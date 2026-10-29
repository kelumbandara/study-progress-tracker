<?php

namespace Tests\Feature;

use App\Models\ExamPaper;
use App\Models\ExamQuestion;
use App\Models\MistakeReview;
use App\Models\PaperAttempt;
use App\Models\QuestionAttempt;
use App\Models\StudyDailyLog;
use App\Models\StudyTarget;
use App\Models\Subject;
use App\Models\SyllabusTopic;
use App\Models\SyllabusUnit;
use App\Models\TheoryStudyEntry;
use App\Models\TopicStatusHistory;
use App\Services\AnalyticsService;
use App\Services\SampleData;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SampleDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now('Asia/Colombo')->setDate(2026, 11, 20)->setTime(20, 0));
    }

    /** One complete set of REAL records that sample loading/removal must never touch. */
    private function realRecords(): array
    {
        $maths = Subject::where('name', 'Combined Mathematics')->first();
        $unit = SyllabusUnit::create(['subject_id' => $maths->id, 'name' => 'Real unit']);
        $topic = SyllabusTopic::create(['unit_id' => $unit->id, 'name' => 'Real topic']);
        $paper = ExamPaper::create(['subject_id' => $maths->id, 'exam_year' => 2022, 'paper_name' => 'Paper I', 'max_marks' => 100]);
        $q = ExamQuestion::create(['paper_id' => $paper->id, 'question_no' => '1', 'max_marks' => 10]);
        $q->topics()->sync([$topic->id]);
        $this->post('/attempts', ['question_id' => $q->id, 'attempt_date' => '2026-11-19', 'marks_earned' => 6, 'result' => 'partial', 'worked_minutes' => 20])->assertSessionHasNoErrors();
        $this->post('/theory', ['study_date' => '2026-11-19', 'topic_id' => $topic->id, 'minutes' => 30, 'marked_complete' => 1]);
        $this->post('/daily-log', ['log_date' => '2026-11-19', 'school_h' => 6, 'theory_h' => 1, 'notes' => 'my real day']);

        return compact('maths', 'unit', 'topic', 'paper', 'q');
    }

    public function test_sample_data_loads_flagged_and_is_enough_to_show_the_app_working(): void
    {
        $this->assertFalse(app(SampleData::class)->exists());
        $this->post('/sample-data')->assertRedirect()->assertSessionHas('status');

        $this->assertTrue(app(SampleData::class)->exists());
        $this->assertSame(3, SyllabusUnit::where('is_sample', true)->count());
        $this->assertSame(9, SyllabusTopic::where('is_sample', true)->count());
        $this->assertSame(3, ExamPaper::where('is_sample', true)->count());
        $this->assertSame(12, ExamQuestion::where('is_sample', true)->count());
        $this->assertSame(14, StudyDailyLog::where('is_sample', true)->count());
        $this->assertSame(3, PaperAttempt::count());
        $this->assertSame(0, ExamPaper::where('is_sample', false)->count());   // nothing sample is unflagged
        $this->assertSame(0, StudyDailyLog::where('is_sample', false)->count());
        $this->assertSame(4, StudyTarget::whereNull('planned_minutes')->count()); // planned hours are never set by sample data

        foreach (Subject::all() as $s) {
            $sum = app(AnalyticsService::class)->subjectSummary($s, 1);
            $this->assertTrue($sum['enough_data'], "{$s->name} needs >= 5 attempts");
        }
        $maths = app(AnalyticsService::class)->subjectSummary(Subject::where('name', 'Combined Mathematics')->first(), 1);
        $it = app(AnalyticsService::class)->subjectSummary(Subject::where('name', 'IT')->first(), 1);
        $this->assertLessThan(50, $maths['weighted_pct']);   // maths is the weak subject, as in the brief
        $this->assertGreaterThan(75, $it['weighted_pct']);   // IT is strong

        $this->get('/')->assertOk()->assertSee('Sample data is loaded')->assertSee('high priority');
        $this->get('/syllabus?subject=' . Subject::where('name', 'Physics')->value('id'))->assertSee('SAMPLE');
        $this->get('/papers')->assertSee('SAMPLE');
        $this->get('/daily-log')->assertSee('SAMPLE');
        $this->get('/mistakes')->assertOk();
        $this->get('/reports?type=monthly')->assertOk();
    }

    public function test_loading_twice_changes_nothing(): void
    {
        $this->post('/sample-data');
        $before = [QuestionAttempt::count(), StudyDailyLog::count(), SyllabusTopic::count()];
        $this->post('/sample-data')->assertSessionHas('status');
        $this->assertSame($before, [QuestionAttempt::count(), StudyDailyLog::count(), SyllabusTopic::count()]);
    }

    public function test_removal_deletes_only_sample_records_and_leaves_no_orphans(): void
    {
        $real = $this->realRecords();
        $counts = ['attempts' => QuestionAttempt::count(), 'theory' => TheoryStudyEntry::count(), 'logs' => StudyDailyLog::count(), 'history' => TopicStatusHistory::count()];

        $this->post('/sample-data');
        $this->assertGreaterThan($counts['attempts'], QuestionAttempt::count());

        $this->delete('/sample-data', ['confirm' => 'remove'])->assertRedirect()->assertSessionHas('status');

        $this->assertFalse(app(SampleData::class)->exists());
        $this->assertSame($counts['attempts'], QuestionAttempt::count());       // real attempt kept, sample ones gone
        $this->assertSame($counts['theory'], TheoryStudyEntry::count());
        $this->assertSame($counts['logs'], StudyDailyLog::count());
        $this->assertSame($counts['history'], TopicStatusHistory::count());
        $this->assertSame(0, PaperAttempt::count());
        $this->assertTrue(SyllabusUnit::whereKey($real['unit']->id)->exists());
        $this->assertTrue(ExamPaper::whereKey($real['paper']->id)->exists());
        $this->assertSame('my real day', StudyDailyLog::first()->notes);

        // nothing left pointing at deleted rows
        $this->assertSame(0, DB::table('question_attempts as a')->leftJoin('exam_questions as q', 'q.id', '=', 'a.question_id')->whereNull('q.id')->count());
        $this->assertSame(0, DB::table('question_topic_links as l')->leftJoin('syllabus_topics as t', 't.id', '=', 'l.topic_id')->whereNull('t.id')->count());
        $this->assertSame(0, DB::table('syllabus_topics')->where('is_sample', true)->count());
    }

    public function test_removal_requires_explicit_confirmation(): void
    {
        $this->post('/sample-data');
        $n = QuestionAttempt::count();
        $this->delete('/sample-data')->assertSessionHasErrors('confirm');
        $this->delete('/sample-data', ['confirm' => 'yes'])->assertSessionHasErrors('confirm');
        $this->assertSame($n, QuestionAttempt::count());
        $this->assertTrue(app(SampleData::class)->exists());
    }

    public function test_real_logs_in_the_sample_date_range_are_never_overwritten(): void
    {
        $this->post('/daily-log', ['log_date' => '2026-11-19', 'school_h' => 6, 'theory_h' => 3, 'notes' => 'real']);
        $this->post('/sample-data');
        $log = StudyDailyLog::whereDate('log_date', '2026-11-19')->first();
        $this->assertFalse($log->is_sample);
        $this->assertSame(180, $log->theory_minutes);
        $this->assertSame(13, StudyDailyLog::where('is_sample', true)->count()); // 14 days minus the real one
    }

    public function test_saving_a_log_over_a_sample_day_makes_it_a_real_record_that_survives_removal(): void
    {
        $this->post('/sample-data');
        $this->post('/daily-log', ['log_date' => '2026-11-19', 'school_h' => 5, 'theory_h' => 2, 'notes' => 'I corrected this day']);
        $this->assertFalse(StudyDailyLog::whereDate('log_date', '2026-11-19')->first()->is_sample);

        $this->delete('/sample-data', ['confirm' => 'remove']);
        $this->assertSame(1, StudyDailyLog::count());
        $this->assertSame('I corrected this day', StudyDailyLog::first()->notes);
    }

    public function test_settings_page_offers_load_then_remove_with_a_specific_confirmation(): void
    {
        $this->get('/settings')->assertOk()->assertSee('Load sample data')->assertDontSee('Remove all sample data');
        $this->post('/sample-data');
        $this->get('/settings')->assertOk()->assertSee('Remove all sample data')->assertSee('3 sample units')
            ->assertSee('Your own records are not touched', false)->assertDontSee('Load sample data');
    }

    public function test_removal_confirmation_calls_window_confirm_explicitly(): void
    {
        // Regression: inside an inline onsubmit, a bare confirm() resolves to the form's own
        // <input name="confirm">, throws, and the form submits with no dialog. window.confirm avoids that.
        $this->post('/sample-data');
        $html = $this->get('/settings')->getContent();
        $this->assertStringContainsString('onsubmit="return window.confirm(', $html);
        $this->assertStringNotContainsString('onsubmit="return confirm(', $html);
    }

    public function test_seeder_class_works_and_is_not_part_of_the_default_seed(): void
    {
        $this->assertFalse(app(SampleData::class)->exists()); // DatabaseSeeder did not add any
        $this->seed(SampleDataSeeder::class);
        $this->assertTrue(app(SampleData::class)->exists());
        $this->assertSame(0, MistakeReview::count());
    }
}
