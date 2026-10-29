<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use App\Models\StudyDailyLog;
use App\Services\DailyLogService;
use App\Services\TargetProgressService;
use App\Support\ApiPresenter;
use App\Support\Category;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DailyLogController extends Controller
{
    use ResolvesStudent;

    /** Rejects impossible dates such as 2026-02-30 (which would otherwise silently roll over to March). */
    private function parseDate(string $date): CarbonImmutable
    {
        $d = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['date' => 'Use a real date in the form YYYY-MM-DD.']);
        }

        return $d;
    }

    private function payload(string $date, DailyLogService $service, TargetProgressService $progress): array
    {
        $student = $this->student();
        $settings = $this->settings();
        $today = $this->today();
        $end = $today->lt($settings->study_end) ? $today : CarbonImmutable::parse($settings->study_end->toDateString(), $settings->timezone);
        $start = CarbonImmutable::parse($settings->study_start->toDateString(), $settings->timezone);
        $daysSoFar = $end->gte($start) ? (int) $start->diffInDays($end) + 1 : 0;
        $logged = StudyDailyLog::query()->where('student_id', $student->id)->whereBetween('log_date', [$start->toDateString(), $end->toDateString()])->count();

        return [
            'log' => ApiPresenter::dailyLog(StudyDailyLog::query()->where(['student_id' => $student->id, 'log_date' => $date])->first(), $date),
            // Detail minutes recorded against this day that exceed the day's total (a warning, never an error).
            'warnings' => $service->allocationWarnings($student->id, $date),
            'cumulative_minutes' => $progress->actualMinutes($student->id),
            'days_so_far' => $daysSoFar, 'missing_days' => max(0, $daysSoFar - $logged), // a missing day is not a zero day
            'today' => $today->toDateString(),
        ];
    }

    public function show(string $date, DailyLogService $service, TargetProgressService $progress): JsonResponse
    {
        $this->parseDate($date);

        return response()->json($this->payload($date, $service, $progress));
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'between:1,60']]);
        $p = StudyDailyLog::query()->where('student_id', $this->student()->id)->orderByDesc('log_date')->paginate((int) $request->query('per_page', 15));

        return response()->json([
            'data' => $p->getCollection()->map(fn ($l) => ApiPresenter::dailyLog($l, $l->log_date->toDateString()))->all(),
            'meta' => ['page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
        ]);
    }

    /** Saves the day (creates it, or edits it with an audit trail). All four durations are required so nothing is zeroed by accident. */
    public function upsert(Request $request, string $date, DailyLogService $service, TargetProgressService $progress): JsonResponse
    {
        $day = $this->parseDate($date);
        if ($day->gt($this->today())) {
            throw ValidationException::withMessages(['date' => 'You cannot log a future date.']);
        }

        $rules = ['minutes' => ['required', 'array'], 'is_rest_day' => ['nullable', 'boolean'], 'rating' => ['nullable', 'integer', 'between:1,5'], 'notes' => ['nullable', 'string', 'max:2000']];
        foreach (Category::keys() as $k) {
            $rules["minutes.{$k}"] = ['required', 'integer', 'between:0,1440'];
        }
        $d = $request->validate($rules);

        $minutes = collect(Category::keys())->mapWithKeys(fn ($k) => [$k => (int) $d['minutes'][$k]])->all();
        if (array_sum($minutes) > 1440) {
            throw ValidationException::withMessages(['minutes' => 'The four durations add up to more than 24 hours.']);
        }
        $service->save($this->student()->id, $date, $minutes, ['is_rest_day' => $request->boolean('is_rest_day'), 'rating' => $d['rating'] ?? null, 'notes' => $d['notes'] ?? null]);

        return response()->json($this->payload($date, $service, $progress));
    }
}
