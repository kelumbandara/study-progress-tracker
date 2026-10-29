<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\ExamPaper;
use App\Models\ExamQuestion;
use App\Models\StudyDailyLog;
use App\Models\Subject;
use App\Models\SyllabusTopic;
use App\Models\SyllabusUnit;
use App\Models\TheoryStudyEntry;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiDataTest extends TestCase
{
    use RefreshDatabase;

    protected bool $signedIn = false;

    private User $user;
    private Subject $maths;
    private SyllabusTopic $topic;
    private ExamPaper $paper;
    private ExamQuestion $q;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now('Asia/Colombo')->setDate(2026, 11, 10)->setTime(20, 0));

        $this->user = User::create(['name' => 'Student', 'email' => 's@example.com', 'password' => bcrypt('x')]);
        Sanctum::actingAs($this->user);

        $this->maths = Subject::where('name', 'Combined Mathematics')->first();
        $unit = SyllabusUnit::create(['subject_id' => $this->maths->id, 'name' => 'Unit A']);
        $this->topic = SyllabusTopic::create(['unit_id' => $unit->id, 'name' => 'Limits', 'reference' => '13']);
        $this->paper = ExamPaper::create(['subject_id' => $this->maths->id, 'exam_year' => 2024, 'paper_name' => 'Paper I', 'max_marks' => 100, 'duration_minutes' => 180]);
        $this->q = ExamQuestion::create(['paper_id' => $this->paper->id, 'question_no' => '1', 'max_marks' => 10]);
        $this->q->topics()->sync([$this->topic->id]);
    }

    /** Every date-like field must be a plain Y-m-d string: a UTC timestamp would show the wrong day in Colombo. */
    private function assertDatesArePlain(array $data, string $path = ''): void
    {
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $this->assertDatesArePlain($v, "{$path}.{$k}");
            } elseif (is_string($k) && preg_match('/(^|_)(date|on|start|end|today)$/', $k) && $v !== null) {
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $v, "{$path}.{$k} is not a plain date: {$v}");
            }
        }
    }

    private function attempt(float $marks, array $o = [])
    {
        return $this->postJson('/api/v1/attempts', array_merge([
            'question_id' => $this->q->id, 'attempt_date' => '2026-11-09', 'marks_earned' => $marks, 'result' => $marks >= 10 ? 'correct' : 'partial',
            'independent' => true, 'worked_minutes' => 30,
        ], $o));
    }

    private function putDay(string $date, array $minutes, array $o = [])
    {
        return $this->putJson("/api/v1/daily-logs/{$date}", array_merge(['minutes' => $minutes], $o));
    }

    private const DAY = ['school' => 330, 'tuition' => 120, 'theory' => 75, 'papers' => 120];

    // ---- bootstrap -------------------------------------------------------------------------

    public function test_bootstrap_gives_server_today_period_subjects_and_options(): void
    {
        $r = $this->getJson('/api/v1/bootstrap')->assertOk();
        $r->assertJsonPath('today', '2026-11-10')->assertJsonPath('timezone', 'Asia/Colombo')
            ->assertJsonPath('study_period.start', '2026-10-28')->assertJsonPath('study_period.end', '2027-07-31')->assertJsonPath('study_period.exam_date', null)
            ->assertJsonPath('rules.mastery_pct', 80)->assertJsonPath('rules.max_minutes_per_day', 1440)
            ->assertJsonCount(3, 'subjects')->assertJsonCount(4, 'options.categories')->assertJsonCount(6, 'options.topic_statuses')
            ->assertJsonCount(4, 'options.attempt_results')->assertJsonCount(7, 'options.mistake_categories');
        $this->assertSame('high', collect($r->json('subjects'))->firstWhere('name', 'Combined Mathematics')['priority']);
        $this->assertSame(['school', 'tuition', 'theory', 'papers'], array_column($r->json('options.categories'), 'key'));
        $this->assertDatesArePlain($r->json());
        $this->assertStringNotContainsString('password', $r->getContent());
    }

    public function test_today_follows_colombo_time_not_utc_around_midnight(): void
    {
        $this->travelTo(now('Asia/Colombo')->setDate(2026, 11, 10)->setTime(23, 50)); // 18:20 UTC, still the 10th in Colombo
        $this->getJson('/api/v1/bootstrap')->assertJsonPath('today', '2026-11-10');
        $this->travelTo(now('Asia/Colombo')->setDate(2026, 11, 11)->setTime(0, 10));  // 18:40 UTC on the 10th, already the 11th in Colombo
        $this->getJson('/api/v1/bootstrap')->assertJsonPath('today', '2026-11-11');
    }

    // ---- daily log -------------------------------------------------------------------------

    public function test_a_missing_day_is_not_a_zero_day(): void
    {
        $r = $this->getJson('/api/v1/daily-logs/2026-11-09')->assertOk();
        $r->assertJsonPath('log.logged', false)->assertJsonPath('log.minutes', null)->assertJsonPath('log.total_minutes', null);
        $r->assertJsonPath('days_so_far', 14)->assertJsonPath('missing_days', 14)->assertJsonPath('today', '2026-11-10');

        // a confirmed zero day is different
        $this->putDay('2026-11-09', ['school' => 0, 'tuition' => 0, 'theory' => 0, 'papers' => 0], ['is_rest_day' => true])->assertOk()
            ->assertJsonPath('log.logged', true)->assertJsonPath('log.total_minutes', 0)->assertJsonPath('log.is_rest_day', true)->assertJsonPath('missing_days', 13);
    }

    public function test_saving_a_day_creates_it_and_saving_again_edits_it_with_the_account_in_the_audit_trail(): void
    {
        $this->putDay('2026-11-09', self::DAY, ['rating' => 4, 'notes' => 'good day'])->assertOk()
            ->assertJsonPath('log.minutes.theory', 75)->assertJsonPath('log.total_minutes', 645)->assertJsonPath('log.rating', 4)
            ->assertJsonPath('cumulative_minutes.school', 330);
        $this->putDay('2026-11-09', ['school' => 330, 'tuition' => 120, 'theory' => 135, 'papers' => 120])->assertOk()->assertJsonPath('log.minutes.theory', 135);

        $this->assertSame(1, StudyDailyLog::count()); // edited, not duplicated
        $audit = AuditLog::where(['entity_type' => 'study_daily_logs', 'action' => 'updated'])->first();
        $this->assertSame(75, $audit->old_values['theory_minutes']);
        $this->assertSame(135, $audit->new_values['theory_minutes']);
        $this->assertSame($this->user->id, $audit->user_id); // the token's account is recorded
    }

    public function test_daily_log_validation(): void
    {
        $this->putDay('2026-11-11', self::DAY)->assertStatus(422)->assertJsonValidationErrors('date');           // future
        $this->putDay('2026-02-30', self::DAY)->assertStatus(422)->assertJsonValidationErrors('date');           // impossible date
        $this->putJson('/api/v1/daily-logs/not-a-date', ['minutes' => self::DAY])->assertNotFound();
        $this->putJson('/api/v1/daily-logs/2026-11-09', [])->assertStatus(422)->assertJsonValidationErrors('minutes');
        $this->putDay('2026-11-09', ['school' => 330, 'tuition' => 120, 'theory' => 75])->assertStatus(422)->assertJsonValidationErrors('minutes.papers'); // all four required
        $this->putDay('2026-11-09', ['school' => -1, 'tuition' => 0, 'theory' => 0, 'papers' => 0])->assertStatus(422)->assertJsonValidationErrors('minutes.school');
        $this->putDay('2026-11-09', ['school' => 90.5, 'tuition' => 0, 'theory' => 0, 'papers' => 0])->assertStatus(422);
        $this->putDay('2026-11-09', ['school' => 1441, 'tuition' => 0, 'theory' => 0, 'papers' => 0])->assertStatus(422);
        $this->putDay('2026-11-09', ['school' => 1000, 'tuition' => 500, 'theory' => 0, 'papers' => 0])->assertStatus(422)->assertJsonValidationErrors('minutes'); // 1500 min > 24 h
        $this->putDay('2026-11-09', self::DAY, ['rating' => 6])->assertStatus(422)->assertJsonValidationErrors('rating');
        $this->assertSame(0, StudyDailyLog::count());
    }

    public function test_daily_log_list_is_paginated_newest_first(): void
    {
        foreach (['2026-11-05', '2026-11-07', '2026-11-06'] as $d) {
            $this->putDay($d, self::DAY)->assertOk();
        }
        $r = $this->getJson('/api/v1/daily-logs?per_page=2')->assertOk();
        $this->assertSame(['2026-11-07', '2026-11-06'], array_column($r->json('data'), 'date'));
        $r->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/v1/daily-logs?per_page=500')->assertStatus(422);
    }

    public function test_detail_minutes_over_the_day_total_produce_a_warning_not_an_error(): void
    {
        $this->putDay('2026-11-09', ['school' => 0, 'tuition' => 0, 'theory' => 30, 'papers' => 0])->assertOk();
        $this->postJson('/api/v1/theory', ['study_date' => '2026-11-09', 'topic_id' => $this->topic->id, 'minutes' => 45])->assertCreated()->assertJsonCount(1, 'warnings');
        $this->getJson('/api/v1/daily-logs/2026-11-09')->assertJsonCount(1, 'warnings');
        $this->assertSame(30, $this->getJson('/api/v1/daily-logs/2026-11-09')->json('cumulative_minutes.theory')); // totals still come from the log only
    }

    // ---- dashboard -------------------------------------------------------------------------

    public function test_dashboard_matches_the_numbers_the_website_uses(): void
    {
        $this->putJson('/api/v1/daily-logs/2026-11-09', ['minutes' => self::DAY])->assertOk();
        $this->attempt(4)->assertCreated();
        $this->attempt(8)->assertCreated();

        $r = $this->getJson('/api/v1/dashboard')->assertOk();
        $this->assertDatesArePlain($r->json());
        $r->assertJsonPath('today', '2026-11-10')->assertJsonPath('today_logged', false)
            ->assertJsonPath('period.total_days', 277)->assertJsonPath('period.start', '2026-10-28')
            ->assertJsonCount(4, 'categories')->assertJsonCount(3, 'subjects');

        $theory = collect($r->json('categories'))->firstWhere('key', 'theory');
        $this->assertSame(75, $theory['actual']);
        $this->assertFalse($theory['configured']);               // planned hours are not set: honest "Not Configured"
        $this->assertSame('not_configured', $theory['status']);

        $maths = collect($r->json('subjects'))->first(fn ($s) => $s['subject']['name'] === 'Combined Mathematics');
        $this->assertSame(2, $maths['attempts']);
        $this->assertEquals(12, $maths['marks_earned']);          // 4 + 8
        $this->assertEquals(20, $maths['marks_available']);
        $this->assertEquals(60, $maths['weighted_pct']);
        $this->assertEquals(['n' => 1, 'pct' => 40], $maths['first']);
        $this->assertEquals(['n' => 1, 'pct' => 80], $maths['retry']);
        $this->assertFalse($maths['enough_data']);
        // With only 2 attempts the advice must say so instead of drawing conclusions.
        $texts = collect($r->json('recommendations'))->pluck('text');
        $this->assertTrue($texts->contains(fn ($t) => str_starts_with($t, 'Combined Mathematics: Not enough data yet')), 'expected a "Not enough data yet" note for Combined Mathematics');
        $this->assertFalse($texts->contains(fn ($t) => str_contains($t, 'Prioritise')), 'advice must not draw conclusions from 2 attempts');
    }

    public function test_dashboard_flags_that_today_is_logged(): void
    {
        $this->putDay('2026-11-10', self::DAY)->assertOk();
        $this->getJson('/api/v1/dashboard')->assertJsonPath('today_logged', true);
    }

    // ---- syllabus --------------------------------------------------------------------------

    public function test_syllabus_tree_with_subtopics_and_progress_flags(): void
    {
        SyllabusTopic::create(['unit_id' => $this->topic->unit_id, 'parent_id' => $this->topic->id, 'name' => 'One-sided limits']);
        $this->attempt(5)->assertCreated();

        $r = $this->getJson('/api/v1/syllabus?subject_id=' . $this->maths->id)->assertOk();
        $this->assertDatesArePlain($r->json());
        $unit = $r->json('units.0');
        $this->assertSame('Unit A', $unit['name']);
        $this->assertSame('Limits', $unit['topics'][0]['name']);
        $this->assertSame('One-sided limits', $unit['topics'][0]['subtopics'][0]['name']);
        $this->assertTrue($unit['topics'][0]['practised']);
        $this->assertSame('questions_practised', $unit['topics'][0]['status']);
        $this->assertSame('Questions Practised', $unit['topics'][0]['status_label']);
        // A topic that has subtopics is counted through its subtopics, and the only leaf (One-sided limits) has no attempts yet.
        $this->assertSame(1, $r->json('stats.total'));
        $this->assertSame(0, $r->json('stats.practised'));
        $this->getJson('/api/v1/syllabus')->assertStatus(422);
        $this->getJson('/api/v1/syllabus?subject_id=9999')->assertStatus(422);
    }

    public function test_mastered_needs_independent_evidence_through_the_api_too(): void
    {
        $url = '/api/v1/syllabus/topics/' . $this->topic->id;
        $this->putJson($url, ['status' => 'mastered'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->attempt(10, ['independent' => false])->assertCreated();                    // full marks but assisted
        $this->putJson($url, ['status' => 'mastered'])->assertStatus(422);
        $this->attempt(7)->assertCreated();                                               // independent but 70%
        $this->putJson($url, ['status' => 'mastered'])->assertStatus(422);
        $this->attempt(9)->assertCreated();                                               // independent 90%
        $this->putJson($url, ['status' => 'mastered', 'confidence' => 5])->assertOk()->assertJsonPath('topic.status', 'mastered')->assertJsonPath('topic.confidence', 5);

        $this->putJson($url, ['status' => 'nonsense'])->assertStatus(422);
        $this->putJson($url, ['status' => 'in_progress', 'confidence' => 9])->assertStatus(422);
        $this->putJson('/api/v1/syllabus/topics/99999', ['status' => 'in_progress'])->assertNotFound();
    }

    // ---- papers and questions --------------------------------------------------------------

    public function test_papers_and_questions_register_once_and_retries_are_harmless(): void
    {
        $body = ['subject_id' => $this->maths->id, 'exam_year' => 2023, 'paper_name' => 'Paper II', 'max_marks' => 100];
        $a = $this->postJson('/api/v1/papers', $body)->assertCreated()->assertJsonPath('created', true);
        $b = $this->postJson('/api/v1/papers', $body)->assertOk()->assertJsonPath('created', false);   // e.g. the phone retried after a dropped connection
        $this->assertSame($a->json('paper.id'), $b->json('paper.id'));
        $this->assertSame(1, ExamPaper::where('exam_year', 2023)->count());

        $pid = $a->json('paper.id');
        $qa = $this->postJson("/api/v1/papers/{$pid}/questions", ['question_no' => '3', 'max_marks' => 12.5, 'topic_ids' => [$this->topic->id]])->assertCreated();
        $qb = $this->postJson("/api/v1/papers/{$pid}/questions", ['question_no' => '3', 'max_marks' => 12.5])->assertOk()->assertJsonPath('created', false);
        $this->assertSame($qa->json('question.id'), $qb->json('question.id'));
        $this->assertSame([$this->topic->id], $qa->json('question.topic_ids'));
        $this->assertEquals(12.5, $qa->json('question.max_marks'));
        $this->assertSame(1, ExamQuestion::where('paper_id', $pid)->count());

        $list = $this->getJson("/api/v1/papers/{$pid}/questions")->assertOk();
        $this->assertSame('3', $list->json('data.0.number'));
        $this->getJson('/api/v1/papers?subject_id=' . $this->maths->id . '&year=2024')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.label', '2024 Paper I');
    }

    public function test_paper_and_question_validation(): void
    {
        $this->postJson('/api/v1/papers', [])->assertStatus(422)->assertJsonValidationErrors(['subject_id', 'exam_year', 'paper_name']);
        $this->postJson('/api/v1/papers', ['subject_id' => $this->maths->id, 'exam_year' => 1800, 'paper_name' => 'P'])->assertStatus(422)->assertJsonValidationErrors('exam_year');
        $this->postJson('/api/v1/papers', ['subject_id' => $this->maths->id, 'exam_year' => 2020, 'paper_name' => 'P', 'paper_url' => 'javascript:alert(1)'])->assertStatus(422)->assertJsonValidationErrors('paper_url');
        $this->postJson("/api/v1/papers/{$this->paper->id}/questions", ['question_no' => '9', 'max_marks' => 0])->assertStatus(422)->assertJsonValidationErrors('max_marks');
        $this->postJson("/api/v1/papers/{$this->paper->id}/questions", ['question_no' => '9', 'max_marks' => 5, 'topic_ids' => [99999]])->assertStatus(422);
        $this->postJson('/api/v1/papers/99999/questions', ['question_no' => '9', 'max_marks' => 5])->assertNotFound();
    }

    public function test_topic_picker_and_paper_attempt_lookup(): void
    {
        $t = $this->getJson('/api/v1/topics?subject_id=' . $this->maths->id)->assertOk();
        $this->assertSame('Limits', $t->json('data.0.name'));
        $this->assertSame('Unit A', $t->json('data.0.unit'));
        $this->getJson('/api/v1/topics')->assertStatus(422);

        $this->postJson('/api/v1/paper-attempts', ['paper_id' => $this->paper->id, 'attempt_date' => '2026-11-09', 'marks_earned' => 40, 'max_marks' => 100, 'independent' => true])->assertCreated();
        $this->getJson("/api/v1/papers/{$this->paper->id}/paper-attempts")->assertOk()->assertJsonPath('data.0.attempt_no', 1)->assertJsonPath('data.0.date', '2026-11-09');
    }

    // ---- attempts --------------------------------------------------------------------------

    public function test_each_attempt_is_added_never_overwritten_and_improvement_is_reported(): void
    {
        $a1 = $this->attempt(4, ['worked_minutes' => 35, 'attempt_date' => '2026-11-07'])->assertCreated();
        $a1->assertJsonPath('attempt.attempt_no', 1)->assertJsonPath('attempt.percent', 40)->assertJsonPath('improvement', null)
            ->assertJsonPath('topics.0.status', 'questions_practised');
        $this->attempt(8, ['worked_minutes' => 25, 'attempt_date' => '2026-11-08'])->assertCreated()->assertJsonPath('attempt.attempt_no', 2);
        $a3 = $this->attempt(10, ['worked_minutes' => 20])->assertCreated();
        $a3->assertJsonPath('attempt.attempt_no', 3)->assertJsonPath('improvement.score_points', 60)->assertJsonPath('improvement.minutes_change', -15)->assertJsonPath('improvement.best_percent', 100);

        $h = $this->getJson("/api/v1/questions/{$this->q->id}/attempts")->assertOk();
        $this->assertSame([1, 2, 3], array_column($h->json('attempts'), 'attempt_no'));
        $this->assertEquals([4, 8, 10], array_column($h->json('attempts'), 'marks_earned'));
        $this->assertSame('Limits', $h->json('question.topics.0.name'));
        $this->assertDatesArePlain($h->json());

        $this->getJson('/api/v1/attempts?subject_id=' . $this->maths->id)->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.question.paper_label', '2024 Paper I');
    }

    public function test_low_score_flags_revision_and_attempt_validation(): void
    {
        $this->attempt(8)->assertCreated();
        $this->attempt(2, ['result' => 'wrong'])->assertCreated()->assertJsonPath('topics.0.status', 'revision_required'); // 20% < the 50% weak threshold

        $this->attempt(11)->assertStatus(422)->assertJsonValidationErrors('marks_earned');                   // above the question's maximum
        $this->attempt(-1)->assertStatus(422);
        $this->attempt(5, ['attempt_date' => '2026-11-11'])->assertStatus(422)->assertJsonValidationErrors('attempt_date');
        $this->attempt(5, ['attempt_date' => '10/11/2026'])->assertStatus(422)->assertJsonValidationErrors('attempt_date');
        $this->attempt(5, ['result' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors('result');
        $this->attempt(5, ['independent' => 'perhaps'])->assertStatus(422)->assertJsonValidationErrors('independent');
        $this->attempt(5, ['mistake_category' => 'laziness'])->assertStatus(422);
        $this->attempt(5, ['worked_minutes' => 5000])->assertStatus(422);
        $this->attempt(5, ['question_id' => 99999])->assertStatus(422)->assertJsonValidationErrors('question_id');
        $this->postJson('/api/v1/attempts', [])->assertStatus(422)->assertJsonValidationErrors(['question_id', 'attempt_date', 'marks_earned', 'result', 'independent']);
        $this->assertSame(2, \App\Models\QuestionAttempt::count());
    }

    public function test_correcting_an_attempt_edits_in_place_audits_it_and_cannot_change_its_identity(): void
    {
        $id = $this->attempt(9)->json('attempt.id');
        $other = ExamQuestion::create(['paper_id' => $this->paper->id, 'question_no' => '2', 'max_marks' => 5]);
        $this->putJson("/api/v1/syllabus/topics/{$this->topic->id}", ['status' => 'mastered'])->assertOk();

        $this->putJson("/api/v1/attempts/{$id}", ['attempt_date' => '2026-11-09', 'marks_earned' => 5, 'result' => 'partial', 'independent' => true, 'worked_minutes' => 30,
            'question_id' => $other->id, 'attempt_no' => 7, 'max_marks' => 99, 'student_id' => 50])->assertOk()->assertJsonPath('attempt.marks_earned', 5)->assertJsonPath('attempt.attempt_no', 1)
            ->assertJsonPath('attempt.question_id', $this->q->id);

        $this->assertSame(1, \App\Models\QuestionAttempt::count());
        $this->assertSame(10.0, (float) \App\Models\QuestionAttempt::first()->max_marks);
        $this->assertSame('revision_required', $this->topic->fresh()->status);           // the evidence behind Mastered was edited away
        $log = AuditLog::where(['entity_type' => 'question_attempts', 'entity_id' => $id])->first();
        $this->assertEquals(9, $log->old_values['marks_earned']);
        $this->assertEquals(5, $log->new_values['marks_earned']);
        $this->assertSame($this->user->id, $log->user_id);

        $this->putJson("/api/v1/attempts/{$id}", ['attempt_date' => '2026-11-09', 'marks_earned' => 11, 'result' => 'correct', 'independent' => true])->assertStatus(422)->assertJsonValidationErrors('marks_earned');
        $this->putJson('/api/v1/attempts/99999', ['attempt_date' => '2026-11-09', 'marks_earned' => 1, 'result' => 'wrong', 'independent' => true])->assertNotFound();
    }

    public function test_complete_paper_total_comes_from_linked_questions_not_both(): void
    {
        $pa = $this->postJson('/api/v1/paper-attempts', ['paper_id' => $this->paper->id, 'attempt_date' => '2026-11-09', 'marks_earned' => 90, 'max_marks' => 100, 'worked_minutes' => 170, 'independent' => true, 'timed' => true])
            ->assertCreated()->assertJsonPath('paper_attempt.total.source', 'manual')->assertJsonPath('paper_attempt.total.percent', 90)->assertJsonPath('paper_attempt.timed', true);
        $id = $pa->json('paper_attempt.id');

        $this->attempt(7, ['paper_attempt_id' => $id])->assertCreated();
        $list = $this->getJson('/api/v1/paper-attempts')->assertOk();
        $this->assertSame('questions', $list->json('data.0.total.source'));
        $this->assertEquals(7, $list->json('data.0.total.earned'));                       // 7, not 90 and not 97

        $this->putJson("/api/v1/paper-attempts/{$id}", ['attempt_date' => '2026-11-09', 'marks_earned' => 95, 'max_marks' => 100, 'independent' => true])->assertOk();
        $this->assertSame(1, \App\Models\PaperAttempt::count());
        $this->putJson("/api/v1/paper-attempts/{$id}", ['attempt_date' => '2026-11-09', 'marks_earned' => 120, 'max_marks' => 100, 'independent' => true])->assertStatus(422)->assertJsonValidationErrors('max_marks');
        $this->postJson('/api/v1/paper-attempts', ['paper_id' => 99999, 'attempt_date' => '2026-11-09', 'independent' => true])->assertStatus(422);
        $this->assertSame(1, \App\Models\PaperAttempt::count()); // the invalid request created nothing
    }

    // ---- theory ----------------------------------------------------------------------------

    public function test_theory_complete_is_not_mastered_and_entries_can_be_corrected(): void
    {
        $r = $this->postJson('/api/v1/theory', ['study_date' => '2026-11-09', 'topic_id' => $this->topic->id, 'minutes' => 45, 'marked_complete' => true, 'confidence' => 3])->assertCreated();
        $r->assertJsonPath('entry.marked_complete', true)->assertJsonPath('entry.topic.status', 'theory_completed')->assertJsonPath('entry.date', '2026-11-09');
        $this->assertSame('theory_completed', $this->topic->fresh()->status);               // never mastered by reading theory
        $id = $r->json('entry.id');

        $this->putJson("/api/v1/theory/{$id}", ['study_date' => '2026-11-08', 'minutes' => 90, 'confidence' => 4, 'notes' => 'longer'])->assertOk()->assertJsonPath('entry.minutes', 90)->assertJsonPath('entry.date', '2026-11-08');
        $this->assertTrue(TheoryStudyEntry::first()->marked_complete);                      // completion flag untouched
        $this->assertSame(90, $this->topic->fresh()->theory_minutes);
        $log = AuditLog::where(['entity_type' => 'theory_study_entries', 'entity_id' => $id])->first();
        $this->assertSame(45, $log->old_values['minutes']);
        $this->assertSame($this->user->id, $log->user_id);

        $this->getJson('/api/v1/theory')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.topic.name', 'Limits');
    }

    public function test_theory_validation(): void
    {
        $base = ['study_date' => '2026-11-09', 'topic_id' => $this->topic->id, 'minutes' => 30];
        $this->postJson('/api/v1/theory', array_merge($base, ['minutes' => 0]))->assertStatus(422)->assertJsonValidationErrors('minutes');
        $this->postJson('/api/v1/theory', array_merge($base, ['minutes' => 2000]))->assertStatus(422);
        $this->postJson('/api/v1/theory', array_merge($base, ['study_date' => '2026-11-11']))->assertStatus(422)->assertJsonValidationErrors('study_date');
        $this->postJson('/api/v1/theory', array_merge($base, ['topic_id' => 99999]))->assertStatus(422)->assertJsonValidationErrors('topic_id');
        $this->postJson('/api/v1/theory', array_merge($base, ['confidence' => 7]))->assertStatus(422)->assertJsonValidationErrors('confidence');
        $this->putJson('/api/v1/theory/99999', ['study_date' => '2026-11-09', 'minutes' => 5])->assertNotFound();
        $this->assertSame(0, TheoryStudyEntry::count());
    }

    // ---- mistakes --------------------------------------------------------------------------

    public function test_mistake_queue_and_review_follow_the_same_rules_as_the_website(): void
    {
        $this->attempt(2, ['result' => 'wrong'])->assertCreated();
        $q = $this->getJson('/api/v1/mistakes')->assertOk()->assertJsonPath('count', 1);
        $this->assertSame('Wrong', $q->json('data.0.reasons.0'));
        $this->assertSame('Limits', $q->json('data.0.question.topics.0.name'));
        $this->assertFalse($q->json('data.0.repeated'));
        $this->assertDatesArePlain($q->json());

        $rev = ['question_id' => $this->q->id, 'reviewed_on' => '2026-11-10'];
        $this->postJson('/api/v1/mistakes', $rev + ['solution_understood' => true, 'theory_reviewed' => true])->assertCreated();
        $this->getJson('/api/v1/mistakes')->assertJsonPath('count', 1);                      // reading the solution does not clear it
        $this->postJson('/api/v1/mistakes', $rev + ['mastered_independently' => true])->assertStatus(422)->assertJsonValidationErrors('mastered_independently');

        $this->attempt(9)->assertCreated();                                                  // independent and >= 80%
        $this->getJson('/api/v1/mistakes')->assertJsonPath('count', 0);
        $this->postJson('/api/v1/mistakes', ['question_id' => 99999, 'reviewed_on' => '2026-11-10'])->assertStatus(422);
        $this->postJson('/api/v1/mistakes', array_merge($rev, ['reviewed_on' => '2026-11-12']))->assertStatus(422)->assertJsonValidationErrors('reviewed_on');
    }

    // ---- reports ---------------------------------------------------------------------------

    public function test_reports_weekly_monthly_and_custom_ranges(): void
    {
        $this->putDay('2026-11-09', self::DAY)->assertOk();
        $this->attempt(4)->assertCreated();
        $this->attempt(8, ['attempt_date' => '2026-11-10'])->assertCreated();

        $w = $this->getJson('/api/v1/reports?anchor=2026-11-10')->assertOk();
        $this->assertDatesArePlain($w->json());
        $w->assertJsonPath('type', 'weekly')->assertJsonPath('from', '2026-11-09')->assertJsonPath('to', '2026-11-15')->assertJsonPath('questions.attempts', 2)
            ->assertJsonPath('questions.weighted_pct', 60)->assertJsonPath('hours.rows.theory.actual_range', 75);
        $this->assertArrayNotHasKey('monthly', $w->json());

        $m = $this->getJson('/api/v1/reports?type=monthly&anchor=2026-11-10')->assertOk()->assertJsonPath('from', '2026-11-01')->assertJsonPath('to', '2026-11-30');
        $this->assertArrayHasKey('monthly', $m->json());
        $this->assertDatesArePlain($m->json());

        $c = $this->getJson('/api/v1/reports?from=2026-11-10&to=2026-11-10&subject_id=' . $this->maths->id)->assertOk()->assertJsonPath('questions.attempts', 1)->assertJsonPath('subject', 'Combined Mathematics');
        $this->assertEquals(80, $c->json('questions.weighted_pct'));

        $this->getJson('/api/v1/reports?type=yearly')->assertStatus(422);
        $this->getJson('/api/v1/reports?from=2026-11-10')->assertStatus(422);
        $this->getJson('/api/v1/reports?from=2026-11-10&to=2026-11-01')->assertStatus(422);
        $this->getJson('/api/v1/reports?from=2020-01-01&to=2026-11-01')->assertStatus(422);
        $this->getJson('/api/v1/reports?anchor=garbage')->assertStatus(422);
        $this->getJson('/api/v1/reports?subject_id=999')->assertStatus(422);
    }

    // ---- hostile input ---------------------------------------------------------------------

    public function test_hostile_input_never_causes_a_server_error_or_stores_markup_unescaped_for_other_clients(): void
    {
        $xss = '<script>alert(1)</script>';
        $this->putDay('2026-11-09', self::DAY, ['notes' => $xss])->assertOk()->assertJsonPath('log.notes', $xss);   // stored as plain text; clients render it as text
        foreach (["/api/v1/attempts?subject_id=1' OR '1'='1", '/api/v1/papers?year=abc', '/api/v1/papers?subject_id[]=1', '/api/v1/syllabus?subject_id=1;--',
            '/api/v1/daily-logs/2026-11-09%27%20OR%201=1', '/api/v1/daily-logs?per_page=-1', '/api/v1/reports?anchor[]=x', '/api/v1/topics?subject_id=abc'] as $url) {
            $status = $this->getJson($url)->getStatusCode();
            $this->assertLessThan(500, $status, "{$url} -> {$status}");
        }
        $this->assertSame(3, Subject::count());
    }

    public function test_no_response_leaks_user_or_secret_fields(): void
    {
        foreach (['/api/v1/bootstrap', '/api/v1/dashboard', '/api/v1/daily-logs/2026-11-09', '/api/v1/syllabus?subject_id=' . $this->maths->id, '/api/v1/papers', '/api/v1/mistakes', '/api/v1/reports'] as $url) {
            $body = $this->getJson($url)->assertOk()->getContent();
            foreach (['password', 'remember_token', 's@example.com', 'student_id'] as $needle) {
                $this->assertStringNotContainsString($needle, $body, "{$url} leaks {$needle}");
            }
        }
    }
}
