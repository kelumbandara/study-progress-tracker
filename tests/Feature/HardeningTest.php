<?php

namespace Tests\Feature;

use App\Exports\ReportExport;
use App\Models\StudyDailyLog;
use App\Models\Subject;
use App\Models\SyllabusTopic;
use App\Models\SyllabusUnit;
use App\Services\ImportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now('Asia/Colombo')->setDate(2026, 11, 10)->setTime(20, 0));
    }

    private function tempFile(string $name, string $content): UploadedFile
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('t') . '_' . $name;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function settingsPayload(array $o = []): array
    {
        return array_replace_recursive(['study_start' => '2026-10-28', 'study_end' => '2027-07-31', 'timezone' => 'Asia/Colombo',
            'paper_year_from' => 2017, 'paper_year_to' => 2026, 'hours' => ['theory' => ''],
            'subjects' => Subject::all()->mapWithKeys(fn ($s) => [$s->id => ['priority' => $s->priority, 'weak_score_pct' => $s->weak_score_pct]])->all()], $o);
    }

    // ---- XSS / injection -------------------------------------------------------------------

    public function test_user_text_is_escaped_everywhere_it_is_shown(): void
    {
        $xss = '<script>alert("x")</script>';
        $this->post('/daily-log', ['log_date' => '2026-11-09', 'notes' => $xss]);
        $sid = Subject::where('name', 'Physics')->value('id'); // ids keep counting across tests on MySQL
        $unit = SyllabusUnit::create(['subject_id' => $sid, 'name' => $xss]);
        SyllabusTopic::create(['unit_id' => $unit->id, 'name' => $xss, 'reference' => $xss]);

        foreach (['/daily-log?date=2026-11-09', "/syllabus?subject={$sid}", '/theory'] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert("x")</script>', $html, "unescaped script on {$url}");
        }
        $this->get('/daily-log?date=2026-11-09')->assertSee('&lt;script&gt;', false);
    }

    public function test_hostile_query_strings_never_cause_server_errors_or_leak_data(): void
    {
        foreach ([
            "/attempts?subject=1' OR '1'='1", '/papers?year=abc&subject=%27%3B%20DROP%20TABLE%20subjects%3B--', '/syllabus?subject=999999',
            '/syllabus?subject=abc', '/daily-log?date=garbage', '/daily-log?date=2026-02-30', '/daily-log?date=' . str_repeat('9', 400),
            '/mistakes?x[]=1', '/api/topics?subject_id=abc', '/api/papers?subject_id=1;--&year=2024',
        ] as $url) {
            $status = $this->get($url)->getStatusCode();
            $this->assertLessThan(500, $status, "{$url} returned {$status}");
        }
        $this->assertSame(3, Subject::count()); // nothing was dropped
        foreach (['/reports?subject=1 OR 1=1', '/reports?type=evil', '/reports?anchor=nonsense', '/reports?from=x&to=y'] as $url) {
            $this->get($url)->assertSessionHasErrors();
        }
    }

    public function test_unknown_ids_return_404_not_500(): void
    {
        foreach (['/papers/99999', '/attempts/question/99999', '/api/papers/99999/questions'] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->put('/syllabus/topics/99999', ['status' => 'mastered'])->assertNotFound();
        $this->put('/questions/99999', ['max_marks' => 5])->assertNotFound();
    }

    public function test_mass_assignment_cannot_change_protected_fields(): void
    {
        $this->post('/daily-log', ['log_date' => '2026-11-09', 'school_h' => 1, 'student_id' => 999, 'id' => 77]);
        $log = StudyDailyLog::first();
        $this->assertSame(1, $log->student_id);
        $this->assertNotSame(77, $log->id);
    }

    // ---- uploads ---------------------------------------------------------------------------

    public function test_upload_type_and_size_are_validated(): void
    {
        foreach (['evil.php', 'run.exe', 'page.html', 'macro.xlsm.js'] as $name) {
            $this->post('/syllabus/import', ['file' => $this->tempFile($name, "subject,unit,topic\nPhysics,U,T\n")])->assertSessionHasErrors('file');
        }
        $big = $this->tempFile('big.csv', "subject,unit,topic\n" . str_repeat("Physics,U,T\n", 600000)); // > 5 MB
        $this->assertGreaterThan(5 * 1024 * 1024, filesize($big->getPathname()));
        $this->post('/syllabus/import', ['file' => $big])->assertSessionHasErrors('file');
        $this->post('/papers/import', ['type' => 'papers', 'file' => $this->tempFile('x.php', 'x')])->assertSessionHasErrors('file');
        $this->post('/papers/import', ['type' => 'bogus', 'file' => $this->tempFile('x.csv', "subject,year,paper\n")])->assertSessionHasErrors('type');
        $this->assertSame(0, SyllabusUnit::count());
    }

    public function test_uploads_are_not_kept_on_disk_after_import(): void
    {
        $before = count(glob(storage_path('app/*')) ?: []) + count(glob(storage_path('app/private/*')) ?: []) + count(glob(public_path('storage/*')) ?: []);
        $this->post('/syllabus/import', ['file' => $this->tempFile('ok.csv', "subject,unit,topic\nPhysics,Mechanics,Kinematics\n")])->assertSessionHasNoErrors();
        $after = count(glob(storage_path('app/*')) ?: []) + count(glob(storage_path('app/private/*')) ?: []) + count(glob(public_path('storage/*')) ?: []);
        $this->assertSame($before, $after);
        $this->assertSame(1, SyllabusTopic::count());
    }

    // ---- import file formats ---------------------------------------------------------------

    public function test_xlsx_import_works(): void
    {
        $rows = [['Subject', 'Unit', 'Topic', 'Topic_Reference'], ['Physics', 'Measurement', 'Units', '1.2'], ['Physics', 'Measurement', 'Dimensions', '1.3'], ['Chemistry', 'X', 'Y', '']];
        $bytes = Excel::raw(new ReportExport($rows), ExcelFormat::XLSX);
        $this->assertSame('PK', substr($bytes, 0, 2));

        $log = app(ImportService::class)->importFile('syllabus', $this->tempFile('syllabus.xlsx', $bytes));
        $this->assertSame(2, $log->rows_ok);
        $this->assertSame(1, $log->rows_failed);
        $this->assertSame('1.2', SyllabusTopic::where('name', 'Units')->value('reference'));
    }

    public function test_xlsx_upload_through_the_form(): void
    {
        $bytes = Excel::raw(new ReportExport([['subject', 'unit', 'topic'], ['IT', 'Programming', 'Sub-programs']]), ExcelFormat::XLSX);
        $this->post('/syllabus/import', ['file' => $this->tempFile('s.xlsx', $bytes)])->assertSessionHasNoErrors();
        $this->assertSame(1, SyllabusTopic::count());
    }

    public function test_csv_with_bom_crlf_extra_spaces_and_odd_header_case(): void
    {
        $csv = "\xEF\xBB\xBF" . "Subject , UNIT,Topic ,estimated_hours\r\n Physics ,Mechanics, Kinematics ,2.5\r\n\r\nPhysics,Mechanics,Newton's laws,\r\n";
        $log = app(ImportService::class)->importFile('syllabus', $this->tempFile('bom.csv', $csv));
        $this->assertSame(2, $log->rows_ok, implode(' | ', $log->errors ?? []));
        $this->assertSame(150, SyllabusTopic::where('name', 'Kinematics')->value('estimated_minutes'));
        $this->assertTrue(SyllabusTopic::where('name', "Newton's laws")->exists());
    }

    public function test_semicolon_separated_csv_from_regional_excel_is_understood(): void
    {
        $csv = "subject;unit;topic\nPhysics;Mechanics;Kinematics\nPhysics;Mechanics;Work, energy and power\n";
        $log = app(ImportService::class)->importFile('syllabus', $this->tempFile('semi.csv', $csv));
        $this->assertSame(2, $log->rows_ok, implode(' | ', $log->errors ?? []));
        $this->assertTrue(SyllabusTopic::where('name', 'Work, energy and power')->exists());
    }

    public function test_empty_and_header_only_files_report_nothing_to_import(): void
    {
        $a = app(ImportService::class)->importFile('syllabus', $this->tempFile('e1.csv', ''));
        $b = app(ImportService::class)->importFile('syllabus', $this->tempFile('e2.csv', "subject,unit,topic\n"));
        $this->assertSame([0, 0], [$a->rows_ok, $a->rows_failed]);
        $this->assertSame([0, 0], [$b->rows_ok, $b->rows_failed]);
    }

    // ---- lost races (found by a parallel probe against the production container) -----------

    public function test_a_paper_registered_by_a_competing_request_is_reported_as_existing_not_as_an_error(): void
    {
        $sid = Subject::where('name', 'Physics')->value('id');
        // Insert the identical row right before the controller's own insert, which is exactly what a parallel request does.
        \App\Models\ExamPaper::creating(function ($paper) {
            \Illuminate\Support\Facades\DB::table('exam_papers')->insert(['subject_id' => $paper->subject_id, 'exam_year' => $paper->exam_year,
                'paper_name' => $paper->paper_name, 'availability' => 'unknown', 'created_at' => now(), 'updated_at' => now()]);
        });

        $r = $this->postJson('/papers', ['subject_id' => $sid, 'exam_year' => 2024, 'paper_name' => 'Paper I'])->assertOk()->assertJson(['existing' => true]);
        $this->assertSame(1, \App\Models\ExamPaper::where('exam_year', 2024)->count());
        $this->assertNotNull($r->json('id'));

        // the HTML form path shows a friendly message rather than a server error
        \App\Models\ExamPaper::flushEventListeners();
        \App\Models\ExamPaper::creating(function ($paper) {
            \Illuminate\Support\Facades\DB::table('exam_papers')->insert(['subject_id' => $paper->subject_id, 'exam_year' => $paper->exam_year,
                'paper_name' => $paper->paper_name, 'availability' => 'unknown', 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->from('/papers')->post('/papers', ['subject_id' => $sid, 'exam_year' => 2023, 'paper_name' => 'Paper I'])->assertSessionHasErrors('paper_name');
        \App\Models\ExamPaper::flushEventListeners();
    }

    public function test_a_question_registered_by_a_competing_request_is_reported_as_existing_not_as_an_error(): void
    {
        $sid = Subject::where('name', 'Physics')->value('id');
        $paper = \App\Models\ExamPaper::create(['subject_id' => $sid, 'exam_year' => 2024, 'paper_name' => 'Paper I']);
        \App\Models\ExamQuestion::creating(function ($q) {
            \Illuminate\Support\Facades\DB::table('exam_questions')->insert(['paper_id' => $q->paper_id, 'question_no' => $q->question_no, 'max_marks' => 10,
                'created_at' => now(), 'updated_at' => now()]);
        });

        $this->postJson("/papers/{$paper->id}/questions", ['question_no' => '3', 'max_marks' => 8])->assertOk()->assertJson(['existing' => true]);
        $this->assertSame(1, \App\Models\ExamQuestion::where('paper_id', $paper->id)->count());
        \App\Models\ExamQuestion::flushEventListeners();
    }

    // ---- time zone edges -------------------------------------------------------------------

    public function test_today_follows_colombo_time_around_midnight(): void
    {
        $this->travelTo(now('Asia/Colombo')->setDate(2026, 11, 10)->setTime(23, 45)); // 18:15 UTC
        $this->get('/daily-log')->assertSee('value="2026-11-10"', false);
        $this->post('/daily-log', ['log_date' => '2026-11-10', 'school_h' => 1])->assertSessionHasNoErrors();
        $this->post('/daily-log', ['log_date' => '2026-11-11', 'school_h' => 1])->assertSessionHasErrors('log_date');

        $this->travelTo(now('Asia/Colombo')->setDate(2026, 11, 11)->setTime(0, 15)); // 18:45 UTC the day before
        $this->get('/daily-log')->assertSee('value="2026-11-11"', false);
        $this->post('/daily-log', ['log_date' => '2026-11-11', 'school_h' => 1])->assertSessionHasNoErrors();
        $this->get('/')->assertDontSee("Today's daily log has not been entered yet", false);
    }

    // ---- planned hours and period rules ----------------------------------------------------

    public function test_planned_hours_decimal_places_and_limits(): void
    {
        $this->from('/settings')->put('/settings', $this->settingsPayload(['hours' => ['theory' => '12.345']]))->assertSessionHasErrors('hours.theory');
        $this->from('/settings')->put('/settings', $this->settingsPayload(['hours' => ['theory' => '999999']]))->assertSessionHasErrors('hours.theory');
        $this->from('/settings')->put('/settings', $this->settingsPayload(['hours' => ['theory' => 'abc']]))->assertSessionHasErrors('hours.theory');
        $this->put('/settings', $this->settingsPayload(['hours' => ['theory' => '0']]))->assertSessionHasNoErrors();
        $this->get('/')->assertSee('Not Configured'); // zero = not configured, never a divide-by-zero
    }

    public function test_lowering_the_plan_below_actual_shows_overrun_not_a_capped_value(): void
    {
        StudyDailyLog::create(['student_id' => 1, 'log_date' => '2026-11-09', 'theory_minutes' => 600]);
        $this->put('/settings', $this->settingsPayload(['hours' => ['theory' => '4']]));
        $this->get('/')->assertSee('Over plan')->assertSee('Over plan by')->assertSee('10.0 h');
    }

    public function test_after_the_end_date_the_dashboard_warns_instead_of_dividing_by_zero(): void
    {
        $this->put('/settings', $this->settingsPayload(['hours' => ['theory' => '100']]));
        $this->travelTo(now('Asia/Colombo')->setDate(2027, 8, 20)->setTime(10, 0));
        $this->get('/')->assertOk()->assertSee('study end date has passed')->assertSee('Period has ended');
        $this->get('/reports?type=monthly&anchor=2027-08-10')->assertOk();
    }

    public function test_reports_and_pages_survive_a_period_with_zero_logs_and_zero_attempts(): void
    {
        foreach (['/', '/reports', '/reports?type=monthly', '/mistakes', '/attempts', '/syllabus', '/daily-log'] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
