<?php

namespace App\Http\Controllers;

use App\Models\StudentProfile;
use App\Models\StudyDailyLog;
use App\Models\StudySetting;
use App\Models\Subject;
use App\Services\AnalyticsService;
use App\Services\MistakeService;
use App\Services\PhoneUsageService;
use App\Services\RecommendationService;
use App\Services\StudyPeriodService;
use App\Services\TargetProgressService;
use Carbon\CarbonImmutable;

class DashboardController extends Controller
{
    public function index(StudyPeriodService $periods, TargetProgressService $progress, AnalyticsService $analytics, RecommendationService $recommendations, MistakeService $mistakes, PhoneUsageService $phoneUsage)
    {
        $student = StudentProfile::current();
        $settings = StudySetting::query()->where('student_id', $student->id)->firstOrFail();
        $categories = $progress->categories($student, $settings);
        $period = $periods->summarize($settings);
        $today = CarbonImmutable::now($settings->timezone)->toDateString();

        $queue = $mistakes->queue($student->id); // computed once and shared by every subject card
        $summaries = Subject::query()->orderBy('sort_order')->get()
            ->mapWithKeys(fn ($s) => [$s->id => $analytics->subjectSummary($s, $student->id, null, null, $queue)])->all();

        return view('dashboard', [
            'sampleLoaded' => app(\App\Services\SampleData::class)->exists(),
            'todayLogged' => StudyDailyLog::query()->where(['student_id' => $student->id, 'log_date' => $today])->exists(),
            'period' => $period,
            'categories' => $categories,
            'totals' => $progress->totals($categories),
            'summaries' => $summaries,
            'consistency' => $analytics->consistency($student->id, $settings),
            'recommendations' => $recommendations->build($summaries, $categories, $period),
            'chartCategories' => collect($categories)->map(fn ($c) => [
                'label' => __($c['label']),
                'planned' => $c['planned'] !== null ? round($c['planned'] / 60, 1) : null,
                'actual' => round($c['actual'] / 60, 1),
            ])->values(),
            'phone' => $phoneUsage->summary($student->id, CarbonImmutable::parse($today, $settings->timezone)->subDays(6), CarbonImmutable::parse($today, $settings->timezone), CarbonImmutable::parse($today, $settings->timezone)),
            'weekly' => $analytics->weeklyHours($student->id),
            'scoreTrend' => $analytics->scoreTrend($student->id),
        ]);
    }
}
