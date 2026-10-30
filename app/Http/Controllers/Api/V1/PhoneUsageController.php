<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use App\Services\PhoneUsageService;
use App\Support\ApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Phone use per app (minutes only). The phone sends the complete day; nothing about content is ever sent or stored. */
class PhoneUsageController extends Controller
{
    use ResolvesStudent;

    private const MAX_DAYS_BACK = 31;

    public function show(Request $request, PhoneUsageService $usage): JsonResponse
    {
        $d = $request->validate(['days' => ['nullable', 'integer', 'between:1,90']]);
        $today = $this->today();
        $days = (int) ($d['days'] ?? 7);

        return response()->json(ApiPresenter::plain($usage->summary($this->student()->id, $today->subDays($days - 1), $today, $today)));
    }

    public function store(Request $request, string $date, PhoneUsageService $usage): JsonResponse
    {
        $today = $this->today();
        $day = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $date, $this->settings()->timezone);
        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['date' => 'Use a real date in the form YYYY-MM-DD.']);
        }
        if ($day->gt($today)) {
            throw ValidationException::withMessages(['date' => 'You cannot send phone use for a future date.']);
        }
        if ($day->lt($today->subDays(self::MAX_DAYS_BACK))) {
            throw ValidationException::withMessages(['date' => 'Phone use can only be sent for the last ' . self::MAX_DAYS_BACK . ' days.']);
        }

        $max = (int) config('phone_usage.max_apps_per_day');
        $v = $request->validate([
            'apps' => ['present', 'array', 'max:' . $max],
            'apps.*.package' => ['required', 'string', 'max:190', 'regex:/^[A-Za-z0-9._]+$/'],
            'apps.*.label' => ['required', 'string', 'max:120'],
            'apps.*.minutes' => ['required', 'integer', 'between:0,1440'],
        ]);
        // Names are shown on the website: remove control characters so nothing odd can be displayed.
        $apps = array_map(fn ($a) => ['package' => $a['package'], 'label' => trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $a['label'])) ?: $a['package'], 'minutes' => (int) $a['minutes']], $v['apps']);

        $stored = $usage->storeDay($this->student()->id, $date, $apps);
        $summary = $usage->summary($this->student()->id, $day, $day, $today);

        return response()->json([
            'date' => $date, 'apps_stored' => $stored, 'total_minutes' => $summary['days'][0]['total_minutes'], 'synced_at' => now()->toIso8601String(),
        ]);
    }

    /** "Stop and erase": deletes everything the phone ever sent. */
    public function destroy(PhoneUsageService $usage): JsonResponse
    {
        return response()->json(['deleted_rows' => $usage->clear($this->student()->id)]);
    }
}
