<?php

namespace Tests\Feature;

use App\Exports\ReportExport;
use App\Models\ExamPaper;
use App\Models\ExamQuestion;
use App\Models\PaperAttempt;
use App\Models\StudyDailyLog;
use App\Models\Subject;
use App\Models\SyllabusTopic;
use App\Models\SyllabusUnit;
use App\Services\AnalyticsService;
use App\Services\RecommendationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Subject $maths;
    private Subject $it;
    private ExamPaper $paper;
    private ExamQuestion $q1;
    private ExamQuestion $q2;
    private SyllabusTopic $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->travelTo(now('Asia/Colombo')->setDate(2026, 11, 10)->setTime(20, 0));

        $this->maths = Subject::where('name', 'Combined Mathematics')->first();
        $this->it = Subject::where('name', 'IT')->first();
        $unit = SyllabusUnit::create(['subject_id' => $this->maths->id, 'name' => 'U']);
        $this->topic = SyllabusTopic::create(['unit_id' => $unit->id, 'name' => 'Limits']);
        $this->paper = ExamPaper::create(['subject_id' => $this->maths->id, 'exam_year' => 2024, 'paper_name' => 'Paper I', 'max_marks' => 100, 'duration_minutes' => 180]);
        $this->q1 = ExamQuestion::create(['paper_id' => $this->paper->id, 'question_no' => '1', 'max_marks' => 10]);
        $this->q2 = ExamQuestion::create(['paper_id' => $this->paper->id, 'question_no' => '2', 'max_marks' => 20]);
        $this->q1->topics()->sync([$this->topic->id]);
        $this->q2->topics()->sync([$this->topic->id]);
    }

    private function attempt(ExamQuestion $q, float $marks, string $date, bool $independent = true, ?int $minutes = null, ?int $paperAttemptId = null): void
    {
        $this->post('/attempts', ['question_id' => $q->id, 'attempt_date' => $date, 'marks_earned' => $marks,
            'result' => $marks >= $q->max_marks ? 'correct' : 'partial', 'assistance' => $independent ? 'independent' : 'assisted',
            'worked_minutes' => $minutes, 'paper_attempt_id' => $paperAttemptId])->assertSessionHasNoErrors();
    }

    private function summary(?Subject $s = null): array
    {
        return app(AnalyticsService::class)->subjectSummary($s ?? $this->maths, 1);
    }

    private function seedKnownAttempts(): void
    {
        $this->attempt($this->q1, 4, '2026-11-02');            // Q1 first 40%
        $this->attempt($this->q1, 8, '2026-11-03');            // retry 80%
        $this->attempt($this->q1, 10, '2026-11-04', false);    // retry 100% (assisted)
        $this->attempt($this->q2, 10, '2026-11-05');           // Q2 first 50%
    }

    // ---- question performance ---------------------------------------------------------------

    public function test_known_sample_values_for_subject_summary(): void
    {
        $this->seedKnownAttempts();
        $s = $this->summary();

        $this->assertSame(4, $s['attempts']);
        $this->assertSame(2, $s['questions_attempted']);
        $this->assertSame(32.0, $s['marks_earned']);       // 4+8+10+10
        $this->assertSame(50.0, $s['marks_available']);    // 10+10+10+20
        $this->assertSame(64.0, $s['weighted_pct']);       // 32/50
        $this->assertSame(['n' => 2, 'pct' => 45.0], $s['first']);   // mean(40, 50)
        $this->assertSame(['n' => 2, 'pct' => 90.0], $s['retry']);   // mean(80, 100)
        $this->assertSame(['n' => 3, 'pct' => 56.7], $s['independent']); // mean(40, 80, 50)
        $this->assertSame(['n' => 1, 'pct' => 100.0], $s['assisted']);
        $this->assertFalse($s['enough_data']);              // fewer than 5 attempts
        $this->assertNull($s['trend']);                     // fewer than 6 attempts
    }

    public function test_trend_compares_last_five_with_previous_attempts(): void
    {
        $this->seedKnownAttempts();                          // 40, 80, 100, 50
        $this->attempt($this->q2, 10, '2026-11-06');         // 50
        $this->attempt($this->q2, 14, '2026-11-07');         // 70
        $this->attempt($this->q2, 18, '2026-11-08');         // 90
        $t = $this->summary()['trend'];

        // 7 attempts: last five = 100,50,50,70,90 -> 72.0; previous (2) = 40,80 -> 60.0
        $this->assertSame(72.0, $t['recent']);
        $this->assertSame(60.0, $t['previous']);
        $this->assertSame(12.0, $t['delta']);
        $this->assertSame(2, $t['n_previous']);
    }

    public function test_weak_topics_use_latest_attempt_per_question(): void
    {
        $this->attempt($this->q1, 2, '2026-11-02');  // 20%
        $this->attempt($this->q1, 3, '2026-11-03');  // latest Q1 = 30%
        $this->attempt($this->q2, 8, '2026-11-04');  // latest Q2 = 40%
        $w = $this->summary()['weak_topics'];

        $this->assertSame([['name' => 'Limits', 'n' => 2, 'pct' => 35.0]], $w); // mean(30,40), below weak 50
    }

    // ---- paper performance -----------------------------------------------------------------

    public function test_repeat_paper_attempts_are_not_new_results_and_time_is_not_double_counted(): void
    {
        $this->post('/paper-attempts', ['paper_id' => $this->paper->id, 'attempt_date' => '2026-11-03', 'marks_earned' => 50, 'max_marks' => 100, 'worked_minutes' => 170, 'timed' => 1]);
        $this->post('/paper-attempts', ['paper_id' => $this->paper->id, 'attempt_date' => '2026-11-08', 'marks_earned' => 70, 'max_marks' => 100, 'worked_minutes' => 150, 'timed' => 1]);
        $pa = PaperAttempt::first();
        $this->attempt($this->q1, 5, '2026-11-03', true, 40, $pa->id); // linked: its 40 min are part of the paper's 170

        $p = app(AnalyticsService::class)->paperPerformance(1, $this->maths->id);
        $this->assertCount(1, $p['first_results']);          // one paper => one result
        // The first attempt has a linked question (5 marks), so its total comes from the questions (5/100),
        // not from the typed 50 and not from 50 + 5.
        $this->assertSame(5.0, $p['first_results'][0]['pct']);
        $this->assertSame(1, $p['paper_count']);
        $this->assertSame(320, $p['minutes']);               // 170 + 150, not + 40
        $this->assertSame(2, $p['by_paper'][0]['attempts']);
        $this->assertSame(70.0, $p['by_paper'][0]['latest_pct']);
        $this->assertSame(180, $p['by_paper'][0]['official']);
        $this->assertNull($p['trend']);                      // trend needs two different papers

        $s = $this->summary();
        $this->assertSame(70.0, $s['latest_timed']['pct']);
        $this->assertSame(320, $s['paper_minutes']);
    }

    public function test_paper_trend_across_different_papers(): void
    {
        $p2 = ExamPaper::create(['subject_id' => $this->maths->id, 'exam_year' => 2023, 'paper_name' => 'Paper I', 'max_marks' => 100]);
        $this->post('/paper-attempts', ['paper_id' => $this->paper->id, 'attempt_date' => '2026-11-03', 'marks_earned' => 40, 'max_marks' => 100]);
        $this->post('/paper-attempts', ['paper_id' => $p2->id, 'attempt_date' => '2026-11-07', 'marks_earned' => 55, 'max_marks' => 100]);

        $p = app(AnalyticsService::class)->paperPerformance(1, $this->maths->id);
        $this->assertSame(15.0, $p['trend']);
        $this->assertSame(47.5, $p['first_avg']);
    }

    // ---- consistency -----------------------------------------------------------------------

    public function test_consistency_counts_missing_days_without_treating_them_as_zero(): void
    {
        foreach ([['2026-11-02', 60, 0], ['2026-11-04', 120, 60], ['2026-11-06', 0, 0]] as [$d, $th, $pa]) {
            StudyDailyLog::create(['student_id' => 1, 'log_date' => $d, 'theory_minutes' => $th, 'papers_minutes' => $pa, 'school_minutes' => 300]);
        }
        $settings = \App\Models\StudySetting::first();
        $c = app(AnalyticsService::class)->consistency(1, $settings);

        $this->assertSame(14, $c['days_in_range']);          // 28 Oct .. 10 Nov
        $this->assertSame(3, $c['days_logged']);
        $this->assertSame(11, $c['days_missing']);
        $this->assertSame(2, $c['days_with_independent']);   // the third log has zero independent time
        $this->assertSame(380, $c['avg_daily_minutes']);     // (360+480+300)/3
        $this->assertSame(80, $c['avg_daily_independent']);  // (60+180+0)/3
        $this->assertSame(2660, $c['avg_weekly_minutes']);   // 380 * 7
    }

    public function test_no_logs_gives_null_averages(): void
    {
        $c = app(AnalyticsService::class)->consistency(1, \App\Models\StudySetting::first());
        $this->assertNull($c['avg_daily_minutes']);
        $this->assertSame(14, $c['days_missing']);
    }

    // ---- recommendations -------------------------------------------------------------------

    public function test_not_enough_data_message_when_few_attempts(): void
    {
        $this->attempt($this->q1, 2, '2026-11-02');
        $this->get('/')->assertOk()->assertSee(RecommendationService::NOT_ENOUGH)->assertDontSee('Prioritise its weak topics');
    }

    public function test_high_priority_low_scores_trigger_a_recommendation_with_weak_topics(): void
    {
        foreach (['2026-11-01', '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05'] as $d) {
            $this->attempt($this->q2, 4, $d); // 20% each
        }
        $s = $this->summary();
        $this->assertTrue($s['enough_data']);
        $this->get('/')->assertSee('high priority')->assertSee('Prioritise its weak topics: Limits')->assertSee('not a prediction of any exam result');
    }

    public function test_strong_maintain_subject_is_told_to_maintain(): void
    {
        $unit = SyllabusUnit::create(['subject_id' => $this->it->id, 'name' => 'ITU']);
        $paper = ExamPaper::create(['subject_id' => $this->it->id, 'exam_year' => 2024, 'paper_name' => 'Paper II']);
        $q = ExamQuestion::create(['paper_id' => $paper->id, 'question_no' => '1', 'max_marks' => 10]);
        foreach (range(1, 5) as $i) {
            $this->attempt($q, 9, '2026-11-0' . $i);
        }
        $this->get('/')->assertSee('consistently strong')->assertSee('Maintain it');
    }

    public function test_behind_plan_recommendation_states_extra_weekly_hours(): void
    {
        $this->put('/settings', ['study_start' => '2026-10-28', 'study_end' => '2027-07-31', 'timezone' => 'Asia/Colombo', 'paper_year_from' => 2017, 'paper_year_to' => 2026,
            'hours' => ['theory' => '277'], 'subjects' => Subject::all()->mapWithKeys(fn ($s) => [$s->id => ['priority' => $s->priority, 'weak_score_pct' => $s->weak_score_pct]])->all()]);
        $this->travelTo(now('Asia/Colombo')->setDate(2027, 1, 27)->setTime(20, 0)); // 91 days elapsed => expected 91 h; actual 0
        $this->get('/')->assertSee('Study Theory is behind plan');
    }

    // ---- dashboard and reports -------------------------------------------------------------

    public function test_dashboard_shows_subject_cards_charts_and_first_vs_retry(): void
    {
        $this->seedKnownAttempts();
        StudyDailyLog::create(['student_id' => 1, 'log_date' => '2026-11-02', 'theory_minutes' => 60, 'papers_minutes' => 60]);

        $this->get('/')->assertOk()->assertSee('Combined Mathematics')->assertSee('First attempts')->assertSee('45%')->assertSee('90%')
            ->assertSee('Marks earned / available')->assertSee('32 / 50')->assertSee('id="chartHours"', false)->assertSee('id="chartScores"', false);
    }

    private function logWeek(): void
    {
        StudyDailyLog::create(['student_id' => 1, 'log_date' => '2026-11-09', 'theory_minutes' => 60, 'papers_minutes' => 120, 'school_minutes' => 300]);
        StudyDailyLog::create(['student_id' => 1, 'log_date' => '2026-11-10', 'theory_minutes' => 30, 'papers_minutes' => 60]);
        StudyDailyLog::create(['student_id' => 1, 'log_date' => '2026-11-02', 'theory_minutes' => 600]); // previous week
    }

    private function setTheoryPlan(string $hours): void
    {
        $this->put('/settings', ['study_start' => '2026-10-28', 'study_end' => '2027-07-31', 'timezone' => 'Asia/Colombo', 'paper_year_from' => 2017, 'paper_year_to' => 2026,
            'hours' => ['theory' => $hours], 'subjects' => Subject::all()->mapWithKeys(fn ($s) => [$s->id => ['priority' => $s->priority, 'weak_score_pct' => $s->weak_score_pct]])->all()]);
    }

    public function test_weekly_report_uses_only_the_chosen_week_and_prorates_the_plan(): void
    {
        $this->setTheoryPlan('277'); // 1 h/day
        $this->logWeek();
        $this->seedKnownAttempts();

        $r = $this->get('/reports?type=weekly&anchor=2026-11-10')->assertOk();
        $r->assertSee('09 Nov 2026 to 15 Nov 2026');
        $r->assertSee('7 h 00 min');          // planned for the 7-day range
        $r->assertSee('1 h 30 min');          // actual theory in the week (60+30), not the 600 min of the week before
        $r->assertSee('2 of 2 day(s) logged'); // the range is capped at today (10 Nov)
    }

    public function test_report_date_range_and_subject_filters(): void
    {
        $this->seedKnownAttempts();
        $this->get('/reports?from=2026-11-04&to=2026-11-05&subject=' . $this->maths->id)->assertOk()
            ->assertSee('2 attempts on 2 question(s)')->assertSee('Combined Mathematics');
        $this->get('/reports?from=2026-11-04&to=2026-11-05&subject=' . $this->it->id)->assertOk()->assertSee('No question attempts in this range');
        $this->get('/reports?from=2026-11-05&to=2026-11-01')->assertSessionHasErrors('to');
        $this->get('/reports?from=2020-01-01&to=2026-11-01')->assertStatus(422);
    }

    public function test_monthly_report_lists_paper_results_by_year_with_time_against_official_duration(): void
    {
        $p2 = ExamPaper::create(['subject_id' => $this->maths->id, 'exam_year' => 2023, 'paper_name' => 'Paper I', 'max_marks' => 100, 'duration_minutes' => 150]);
        $this->post('/paper-attempts', ['paper_id' => $this->paper->id, 'attempt_date' => '2026-11-03', 'marks_earned' => 40, 'max_marks' => 100, 'worked_minutes' => 200]);
        $this->post('/paper-attempts', ['paper_id' => $this->paper->id, 'attempt_date' => '2026-11-08', 'marks_earned' => 70, 'max_marks' => 100, 'worked_minutes' => 170]);
        $this->post('/paper-attempts', ['paper_id' => $p2->id, 'attempt_date' => '2026-11-09', 'marks_earned' => 55, 'max_marks' => 100, 'worked_minutes' => 150]);

        $this->get('/reports?type=monthly&anchor=2026-11-10')->assertOk()
            ->assertSee('Paper results to date')->assertSee('200 min / 180 min')->assertSee('150 min / 150 min')
            ->assertSee('Average of first attempts: 47.5% over 2 paper(s)');

        $csv = $this->get('/reports/export?type=monthly&anchor=2026-11-10&format=csv')->assertOk();
        $content = file_get_contents($csv->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('Paper result', $content);
        $this->assertStringContainsString('40% first attempt', $content);
        $this->assertStringContainsString('latest 70%', $content); // the retry is reported, but is not the paper's result
    }

    public function test_adding_a_subtopic_through_the_form(): void
    {
        $this->post('/syllabus/topics', ['unit_id' => $this->topic->unit_id, 'parent_id' => $this->topic->id, 'name' => 'One-sided limits', 'estimated_hours' => 1.5])->assertSessionHasNoErrors();
        $sub = SyllabusTopic::where('name', 'One-sided limits')->first();
        $this->assertSame($this->topic->id, $sub->parent_id);
        $this->assertSame(90, $sub->estimated_minutes);
        $this->get('/syllabus?subject=' . $this->maths->id)->assertSee('One-sided limits');
    }

    public function test_monthly_report_has_forecast_and_coverage_sections(): void
    {
        $this->setTheoryPlan('277');
        $this->get('/reports?type=monthly&anchor=2026-11-10')->assertOk()->assertSee('Monthly detail')->assertSee('Cumulative syllabus coverage')
            ->assertSee('Workload forecast to the end date')->assertSee('01 Nov 2026 to 30 Nov 2026');
    }

    public function test_csv_and_excel_exports(): void
    {
        $this->setTheoryPlan('277');
        $this->logWeek();
        $this->seedKnownAttempts();

        $csv = $this->get('/reports/export?type=weekly&anchor=2026-11-10&format=csv')->assertOk();
        $content = file_get_contents($csv->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('Weekly report', $content);
        $this->assertStringContainsString('Study Theory', $content);
        $this->assertStringContainsString('Questions', $content);
        $this->assertStringContainsString('weekly-report-2026-11-09-to-2026-11-15.csv', $csv->headers->get('content-disposition'));

        $xlsx = $this->get('/reports/export?type=monthly&anchor=2026-11-10&format=xlsx')->assertOk();
        $this->assertStringContainsString('.xlsx', $xlsx->headers->get('content-disposition'));
        $this->assertSame('PK', substr(file_get_contents($xlsx->baseResponse->getFile()->getPathname()), 0, 2)); // zip container
    }

    public function test_export_neutralises_spreadsheet_formulas(): void
    {
        $rows = (new ReportExport([['=HYPERLINK("http://x")', '+1', '-2', '@x', 'safe', 5]]))->array();
        $this->assertSame(["'=HYPERLINK(\"http://x\")", "'+1", "'-2", "'@x", 'safe', 5], $rows[0]);
    }

    public function test_reports_page_renders_with_no_data_at_all(): void
    {
        $this->get('/reports')->assertOk()->assertSee('Not enough data yet')->assertSee('Not configured');
        $this->get('/reports?type=monthly')->assertOk();
    }
}
