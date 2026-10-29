<?php

namespace Tests\Unit;

use App\Models\StudySetting;
use App\Services\StudyPeriodService;
use App\Services\TargetProgressService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class TargetProgressTest extends TestCase
{
    private function period(string $today): array
    {
        $s = new StudySetting(['study_start' => '2026-10-28', 'study_end' => '2027-07-31', 'timezone' => 'Asia/Colombo']);
        $s->study_start = CarbonImmutable::parse('2026-10-28');
        $s->study_end = CarbonImmutable::parse('2027-07-31');

        return (new StudyPeriodService())->summarize($s, CarbonImmutable::parse($today, 'Asia/Colombo'));
    }

    private function calc(?int $planned, int $actual, string $today, ?int $original = null): array
    {
        $svc = new TargetProgressService(new StudyPeriodService());

        return $svc->compute('theory', $planned, $actual, $original, $this->period($today));
    }

    public function test_period_counts_both_ends_inclusive(): void
    {
        // 28 Oct 2026 .. 31 Jul 2027 = 277 days (Oct 4 + Nov 30 + Dec 31 + Jan 31 + Feb 28 + Mar 31 + Apr 30 + May 31 + Jun 30 + Jul 31)
        $p = $this->period('2026-10-28');
        $this->assertSame(277, $p['total_days']);
        $this->assertSame(0, $p['elapsed_days']);
        $this->assertSame(277, $p['remaining_days']);
    }

    public function test_period_elapsed_and_remaining_mid_way(): void
    {
        $p = $this->period('2027-01-27'); // 91 days after start
        $this->assertSame(91, $p['elapsed_days']);
        $this->assertSame(186, $p['remaining_days']);
        $this->assertSame(32.9, $p['percent_elapsed']);
    }

    public function test_period_before_start_and_after_end(): void
    {
        $before = $this->period('2026-10-01');
        $this->assertTrue($before['not_started']);
        $this->assertSame(277, $before['remaining_days']);

        $after = $this->period('2027-08-05');
        $this->assertTrue($after['ended']);
        $this->assertSame(0, $after['remaining_days']);
        $this->assertSame(277, $after['elapsed_days']);
    }

    public function test_unconfigured_or_zero_planned_is_not_configured_not_division_by_zero(): void
    {
        foreach ([null, 0] as $planned) {
            $c = $this->calc($planned, 600, '2027-01-27');
            $this->assertFalse($c['configured']);
            $this->assertSame('not_configured', $c['status']);
            $this->assertNull($c['percent']);
            $this->assertSame(600, $c['actual']); // actual is still reported
        }
    }

    public function test_known_sample_values(): void
    {
        // Planned 278 h => 1 h/day. 91 days elapsed => expected 91 h. Actual 100 h.
        $c = $this->calc(277 * 60, 100 * 60, '2027-01-27');
        $this->assertSame(36.1, $c['percent']);
        $this->assertSame(177 * 60, $c['remaining']);
        $this->assertSame(91 * 60, $c['expected']);
        $this->assertSame('on_track', $c['status']);
        // 177 h over 186 days.
        $this->assertEqualsWithDelta(177 * 60 / 186, $c['required_daily'], 0.0001);
        $this->assertEqualsWithDelta(177 * 60 / (186 / 7), $c['required_weekly'], 0.0001);
    }

    public function test_behind_plan_reports_gap_and_recovery_rate(): void
    {
        $c = $this->calc(277 * 60, 50 * 60, '2027-01-27'); // expected 91 h, actual 50 h
        $this->assertSame('behind', $c['status']);
        $this->assertSame(41 * 60, $c['behind_by']);
        $this->assertStringContainsString('Behind the linear plan', $c['warnings'][0]);
        $this->assertGreaterThan($c['weekly_plan'], $c['required_weekly']);
    }

    public function test_overrun_is_shown_not_capped(): void
    {
        $c = $this->calc(100 * 60, 120 * 60, '2027-01-27');
        $this->assertSame('overrun', $c['status']);
        $this->assertSame(120.0, $c['percent']);
        $this->assertSame(0, $c['remaining']);
        $this->assertSame(20 * 60, $c['overrun']);
        $this->assertSame(120 * 60, $c['actual']);
    }

    public function test_end_date_passed_with_hours_remaining_warns(): void
    {
        $c = $this->calc(100 * 60, 40 * 60, '2027-08-05');
        $this->assertNull($c['required_weekly']);
        $this->assertStringContainsString('end date has passed', $c['warnings'][0]);
    }

    public function test_revised_target_is_flagged(): void
    {
        $this->assertTrue($this->calc(200 * 60, 0, '2027-01-27', 150 * 60)['revised']);
        $this->assertFalse($this->calc(200 * 60, 0, '2027-01-27', 200 * 60)['revised']);
    }
}


