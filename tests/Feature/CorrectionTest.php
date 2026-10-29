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
use App\Models\TheoryStudyEntry;
use App\Models\TopicStatusHistory;
use App\Services\AnalyticsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Subject $maths;
    private SyllabusTopic $topic;
    private ExamQuestion $question;
    private ExamPaper $paper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now('Asia/Colombo')->setDate(2026, 11, 10)->setTime(20, 0));
        $this->maths = Subject::where('name', 'Combined Mathematics')->first();
        $unit = SyllabusUnit::create(['subject_id' => $this->maths->id, 'name' => 'U']);
        $this->topic = SyllabusTopic::create(['unit_id' => $unit->id, 'name' => 'Limits']);
        $this->paper = ExamPaper::create(['subject_id' => $this->maths->id, 'exam_year' => 2024, 'paper_name' => 'Paper I', 'max_marks' => 100]);
        $this->question = ExamQuestion::create(['paper_id' => $this->paper->id, 'question_no' => '1', 'max_marks' => 10]);
        $this->question->topics()->sync([$this->topic->id]);
    }

    private function attempt(float $marks, bool $independent = true, int $minutes = 30): QuestionAttempt
    {
        $this->post('/attempts', ['question_id' => $this->question->id, 'attempt_date' => '2026-11-09', 'marks_earned' => $marks, 'result' => 'partial',
            'assistance' => $independent ? 'independent' : 'assisted', 'worked_minutes' => $minutes])->assertSessionHasNoErrors();

        return QuestionAttempt::orderByDesc('id')->first();
    }

    private function edit(QuestionAttempt $a, array $o = []): \Illuminate\Testing\TestResponse
    {
        return $this->put("/attempts/{$a->id}", array_merge([
            'attempt_date' => '2026-11-09', 'marks_earned' => $a->marks_earned, 'worked_minutes' => $a->worked_minutes, 'result' => $a->result,
            'assistance' => $a->independent ? 'independent' : 'assisted', 'notes' => $a->notes,
        ], $o));
    }

    public function test_correcting_an_attempt_edits_it_in_place_and_keeps_an_audit_trail(): void
    {
        $a = $this->attempt(4);
        $this->edit($a, ['marks_earned' => 7, 'worked_minutes' => 25, 'notes' => 'typo fixed'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, QuestionAttempt::count());          // edited, not duplicated
        $b = $a->fresh();
        $this->assertSame(7.0, (float) $b->marks_earned);
        $this->assertSame(25, $b->worked_minutes);
        $this->assertSame([1, $this->question->id, 10.0], [$b->attempt_no, $b->question_id, (float) $b->max_marks]); // identity untouched

        $log = AuditLog::where(['entity_type' => 'question_attempts', 'entity_id' => $a->id, 'action' => 'updated'])->first();
        $this->assertNotNull($log);
        $this->assertEquals(4, $log->old_values['marks_earned']);
        $this->assertEquals(7, $log->new_values['marks_earned']);
        $this->assertSame(30, $log->old_values['worked_minutes']);
        $this->assertSame('typo fixed', $log->new_values['notes']);

        $this->get("/attempts/{$a->id}/edit")->assertOk()->assertSee('Change history')->assertSee('marks earned')->assertSee('typo fixed');
    }

    public function test_saving_without_changes_creates_no_audit_entry(): void
    {
        $a = $this->attempt(4);
        $this->edit($a)->assertSessionHasNoErrors();
        $this->assertSame(0, AuditLog::where('entity_type', 'question_attempts')->count());
    }

    public function test_correction_is_validated(): void
    {
        $a = $this->attempt(4);
        $this->edit($a, ['marks_earned' => 11])->assertSessionHasErrors('marks_earned');
        $this->edit($a, ['marks_earned' => -1])->assertSessionHasErrors('marks_earned');
        $this->edit($a, ['attempt_date' => '2026-11-11'])->assertSessionHasErrors('attempt_date'); // future
        $this->edit($a, ['result' => 'bogus'])->assertSessionHasErrors('result');
        $this->assertSame(4.0, (float) $a->fresh()->marks_earned);

        $this->put('/attempts/99999', ['attempt_date' => '2026-11-09', 'marks_earned' => 1, 'result' => 'wrong'])->assertNotFound();
    }

    public function test_the_question_and_attempt_number_cannot_be_changed_by_a_crafted_request(): void
    {
        $other = ExamQuestion::create(['paper_id' => $this->paper->id, 'question_no' => '2', 'max_marks' => 5]);
        $a = $this->attempt(4);
        $this->edit($a, ['question_id' => $other->id, 'attempt_no' => 9, 'max_marks' => 99, 'student_id' => 50])->assertSessionHasNoErrors();
        $b = $a->fresh();
        $this->assertSame([$this->question->id, 1, 10.0, 1], [$b->question_id, $b->attempt_no, (float) $b->max_marks, $b->student_id]);
    }

    public function test_editing_the_evidence_behind_mastery_sends_the_topic_back_to_revision(): void
    {
        $a = $this->attempt(9);                      // independent 90% => evidence
        $this->put("/syllabus/topics/{$this->topic->id}", ['status' => 'mastered'])->assertSessionHasNoErrors();
        $this->assertSame('mastered', $this->topic->fresh()->status);

        $this->edit($a, ['marks_earned' => 5])->assertSessionHasNoErrors(); // typo: it was really 5/10
        $this->assertSame('revision_required', $this->topic->fresh()->status);
        $this->assertTrue(TopicStatusHistory::where(['topic_id' => $this->topic->id, 'to_status' => 'revision_required'])->where('note', 'like', '%edited%')->exists());
    }

    public function test_changing_independent_to_assisted_also_removes_mastery_evidence(): void
    {
        $a = $this->attempt(10);
        $this->put("/syllabus/topics/{$this->topic->id}", ['status' => 'mastered']);
        $this->edit($a, ['assistance' => 'assisted']);
        $this->assertSame('revision_required', $this->topic->fresh()->status);
    }

    public function test_a_correction_that_keeps_the_evidence_leaves_mastery_alone(): void
    {
        $a = $this->attempt(9);
        $this->put("/syllabus/topics/{$this->topic->id}", ['status' => 'mastered']);
        $this->edit($a, ['marks_earned' => 10, 'notes' => 'full marks']);
        $this->assertSame('mastered', $this->topic->fresh()->status);
    }

    public function test_corrected_numbers_flow_into_the_dashboard_figures(): void
    {
        $a = $this->attempt(4);
        $this->assertSame(40.0, app(AnalyticsService::class)->subjectSummary($this->maths, 1)['weighted_pct']);
        $this->edit($a, ['marks_earned' => 8]);
        $this->assertSame(80.0, app(AnalyticsService::class)->subjectSummary($this->maths, 1)['weighted_pct']);
    }

    // ---- theory entries --------------------------------------------------------------------

    public function test_correcting_a_theory_entry_updates_the_topic_total_and_keeps_completion(): void
    {
        $this->post('/theory', ['study_date' => '2026-11-09', 'topic_id' => $this->topic->id, 'minutes' => 45, 'marked_complete' => 1, 'confidence' => 3]);
        $e = TheoryStudyEntry::first();
        $this->post('/theory', ['study_date' => '2026-11-09', 'topic_id' => $this->topic->id, 'minutes' => 15]);
        $this->assertSame(60, $this->topic->fresh()->theory_minutes);

        $this->put("/theory/{$e->id}", ['study_date' => '2026-11-08', 'minutes' => 90, 'confidence' => 4, 'notes' => 'was longer'])->assertSessionHasNoErrors();

        $e = $e->fresh();
        $this->assertSame(90, $e->minutes);
        $this->assertSame('2026-11-08', $e->study_date->toDateString());
        $this->assertTrue($e->marked_complete);                       // completion flag untouched
        $this->assertSame($this->topic->id, $e->topic_id);            // topic untouched
        $this->assertSame(105, $this->topic->fresh()->theory_minutes); // 90 + 15
        $this->assertSame('theory_completed', $this->topic->fresh()->status);

        $log = AuditLog::where(['entity_type' => 'theory_study_entries', 'entity_id' => $e->id])->first();
        $this->assertSame(45, $log->old_values['minutes']);
        $this->assertSame(90, $log->new_values['minutes']);
        $this->get("/theory/{$e->id}/edit")->assertOk()->assertSee('Change history')->assertSee('was longer');
    }

    public function test_theory_correction_validation_and_allocation_warning(): void
    {
        StudyDailyLog::create(['student_id' => 1, 'log_date' => '2026-11-09', 'theory_minutes' => 60]);
        $this->post('/theory', ['study_date' => '2026-11-09', 'topic_id' => $this->topic->id, 'minutes' => 30]);
        $e = TheoryStudyEntry::first();

        $this->put("/theory/{$e->id}", ['study_date' => '2026-11-09', 'minutes' => 0])->assertSessionHasErrors('minutes');
        $this->put("/theory/{$e->id}", ['study_date' => '2026-11-11', 'minutes' => 30])->assertSessionHasErrors('study_date');
        $this->put("/theory/{$e->id}", ['study_date' => '2026-11-09', 'minutes' => 120])->assertSessionHas('warnings'); // more than the day's 60, warned not blocked
        $this->assertSame(120, $e->fresh()->minutes);
        $this->assertSame(60, app(\App\Services\TargetProgressService::class)->actualMinutes(1)['theory']); // totals still from the daily log only
    }

    // ---- full-paper attempts ---------------------------------------------------------------

    public function test_correcting_a_paper_attempt_is_audited(): void
    {
        $this->post('/paper-attempts', ['paper_id' => $this->paper->id, 'attempt_date' => '2026-11-09', 'marks_earned' => 40, 'max_marks' => 100, 'worked_minutes' => 170, 'timed' => 1]);
        $pa = PaperAttempt::first();
        $this->put("/paper-attempts/{$pa->id}", ['attempt_date' => '2026-11-09', 'marks_earned' => 46, 'max_marks' => 100, 'worked_minutes' => 175, 'assistance' => 'independent', 'timed' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame(46.0, (float) $pa->fresh()->marks_earned);
        $this->assertSame(1, PaperAttempt::count());
        $log = AuditLog::where(['entity_type' => 'paper_attempts', 'entity_id' => $pa->id])->first();
        $this->assertEquals(40, $log->old_values['marks_earned']);
        $this->assertEquals(46, $log->new_values['marks_earned']);

        $this->put("/paper-attempts/{$pa->id}", ['attempt_date' => '2026-11-09', 'marks_earned' => 120, 'max_marks' => 100, 'assistance' => 'independent'])->assertSessionHasErrors('max_marks');
        $this->get("/paper-attempts/{$pa->id}/edit")->assertOk()->assertSee('Change history');
    }

    public function test_edit_pages_and_links_render(): void
    {
        $a = $this->attempt(4);
        $this->post('/theory', ['study_date' => '2026-11-09', 'topic_id' => $this->topic->id, 'minutes' => 20]);
        $this->post('/paper-attempts', ['paper_id' => $this->paper->id, 'attempt_date' => '2026-11-09', 'marks_earned' => 40, 'max_marks' => 100]);

        $this->get('/attempts/question/' . $this->question->id)->assertOk()->assertSee("/attempts/{$a->id}/edit", false);
        $this->get('/attempts')->assertOk()->assertSee('Correct');
        $this->get('/theory')->assertOk()->assertSee('/edit', false);
        $this->get('/attempts/99999/edit')->assertNotFound();
        $this->get('/theory/99999/edit')->assertNotFound();
        $this->get('/paper-attempts/99999/edit')->assertNotFound();
    }
}
