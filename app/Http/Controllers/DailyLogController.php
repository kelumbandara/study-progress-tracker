<?php

namespace App\Http\Controllers;

use App\Models\StudentProfile;
use App\Models\StudyDailyLog;
use App\Models\StudySetting;
use App\Models\AuditLog;
use App\Services\DailyLogService;
use App\Services\TargetProgressService;
use App\Support\Category;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class DailyLogController extends Controller
{
    public function index(Request $request, DailyLogService $service, TargetProgressService $progress)
    {
        $student = StudentProfile::current();
        $settings = StudySetting::query()->where('student_id', $student->id)->firstOrFail();
        $today = CarbonImmutable::now($settings->timezone)->startOfDay();
        $date = $request->query('date') && strtotime($request->query('date')) ? CarbonImmutable::parse($request->query('date'), $settings->timezone)->toDateString() : $today->toDateString();

        $log = StudyDailyLog::query()->where(['student_id' => $student->id, 'log_date' => $date])->first();
        $logs = StudyDailyLog::query()->where('student_id', $student->id)->orderByDesc('log_date')->paginate(15);

        // Missing days inside the period up to today (a missing day is not a zero day).
        $start = CarbonImmutable::parse($settings->study_start->toDateString(), $settings->timezone);
        $end = $today->lt($settings->study_end) ? $today : CarbonImmutable::parse($settings->study_end->toDateString(), $settings->timezone);
        $logged = StudyDailyLog::query()->where('student_id', $student->id)->whereBetween('log_date', [$start, $end])->count();
        $daysSoFar = $end->gte($start) ? (int) $start->diffInDays($end) + 1 : 0;

        return view('daily-log.index', [
            'date' => $date, 'today' => $today->toDateString(), 'log' => $log, 'logs' => $logs,
            'cumulative' => $progress->actualMinutes($student->id),
            'missing' => max(0, $daysSoFar - $logged), 'daysSoFar' => $daysSoFar,
            'warnings' => $service->allocationWarnings($student->id, $date),
            'audit' => $log ? AuditLog::query()->where(['entity_type' => 'study_daily_logs', 'entity_id' => $log->id])->latest('id')->limit(10)->get() : collect(),
        ]);
    }

    public function store(Request $request, DailyLogService $service)
    {
        $student = StudentProfile::current();
        $settings = StudySetting::query()->where('student_id', $student->id)->firstOrFail();
        $today = CarbonImmutable::now($settings->timezone)->toDateString();

        $rules = ['log_date' => ['required', 'date', 'before_or_equal:' . $today], 'rating' => ['nullable', 'integer', 'between:1,5'],
            'notes' => ['nullable', 'string', 'max:2000'], 'is_rest_day' => ['nullable', 'boolean']];
        foreach (Category::keys() as $k) {
            $rules["{$k}_h"] = ['nullable', 'integer', 'between:0,24'];
            $rules["{$k}_m"] = ['nullable', 'integer', 'between:0,59'];
        }
        $data = $request->validate($rules, ['log_date.before_or_equal' => __('You cannot log a future date.'), '*.between' => __('Use hours 0-24 and minutes 0-59.'), '*.integer' => __('Enter whole numbers only.')]);

        $minutes = [];
        foreach (Category::keys() as $k) {
            $minutes[$k] = (int) ($data["{$k}_h"] ?? 0) * 60 + (int) ($data["{$k}_m"] ?? 0);
        }
        if (array_sum($minutes) > 1440) {
            return back()->withInput()->withErrors(['total' => 'The four durations add up to more than 24 hours. Please check them.']);
        }

        $service->save($student->id, $data['log_date'], $minutes, [
            'is_rest_day' => $request->boolean('is_rest_day'), 'rating' => $data['rating'] ?? null, 'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('daily-log.index', ['date' => $data['log_date']])->with('status', __('Daily log saved.'));
    }
}
