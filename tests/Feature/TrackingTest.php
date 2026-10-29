<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ExamPaper;
use App\Models\ExamQuestion;
use App\Models\PaperAttempt;
use App\Models\QuestionAttempt;
use App\Models\StudyDailyLog;
use App\Models\Subject;
use App\Models\SyllabusTopic;
use App\Models\SyllabusUnit;
use App\Models\TopicStatusHistory;
use App\Services\ImportService;
use App\Services\MistakeService;
use App\Services\TargetProgressService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class TrackingTest extends TestCase
{
    use RefreshDatabase;

    private Subject $maths;
    private SyllabusTopic $topic;
    private ExamQuestion $question;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now('Asia/Colombo')->setDate(2026, 11, 10)->setTime(20, 0));

        $this->maths = Subject::where('name', 'Combined Mathematics')->first();
        $unit = SyllabusUnit::create(['subject_id' => $this->maths->id, 'name' => 'Unit A', 'is_sample' => true]);
        $this->topic = SyllabusTopic::create(['unit_id' => $unit->id, 'name' => 'Topic A', 'is_sample' => true]);
        $paper = ExamPaper::create(['subject_id' => $this->maths->id, 'exam_year' => 2024, 'paper_name' => 'Paper I', 'max_marks' => 100, 'is_sample' => true]);
        $this->question = ExamQuestion::create(['paper_id' => $paper->id, 'question_no' => '1', 'max_marks' => 10, 'is_sample' => true]);
        $this->question->topics()->sync([$this->topic->id]);
    }

    private function logPayload(array $o = []): array
    {
        return array_merge(['log_date' => '2026-11-09', 'school_h' => 5, 'school_m' => 30, 'tuition_h' => 2, 'tuition_m' => 0,
            'theory_h' => 1, 'theory_m' => 15, 'papers_h' => 2, 'papers_m' => 0], $o);
    }

    private function attempt(float $marks, string $result, bool $independent = true, ?int $minutes = null, string $date = '2026-11-10'): void
    {
        $this->post('/attempts', [
            'question_id' => $this->question->id, 'attempt_date' => $date, 'marks_earned' => $marks, 'result' => $result,
            'assistance' => $independent ? 'independent' : 'assisted', 'worked_minutes' => $minutes,
        ])->assertSessionHasNoErrors();
    }

    // ---- daily log -------------------------------------------------------------------------

    public function test_daily_log_saves_minutes_and_resaving_does_not_double_count(): void
    {
        $this->post('/daily-log', $this->logPayload())->assertRedirect();
        $this->post('/daily-log', $this->logPayload(['theory_h' => 2, 'theory_m' => 0]))->assertRedirect();

        $this->assertSame(1, StudyDailyLog::count());
        $log = StudyDailyLog::first();
        $this->assertSame([330, 120, 120, 120], [$log->school_minutes, $log->tuition_minutes, $log->theory_minutes, $log->papers_minutes]);
        $this->assertSame(120, app(TargetProgressService::class)->actualMinutes(1)['theory']);
    }

    public function test_edit_keeps_an_audit_trail(): void
    {
        $this->post('/daily-log', $this->logPayload());
        $this->post('/daily-log', $this->logPayload(['theory_h' => 3]));

        $entries = AuditLog::where('entity_type', 'study_daily_logs')->orderBy('id')->get();
        $this->assertSame(['created', 'updated'], $entries->pluck('action')->all());
        $this->assertSame(75, $entries[1]->old_values['theory_minutes']);
        $this->assertSame(195, $entries[1]->new_values['theory_minutes']);
    }

    public function test_zero_day_is_confirmed_and_missing_day_is_not_zero(): void
    {
        $this->post('/daily-log', $this->logPayload(['log_date' => '2026-11-05', 'school_h' => 0, 'school_m' => 0, 'tuition_h' => 0, 'tuition_m' => 0,
            'theory_h' => 0, 'theory_m' => 0, 'papers_h' => 0, 'papers_m' => 0, 'is_rest_day' => 1]))->assertRedirect();

        $this->assertTrue(StudyDailyLog::whereDate('log_date', '2026-11-05')->exists()); // confirmed zero
        $this->assertFalse(StudyDailyLog::whereDate('log_date', '2026-11-06')->exists()); // missing: no row invented

        // Period started 28 Oct; today is 10 Nov => 14 days so far, 1 logged, 13 missing.
        $this->get('/daily-log')->assertOk()->assertSee('13 of 14 day(s) so far have no log');
        $this->get('/daily-log?date=2026-11-06')->assertSee('Missing: nothing has been logged');
    }

    public function test_daily_log_validation(): void
    {
        $this->post('/daily-log', $this->logPayload(['log_date' => '2026-11-11']))->assertSessionHasErrors('log_date'); // future
        $this->post('/daily-log', $this->logPayload(['school_m' => 75]))->assertSessionHasErrors('school_m');
        $this->post('/daily-log', $this->logPayload(['school_h' => 20, 'tuition_h' => 5]))->assertSessionHasErrors('total');
        $this->assertSame(0, StudyDailyLog::count());
    }

    public function test_dashboard_prompts_when_todays_log_is_missing(): void
    {
        $this->get('/')->assertSee("Today's daily log has not been entered yet");
        $this->post('/daily-log', $this->logPayload(['log_date' => '2026-11-10']));
        $this->get('/')->assertDontSee("Today's daily log has not been entered yet");
    }

    // ---- no double counting ----------------------------------------------------------------

    public function test_question_and_topic_minutes_never_add_to_daily_totals(): void
    {
        $this->post('/daily-log', $this->logPayload(['log_date' => '2026-11-10', 'papers_h' => 2, 'papers_m' => 0, 'theory_h' => 1, 'theory_m' => 0]));
        $this->attempt(5, 'partial', true, 35);
        $this->post('/theory', ['study_date' => '2026-11-10', 'topic_id' => $this->topic->id, 'minutes' => 40])->assertSessionHasNoErrors();

        $actual = app(TargetProgressService::class)->actualMinutes(1);
        $this->assertSame(120, $actual['papers']);
        $this->assertSame(60, $actual['theory']);
    }

    public function test_warning_when_allocation_exceeds_daily_total_but_entry_is_still_saved(): void
    {
        $this->post('/daily-log', $this->logPayload(['log_date' => '2026-11-10', 'theory_h' => 0, 'theory_m' => 30]));
        $this->post('/theory', ['study_date' => '2026-11-10', 'topic_id' => $this->topic->id, 'minutes' => 45])
            ->assertSessionHas('warnings');
        $this->assertSame(1, \App\Models\TheoryStudyEntry::count());
    }

    public function test_warning_when_allocating_to_a_day_with_no_log(): void
    {
        $this->post('/theory', ['study_date' => '2026-11-09', 'topic_id' => $this->topic->id, 'minutes' => 20])->assertSessionHas('warnings');
    }

    // ---- attempts --------------------------------------------------------------------------

    public function test_repeat_attempts_are_preserved_separately(): void
    {
        $this->attempt(4, 'partial', true, 35, '2026-11-08');
        $this->attempt(8, 'partial', true, 25, '2026-11-09');
        $this->attempt(10, 'correct', true, 20, '2026-11-10');

        $a = QuestionAttempt::where('question_id', $this->question->id)->orderBy('attempt_no')->get();
        $this->assertSame([1, 2, 3], $a->pluck('attempt_no')->all());
        $this->assertSame([4.0, 8.0, 10.0], $a->map(fn ($x) => (float) $x->marks_earned)->all());
        $this->assertSame([35, 25, 20], $a->pluck('worked_minutes')->all());

        $this->get('/attempts/question/' . $this->question->id)->assertOk()
            ->assertSee('+60 percentage points')->assertSee('15 min faster')->assertSee('best score 100%');
    }

    public function test_marks_cannot_exceed_question_maximum(): void
    {
        $this->post('/attempts', ['question_id' => $this->question->id, 'attempt_date' => '2026-11-10', 'marks_earned' => 11, 'result' => 'correct'])
            ->assertSessionHasErrors('marks_earned');
        $this->assertSame(0, QuestionAttempt::count());
    }

    public function test_max_marks_snapshot_survives_later_question_edit(): void
    {
        $this->attempt(5, 'partial');
        $this->put('/questions/' . $this->question->id, ['max_marks' => 20])->assertSessionHasNoErrors();
        $this->assertSame(10.0, (float) QuestionAttempt::first()->max_marks);
    }

    public function test_a_question_links_to_multiple_topics(): void
    {
        $t2 = SyllabusTopic::create(['unit_id' => $this->topic->unit_id, 'name' => 'Topic B']);
        $this->put('/questions/' . $this->question->id, ['max_marks' => 10, 'topic_ids' => [$this->topic->id, $t2->id]]);
        $this->assertSame(2, $this->question->fresh()->topics()->count());
    }

    public function test_paper_total_comes_from_questions_when_linked_not_added_to_manual_total(): void
    {
        $pa = PaperAttempt::create(['student_id' => 1, 'paper_id' => $this->question->paper_id, 'attempt_date' => '2026-11-10', 'marks_earned' => 90, 'max_marks' => 100]);
        $this->post('/attempts', ['question_id' => $this->question->id, 'paper_attempt_id' => $pa->id, 'attempt_date' => '2026-11-10',
            'marks_earned' => 7, 'result' => 'partial'])->assertSessionHasNoErrors();

        $t = $pa->fresh()->totals();
        $this->assertSame(7.0, $t['earned']); // 7, not 90 and not 97
        $this->assertSame('questions', $t['source']);

        $manual = PaperAttempt::create(['student_id' => 1, 'paper_id' => $this->question->paper_id, 'attempt_date' => '2026-11-10', 'marks_earned' => 55, 'max_marks' => 100]);
        $this->assertSame(['earned' => 55.0, 'max' => 100.0, 'source' => 'manual'], $manual->totals());
    }

    public function test_complete_paper_attempt_via_form_keeps_each_attempt(): void
    {
        $data = ['paper_id' => $this->question->paper_id, 'attempt_date' => '2026-11-10', 'marks_earned' => 60, 'max_marks' => 100, 'worked_minutes' => 170];
        $this->post('/paper-attempts', $data)->assertSessionHasNoErrors();
        $this->post('/paper-attempts', $data)->assertSessionHasNoErrors();
        $this->assertSame([1, 2], PaperAttempt::orderBy('id')->pluck('attempt_no')->all());
        $this->post('/paper-attempts', array_merge($data, ['marks_earned' => 120]))->assertSessionHasErrors('max_marks');
    }

    // ---- topic status and mastery ----------------------------------------------------------

    public function test_theory_complete_does_not_mark_mastered_and_keeps_history(): void
    {
        $this->post('/theory', ['study_date' => '2026-11-09', 'topic_id' => $this->topic->id, 'minutes' => 60, 'marked_complete' => 1, 'confidence' => 3]);
        $t = $this->topic->fresh();
        $this->assertSame('theory_completed', $t->status);
        $this->assertSame('2026-11-09', $t->theory_completed_on->toDateString());
        $this->assertSame(60, $t->theory_minutes);

        // a later revision completion adds another history row; nothing is deleted
        $this->post('/theory', ['study_date' => '2026-11-10', 'topic_id' => $this->topic->id, 'minutes' => 30, 'marked_complete' => 1]);
        $this->assertSame(2, TopicStatusHistory::where('topic_id', $t->id)->where('note', 'like', 'Theory marked complete%')->count());
        $this->assertSame(90, $t->fresh()->theory_minutes);
    }

    public function test_mastered_is_refused_without_evidence_and_assisted_attempts_do_not_count(): void
    {
        $this->put('/syllabus/topics/' . $this->topic->id, ['status' => 'mastered'])->assertSessionHasErrors('status');
        $this->assertNotSame('mastered', $this->topic->fresh()->status);

        $this->attempt(10, 'correct', false); // full marks but assisted
        $this->put('/syllabus/topics/' . $this->topic->id, ['status' => 'mastered'])->assertSessionHasErrors('status');

        $this->attempt(7, 'partial', true); // independent but 70% < 80%
        $this->put('/syllabus/topics/' . $this->topic->id, ['status' => 'mastered'])->assertSessionHasErrors('status');

        $this->attempt(9, 'correct', true); // independent 90%
        $this->put('/syllabus/topics/' . $this->topic->id, ['status' => 'mastered'])->assertSessionHasNoErrors();
        $this->assertSame('mastered', $this->topic->fresh()->status);
    }

    public function test_practice_moves_topic_forward_and_low_score_flags_revision(): void
    {
        $this->attempt(8, 'partial');
        $this->assertSame('questions_practised', $this->topic->fresh()->status);
        $this->attempt(2, 'wrong'); // 20% < default weak 50%
        $this->assertSame('revision_required', $this->topic->fresh()->status);
    }

    // ---- mistake review --------------------------------------------------------------------

    public function test_wrong_question_enters_queue_and_leaves_only_after_independent_success(): void
    {
        $this->attempt(2, 'wrong');
        $q = app(MistakeService::class)->queue(1);
        $this->assertCount(1, $q);
        $this->assertStringContainsString('Wrong', implode(',', $q[0]['reasons']));

        // reading the solution is not enough
        $this->post('/mistakes', ['question_id' => $this->question->id, 'reviewed_on' => '2026-11-10', 'solution_understood' => 1, 'theory_reviewed' => 1])->assertSessionHasNoErrors();
        $this->assertCount(1, app(MistakeService::class)->queue(1));
        $this->post('/mistakes', ['question_id' => $this->question->id, 'reviewed_on' => '2026-11-10', 'mastered_independently' => 1])->assertSessionHasErrors('mastered_independently');

        $this->attempt(9, 'correct', true);
        $this->assertCount(0, app(MistakeService::class)->queue(1));
        $this->get('/mistakes')->assertOk()->assertSee('Nothing to review');
    }

    public function test_repeated_low_scores_are_flagged_as_repeated_difficulty(): void
    {
        $this->attempt(2, 'wrong', true, null, '2026-11-08');
        $this->attempt(3, 'wrong', true, null, '2026-11-09');
        $q = app(MistakeService::class)->queue(1);
        $this->assertTrue($q[0]['repeated']);
        $this->get('/mistakes')->assertSee('Repeated difficulty');
    }

    public function test_slow_question_uses_configurable_per_subject_threshold(): void
    {
        $this->attempt(9, 'correct', false, 50); // assisted so still reviewable; 5 min/mark
        $this->assertStringNotContainsString('Slow', implode(',', app(MistakeService::class)->queue(1)[0]['reasons']));
        $this->maths->update(['slow_minutes_per_mark' => 3]);
        $this->assertStringContainsString('Slow', implode(',', app(MistakeService::class)->queue(1)[0]['reasons']));
    }

    // ---- registering papers/questions in the workflow --------------------------------------

    public function test_missing_paper_and_question_can_be_added_inline_without_duplicates(): void
    {
        $r = $this->postJson('/papers', ['subject_id' => $this->maths->id, 'exam_year' => 2023, 'paper_name' => 'Paper II'])->assertCreated();
        $paperId = $r->json('id');
        $this->postJson('/papers', ['subject_id' => $this->maths->id, 'exam_year' => 2023, 'paper_name' => 'Paper II'])->assertOk()->assertJson(['existing' => true, 'id' => $paperId]);
        $this->assertSame(1, ExamPaper::where('exam_year', 2023)->count());

        $this->postJson("/papers/{$paperId}/questions", ['question_no' => '3', 'max_marks' => 12.5, 'topic_ids' => [$this->topic->id]])->assertCreated();
        $this->postJson("/papers/{$paperId}/questions", ['question_no' => '3', 'max_marks' => 12.5])->assertOk()->assertJson(['existing' => true]);
        $this->assertSame(1, ExamQuestion::where('paper_id', $paperId)->count());

        $this->getJson("/api/papers/{$paperId}/questions")->assertJsonFragment(['label' => 'Q3 (12.5 marks)']);
        $this->getJson('/api/papers?subject_id=' . $this->maths->id . '&year=2023')->assertJsonCount(1);
    }

    // ---- imports ---------------------------------------------------------------------------

    private function csv(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.csv';
        file_put_contents($path, $content);

        return new UploadedFile($path, 'test.csv', 'text/csv', null, true);
    }

    public function test_syllabus_import_reports_bad_rows_and_skips_duplicates(): void
    {
        $file = $this->csv("subject,unit,topic,topic_reference,subtopic\nPhysics,Mechanics,Kinematics,2.1,\nPhysics,Mechanics,Kinematics,2.1,\nPhysics,Mechanics,Kinematics,2.1,Velocity\nChemistry,Atoms,Atom,,\nPhysics,,Orphan,,\n");
        $log = app(ImportService::class)->importFile('syllabus', $file);

        $this->assertSame(2, $log->rows_ok);          // Kinematics + the Velocity subtopic
        $this->assertSame(2, $log->rows_failed);       // unknown subject, missing unit
        $this->assertStringContainsString("unknown subject 'Chemistry'", implode(' ', $log->errors));
        $this->assertStringContainsString('1 row(s) already existed', implode(' ', $log->errors));
        $kin = SyllabusTopic::where('name', 'Kinematics')->first();
        $this->assertSame(1, $kin->children()->count());
    }

    public function test_paper_and_question_imports_link_topics(): void
    {
        $svc = app(ImportService::class);
        $p = $svc->importFile('papers', $this->csv("subject,year,paper,max_marks,duration_minutes,availability\nCombined Mathematics,2022,Paper I,100,180,available\nCombined Mathematics,abc,Paper I,100,180,available\nCombined Mathematics,2022,Paper I,100,180,available\n"));
        $this->assertSame(1, $p->rows_ok);
        $this->assertSame(1, $p->rows_failed);

        $q = $svc->importFile('questions', $this->csv("subject,year,paper,question_no,max_marks,topics\nCombined Mathematics,2022,Paper I,1,10,Topic A\nCombined Mathematics,2022,Paper I,2,10,No such topic\nCombined Mathematics,2021,Paper I,1,10,\n"));
        $this->assertSame(1, $q->rows_ok);
        $this->assertSame(2, $q->rows_failed);
        $this->assertSame(1, ExamQuestion::whereHas('paper', fn ($x) => $x->where('exam_year', 2022))->first()->topics()->count());
    }

    public function test_nie_syllabus_file_loads_for_all_three_subjects_and_is_idempotent(): void
    {
        $this->post('/syllabus/load-nie')->assertSessionHasNoErrors();

        $counts = SyllabusTopic::query()->join('syllabus_units', 'syllabus_units.id', '=', 'syllabus_topics.unit_id')
            ->join('subjects', 'subjects.id', '=', 'syllabus_units.subject_id')->selectRaw('subjects.name n, count(*) c')->groupBy('subjects.name')->pluck('c', 'n');
        $this->assertSame(61, $counts['Physics']); // 5+8+10+9+2+4+3+6+4+3+7 competency levels
        $this->assertSame(11, SyllabusUnit::where('subject_id', Subject::where('name', 'Physics')->value('id'))->count());
        $this->assertSame(14, SyllabusUnit::where('subject_id', Subject::where('name', 'IT')->value('id'))->count());
        $this->assertSame(2, SyllabusUnit::where('subject_id', $this->maths->id)->whereIn('name', ['Combined Mathematics I', 'Combined Mathematics II'])->count());
        $this->assertSame(0, SyllabusTopic::where('is_sample', true)->whereHas('unit', fn ($q) => $q->where('name', 'Mechanics'))->count()); // official data is not flagged as sample

        $before = SyllabusTopic::count();
        $this->post('/syllabus/load-nie');
        $this->assertSame($before, SyllabusTopic::count());
        $this->assertSame(\App\Models\ImportLog::where('rows_failed', '>', 0)->count(), 0);
    }

    // ---- pages render ----------------------------------------------------------------------

    public function test_all_tracking_pages_render(): void
    {
        $this->attempt(5, 'partial');
        foreach (['/daily-log', '/syllabus', '/papers', '/papers/' . $this->question->paper_id, '/attempts', '/attempts/create', '/attempts/create?question=' . $this->question->id,
            '/attempts/question/' . $this->question->id, '/paper-attempts/create', '/theory', '/mistakes', '/api/topics?subject_id=' . $this->maths->id] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/syllabus?subject=' . $this->maths->id)->assertSee('Topic A')->assertSee('SAMPLE');
    }
}
