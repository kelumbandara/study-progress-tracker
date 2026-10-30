<?php

namespace App\Services;

use App\Models\PhoneAppUsage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Stores and summarises the per-app phone use that the phone app sends. A day with no data is missing, never zero. */
class PhoneUsageService
{
    public function categoryFor(string $package): string
    {
        $exact = config('phone_usage.exact');
        if (isset($exact[$package])) {
            return $exact[$package];
        }
        foreach (config('phone_usage.prefix') as $prefix => $category) {
            if (str_starts_with($package, $prefix)) {
                return $category;
            }
        }

        return 'other';
    }

    /**
     * Replaces the whole day with what the phone measured (the phone always sends the complete day, so repeating is harmless).
     *
     * @param array<int,array{package:string,label:string,minutes:int}> $apps
     * @return int number of apps stored
     */
    public function storeDay(int $studentId, string $date, array $apps): int
    {
        $merged = [];
        foreach ($apps as $a) {
            $key = $a['package'];
            $merged[$key] = ['label' => $a['label'], 'minutes' => min(1440, ($merged[$key]['minutes'] ?? 0) + (int) $a['minutes'])];
        }
        $now = now();
        $rows = collect($merged)->filter(fn ($m) => $m['minutes'] > 0)->map(fn ($m, $package) => [
            'student_id' => $studentId, 'usage_date' => $date, 'package' => $package, 'label' => $m['label'],
            'minutes' => $m['minutes'], 'category' => $this->categoryFor($package), 'created_at' => $now, 'updated_at' => $now,
        ])->values()->all();

        DB::transaction(function () use ($studentId, $date, $rows) {
            PhoneAppUsage::query()->where(['student_id' => $studentId, 'usage_date' => $date])->delete();
            foreach (array_chunk($rows, 100) as $chunk) {
                PhoneAppUsage::query()->insert($chunk);
            }
        });

        return count($rows);
    }

    /** Deletes everything the phone ever sent (the student's right to stop and erase). */
    public function clear(int $studentId): int
    {
        return PhoneAppUsage::query()->where('student_id', $studentId)->delete();
    }

    /** Totals for a date range. A day the phone has not reported has total_minutes = null (not 0). */
    public function summary(int $studentId, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today): array
    {
        $rows = PhoneAppUsage::query()->where('student_id', $studentId)
            ->whereBetween('usage_date', [$from->toDateString(), $to->toDateString()])->get();

        $byDay = $rows->groupBy(fn ($r) => $r->usage_date->toDateString());
        $days = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $key = $d->toDateString();
            $day = $byDay->get($key);
            $days[] = [
                'date' => $key,
                'total_minutes' => $day ? (int) $day->sum('minutes') : null,
                'categories' => $day ? $day->groupBy('category')->map(fn ($g) => (int) $g->sum('minutes'))->all() : [],
                'apps' => $day ? $day->sortByDesc('minutes')->values()->map(fn ($r) => ['package' => $r->package, 'label' => $r->label, 'minutes' => $r->minutes, 'category' => $r->category])->all() : [],
            ];
        }

        $withData = collect($days)->whereNotNull('total_minutes');
        $total = (int) $rows->sum('minutes');
        $topApps = $rows->groupBy('package')->map(fn ($g) => [
            'package' => $g->first()->package, 'label' => $g->sortByDesc('usage_date')->first()->label, 'category' => $g->first()->category,
            'minutes' => (int) $g->sum('minutes'), 'days' => $g->pluck('usage_date')->map(fn ($x) => $x->toDateString())->unique()->count(),
        ])->sortByDesc('minutes')->values()->all();

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days,
            'days_with_data' => $withData->count(), 'total_minutes' => $total,
            'avg_per_logged_day' => $withData->count() ? (int) round($total / $withData->count()) : null,
            'top_apps' => $topApps,
            'categories' => $rows->groupBy('category')->map(fn ($g) => (int) $g->sum('minutes'))->sortDesc()->all(),
            'last_sync' => $rows->max('updated_at')?->toIso8601String(),
            'today' => collect($days)->firstWhere('date', $today->toDateString()),
        ];
    }
}
