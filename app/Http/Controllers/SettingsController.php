<?php

namespace App\Http\Controllers;

use App\Models\StudentProfile;
use App\Models\StudyDailyLog;
use App\Models\StudySetting;
use App\Models\StudyTargetHistory;
use App\Models\Subject;
use App\Services\SettingsService;
use App\Services\StudyPeriodService;
use App\Services\TargetProgressService;
use App\Support\Category;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function edit(StudyPeriodService $periods, TargetProgressService $progress)
    {
        $student = StudentProfile::current();
        $settings = StudySetting::query()->where('student_id', $student->id)->firstOrFail();
        $categories = $progress->categories($student, $settings);

        $outside = StudyDailyLog::query()->where('student_id', $student->id)
            ->where(fn ($q) => $q->where('log_date', '<', $settings->study_start)->orWhere('log_date', '>', $settings->study_end))
            ->count();

        return view('settings.edit', [
            'settings' => $settings,
            'period' => $periods->summarize($settings),
            'categories' => $categories,
            'subjects' => Subject::query()->orderBy('sort_order')->get(),
            'history' => StudyTargetHistory::query()->where('student_id', $student->id)->latest('changed_at')->latest('id')->limit(20)->get(),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'logsOutsidePeriod' => $outside,
            'sample' => ['exists' => app(\App\Services\SampleData::class)->exists(), 'counts' => app(\App\Services\SampleData::class)->counts()],
        ]);
    }

    public function update(Request $request, SettingsService $service)
    {
        $data = $request->validate([
            'study_start' => ['required', 'date'],
            'study_end' => ['required', 'date', 'after:study_start'],
            'exam_date' => ['nullable', 'date', 'after_or_equal:study_start'],
            'timezone' => ['required', Rule::in(\DateTimeZone::listIdentifiers())],
            'paper_year_from' => ['required', 'integer', 'between:1990,2100'],
            'paper_year_to' => ['required', 'integer', 'between:1990,2100', 'gte:paper_year_from'],
            'hours' => ['array'],
            'hours.*' => ['nullable', 'numeric', 'min:0', 'max:20000', 'decimal:0,2'],
            'reason' => ['nullable', 'string', 'max:255'],
            'subjects' => ['array'],
            'subjects.*.priority' => ['required', Rule::in(array_keys(Subject::PRIORITIES))],
            'subjects.*.weak_score_pct' => ['required', 'integer', 'between:1,100'],
            'subjects.*.slow_minutes_per_mark' => ['nullable', 'numeric', 'min:0', 'max:999'],
        ], [
            'hours.*.min' => 'Planned hours cannot be negative.',
            'hours.*.numeric' => 'Planned hours must be a number, for example 125.5.',
            'hours.*.decimal' => 'Use at most two decimal places for planned hours.',
            'study_end.after' => 'The end date must be after the start date.',
        ]);

        // Only accept category keys and subject ids we know about.
        $data['hours'] = array_intersect_key($data['hours'] ?? [], Category::LABELS);
        $data['subjects'] = array_intersect_key($data['subjects'] ?? [], Subject::query()->pluck('id', 'id')->all());

        $service->save(StudentProfile::current(), $data);

        return redirect()->route('settings.edit')->with('status', __('Settings saved.'));
    }
}
