<?php

namespace App\Services;

use App\Models\StudySetting;
use Carbon\CarbonImmutable;

/**
 * Calendar maths for the configured study period.
 *
 * "Remaining" days include today while today is inside the period, because today's
 * log may not have been entered yet. "Elapsed" is therefore the days before today.
 */
class StudyPeriodService
{
    public function summarize(StudySetting $s, ?CarbonImmutable $today = null): array
    {
        $tz = $s->timezone;
        $today = ($today ?? CarbonImmutable::now($tz))->setTimezone($tz)->startOfDay();
        $start = CarbonImmutable::parse($s->study_start->toDateString(), $tz)->startOfDay();
        $end = CarbonImmutable::parse($s->study_end->toDateString(), $tz)->startOfDay();

        $total = (int) $start->diffInDays($end) + 1; // inclusive of both ends

        if ($today->lt($start)) {
            $remaining = $total;
        } elseif ($today->gt($end)) {
            $remaining = 0;
        } else {
            $remaining = (int) $today->diffInDays($end) + 1;
        }
        $elapsed = $total - $remaining;

        return [
            'start' => $start,
            'end' => $end,
            'exam_date' => $s->exam_date,
            'today' => $today,
            'total_days' => $total,
            'elapsed_days' => $elapsed,
            'remaining_days' => $remaining,
            'remaining_weeks' => $remaining / 7,
            'total_weeks' => $total / 7,
            'percent_elapsed' => $total > 0 ? round($elapsed / $total * 100, 1) : 0.0,
            'not_started' => $today->lt($start),
            'ended' => $today->gt($end),
        ];
    }
}
