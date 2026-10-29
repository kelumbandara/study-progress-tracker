<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\StudyDailyLog;
use App\Models\StudyTarget;
use App\Models\StudyTargetHistory;
use App\Models\Subject;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function payload(array $override = []): array
    {
        $subjects = Subject::all()->mapWithKeys(fn ($s) => [$s->id => [
            'priority' => $s->priority, 'weak_score_pct' => $s->weak_score_pct, 'slow_minutes_per_mark' => '',
        ]])->all();

        return array_replace_recursive([
            'study_start' => '2026-10-28', 'study_end' => '2027-07-31', 'exam_date' => null,
            'timezone' => 'Asia/Colombo', 'paper_year_from' => 2017, 'paper_year_to' => 2026,
            'hours' => ['school' => '', 'tuition' => '', 'theory' => '', 'papers' => ''],
            'subjects' => $subjects,
        ], $override);
    }

    public function test_main_pages_open_for_the_signed_in_account(): void
    {
        $this->get('/')->assertOk()->assertSee('Not Configured');
        $this->get('/settings')->assertOk()->assertSee('Study period');
    }

    public function test_seed_defaults(): void
    {
        $this->assertSame(3, Subject::count());
        $this->assertSame('high', Subject::where('name', 'Combined Mathematics')->value('priority'));
        $this->assertSame(4, StudyTarget::whereNull('planned_minutes')->count());
    }

    public function test_decimal_hours_are_stored_as_minutes_and_history_kept(): void
    {
        $this->put('/settings', $this->payload(['hours' => ['theory' => '125.5'], 'reason' => 'initial plan']))
            ->assertRedirect('/settings');

        $this->assertSame(7530, StudyTarget::where('category', 'theory')->value('planned_minutes'));
        $h = StudyTargetHistory::where('category', 'theory')->first();
        $this->assertNull($h->old_minutes);
        $this->assertSame(7530, $h->new_minutes);
        $this->assertSame('initial plan', $h->reason);

        // Revise: original is preserved in history, a new history row is added.
        $this->put('/settings', $this->payload(['hours' => ['theory' => '200']]));
        $this->assertSame(12000, StudyTarget::where('category', 'theory')->value('planned_minutes'));
        $this->assertSame(2, StudyTargetHistory::where('category', 'theory')->count());
        $this->get('/settings')->assertSee('Original: 125.5 h');
    }

    public function test_unchanged_save_adds_no_history(): void
    {
        $this->put('/settings', $this->payload(['hours' => ['school' => '100']]));
        $this->put('/settings', $this->payload(['hours' => ['school' => '100']]));
        $this->assertSame(1, StudyTargetHistory::where('category', 'school')->count());
    }

    public function test_negative_hours_are_rejected(): void
    {
        $this->from('/settings')->put('/settings', $this->payload(['hours' => ['papers' => '-5']]))
            ->assertSessionHasErrors('hours.papers');
        $this->assertNull(StudyTarget::where('category', 'papers')->value('planned_minutes'));
    }

    public function test_end_date_must_be_after_start(): void
    {
        $this->from('/settings')->put('/settings', $this->payload(['study_end' => '2026-10-01']))
            ->assertSessionHasErrors('study_end');
    }

    public function test_changing_dates_keeps_existing_logs_and_is_audited(): void
    {
        StudyDailyLog::create(['student_id' => 1, 'log_date' => '2026-11-02', 'theory_minutes' => 60]);

        $this->put('/settings', $this->payload(['study_start' => '2026-12-01']))->assertRedirect('/settings');

        $this->assertSame(1, StudyDailyLog::count());
        $this->assertTrue(AuditLog::where('entity_type', 'study_settings')->where('action', 'updated')->exists());
        $this->get('/settings')->assertSee('1 existing daily log(s) fall outside this period');
    }

    public function test_subject_priority_can_change_without_touching_hours(): void
    {
        $physics = Subject::where('name', 'Physics')->first();
        $this->put('/settings', $this->payload(['subjects' => [$physics->id => ['priority' => 'high', 'weak_score_pct' => 40]]]));

        $this->assertSame('high', $physics->fresh()->priority);
        $this->assertSame(40, $physics->fresh()->weak_score_pct);
        $this->assertSame(4, StudyTarget::whereNull('planned_minutes')->count());
    }

    public function test_actuals_come_from_daily_logs_only(): void
    {
        $this->put('/settings', $this->payload(['hours' => ['theory' => '10']]));
        StudyDailyLog::create(['student_id' => 1, 'log_date' => '2026-10-28', 'theory_minutes' => 90]);

        $this->get('/')->assertOk()->assertSee('1.5 h');
    }
}
