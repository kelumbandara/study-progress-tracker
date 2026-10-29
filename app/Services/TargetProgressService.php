<?php

namespace App\Services;

use App\Models\StudentProfile;
use App\Models\StudySetting;
use App\Models\StudyDailyLog;
use App\Models\StudyTarget;
use App\Models\StudyTargetHistory;
use App\Support\Category;
use App\Support\Duration;
use Carbon\CarbonImmutable;

/**
 * Planned vs actual time per category. Actuals come only from daily logs;
 * question- and topic-level minutes are allocations and are never added here.
 */
class TargetProgressService
{
    public function __construct(private StudyPeriodService $periods)
    {
    }

    public function actualMinutes(int $studentId): array
    {
        $row = StudyDailyLog::query()
            ->where('student_id', $studentId)
            ->selectRaw('COALESCE(SUM(school_minutes),0) school, COALESCE(SUM(tuition_minutes),0) tuition, COALESCE(SUM(theory_minutes),0) theory, COALESCE(SUM(papers_minutes),0) papers')
            ->first();

        return array_map('intval', [
            'school' => $row->school, 'tuition' => $row->tuition,
            'theory' => $row->theory, 'papers' => $row->papers,
        ]);
    }

    /** Original = first value ever set for the category; null if never configured. */
    public function originalMinutes(int $studentId, string $category): ?int
    {
        $first = StudyTargetHistory::query()
            ->where(['student_id' => $studentId, 'category' => $category])
            ->whereNotNull('new_minutes')
            ->orderBy('changed_at')->orderBy('id')
            ->first();

        return $first?->new_minutes;
    }

    /** @return array<string,array> keyed by category */
    public function categories(StudentProfile $student, StudySetting $settings, ?CarbonImmutable $today = null): array
    {
        $period = $this->periods->summarize($settings, $today);
        $planned = StudyTarget::query()->where('student_id', $student->id)->pluck('planned_minutes', 'category');
        $actual = $this->actualMinutes($student->id);
        $out = [];

        foreach (Category::keys() as $key) {
            $out[$key] = $this->compute(
                $key,
                $planned[$key] ?? null,
                $actual[$key],
                $this->originalMinutes($student->id, $key),
                $period
            );
        }

        return $out;
    }

    public function compute(string $key, ?int $planned, int $actual, ?int $original, array $period): array
    {
        $base = [
            'key' => $key, 'label' => Category::LABELS[$key],
            'planned' => $planned, 'actual' => $actual, 'original' => $original,
            'revised' => $original !== null && $planned !== null && $original !== $planned,
            'independent' => in_array($key, Category::INDEPENDENT, true),
        ];

        // Zero or unset is "Not Configured"; never divide by it.
        if (! $planned) {
            return $base + [
                'configured' => false, 'status' => 'not_configured', 'percent' => null,
                'remaining' => null, 'overrun' => 0, 'expected' => null,
                'daily_plan' => null, 'weekly_plan' => null, 'required_weekly' => null,
                'required_daily' => null, 'behind_by' => null, 'warnings' => [],
            ];
        }

        $remaining = max(0, $planned - $actual);
        $overrun = max(0, $actual - $planned);
        $expected = (int) round($planned * $period['elapsed_days'] / max(1, $period['total_days']));
        $weeklyPlan = $planned / $period['total_weeks'];
        $requiredWeekly = $requiredDaily = null;
        $warnings = [];
        $flags = []; // language-independent markers, so advice logic never depends on the wording of a warning

        if ($remaining > 0) {
            if ($period['remaining_days'] > 0) {
                $requiredDaily = $remaining / $period['remaining_days'];
                $requiredWeekly = $remaining / $period['remaining_weeks'];
            } else {
                $warnings[] = __('The study end date has passed with planned hours still remaining.');
                $flags['end_passed'] = true;
            }
        }

        if ($overrun > 0) {
            $status = 'overrun';
        } elseif ($remaining === 0) {
            $status = 'complete';
        } elseif ($actual >= $expected) {
            $status = 'on_track';
        } else {
            $status = 'behind';
        }

        $behindBy = $status === 'behind' ? $expected - $actual : 0;
        if ($status === 'behind') {
            $warnings[] = __('Behind the linear plan by :time.', ['time' => Duration::format($behindBy)]);
        }
        if ($requiredWeekly !== null && $weeklyPlan > 0
            && $requiredWeekly > $weeklyPlan * config('tracker.unrealistic_rate_factor')) {
            $warnings[] = __('The weekly rate now needed is well above the original plan; review the target.');
            $flags['well_above'] = true;
        }

        return $base + [
            'configured' => true, 'status' => $status,
            'percent' => round($actual / $planned * 100, 1),
            'remaining' => $remaining, 'overrun' => $overrun, 'expected' => $expected,
            'daily_plan' => $planned / $period['total_days'], 'weekly_plan' => $weeklyPlan,
            'required_weekly' => $requiredWeekly, 'required_daily' => $requiredDaily,
            'behind_by' => $behindBy, 'warnings' => $warnings, 'flags' => $flags,
        ];
    }

    /** Totals across the four categories, ignoring unconfigured ones when comparing with the plan. */
    public function totals(array $categories): array
    {
        $configured = array_filter($categories, fn ($c) => $c['configured']);
        $planned = array_sum(array_column($configured, 'planned'));
        $actualConfigured = array_sum(array_column($configured, 'actual'));

        return [
            'configured_count' => count($configured),
            'planned' => $configured ? $planned : null,
            'actual_all' => array_sum(array_column($categories, 'actual')),
            'actual_configured' => $actualConfigured,
            'remaining' => $configured ? max(0, $planned - $actualConfigured) : null,
            'expected' => $configured ? array_sum(array_column($configured, 'expected')) : null,
            'percent' => $planned ? round($actualConfigured / $planned * 100, 1) : null,
            'independent_actual' => array_sum(array_map(fn ($c) => $c['independent'] ? $c['actual'] : 0, $categories)),
        ];
    }
}
