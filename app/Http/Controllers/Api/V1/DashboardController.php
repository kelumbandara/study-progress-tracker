<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use App\Models\StudyDailyLog;
use App\Models\Subject;
use App\Services\AnalyticsService;
use App\Services\MistakeService;
use App\Services\RecommendationService;
use App\Services\StudyPeriodService;
use App\Services\TargetProgressService;
use App\Support\ApiPresenter;
use Illuminate\Http\JsonResponse;

/** The same numbers as the web dashboard, from the same services, as plain JSON. */
class DashboardController extends Controller
{
    use ResolvesStudent;

    public function show(StudyPeriodService $periods, TargetProgressService $progress, AnalyticsService $analytics, RecommendationService $recommendations, MistakeService $mistakes): JsonResponse
    {
        $student = $this->student();
        $settings = $this->settings();
        $categories = $progress->categories($student, $settings);
        $period = $periods->summarize($settings);

        $queue = $mistakes->queue($student->id); // computed once, shared by every subject
        $summaries = Subject::query()->orderBy('sort_order')->get()
            ->map(fn ($s) => $analytics->subjectSummary($s, $student->id, null, null, $queue))->all(); // keep the Subject models for the advice rules
        $subjectsOut = array_map(fn ($sum) => ['subject' => ApiPresenter::subject($sum['subject'])] + $sum, $summaries);

        return response()->json(ApiPresenter::plain([
            'today' => $this->today(),
            'today_logged' => StudyDailyLog::query()->where(['student_id' => $student->id, 'log_date' => $this->today()->toDateString()])->exists(),
            'period' => $period,
            'categories' => array_values($categories),
            'totals' => $progress->totals($categories),
            'subjects' => $subjectsOut,
            'consistency' => $analytics->consistency($student->id, $settings),
            'recommendations' => $recommendations->build($summaries, $categories, $period),
            'weekly_hours' => $analytics->weeklyHours($student->id),
            'score_trend' => $analytics->scoreTrend($student->id),
        ]));
    }
}
