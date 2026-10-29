<?php

namespace App\Support;

/** Durations are stored as integer minutes; this is the only place they are converted for display/input. */
class Duration
{
    public static function format(?int $minutes): string
    {
        if ($minutes === null) {
            return '—';
        }
        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);

        return sprintf('%s%d h %02d min', $sign, intdiv($minutes, 60), $minutes % 60);
    }

    public static function hours(?int $minutes, int $decimals = 1): string
    {
        return $minutes === null ? '—' : number_format($minutes / 60, $decimals);
    }

    /** Decimal hours (e.g. 125.5) to whole minutes, or null for blank. */
    public static function fromHours(string|float|int|null $hours): ?int
    {
        if ($hours === null || $hours === '') {
            return null;
        }

        return (int) round(((float) $hours) * 60);
    }
}
