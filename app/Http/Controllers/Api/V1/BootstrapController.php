<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use App\Models\QuestionAttempt;
use App\Models\Subject;
use App\Models\SyllabusTopic;
use App\Support\ApiPresenter;
use App\Support\Category;
use Illuminate\Http\JsonResponse;

/** Everything the app needs once at start-up: study dates, "today" from the server, subjects and the fixed option lists. */
class BootstrapController extends Controller
{
    use ResolvesStudent;

    private function options(array $labels): array
    {
        return collect($labels)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values()->all();
    }

    public function show(): JsonResponse
    {
        $s = $this->settings();

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'today' => $this->today()->toDateString(),
            'timezone' => $s->timezone,
            'study_period' => ['start' => $s->study_start->toDateString(), 'end' => $s->study_end->toDateString(), 'exam_date' => $s->exam_date?->toDateString()],
            'paper_years' => ['from' => (int) $s->paper_year_from, 'to' => (int) $s->paper_year_to],
            'subjects' => Subject::query()->orderBy('sort_order')->get()->map(fn ($x) => ApiPresenter::subject($x))->all(),
            'options' => [
                'categories' => $this->options(Category::LABELS),
                'topic_statuses' => $this->options(SyllabusTopic::STATUSES),
                'attempt_results' => $this->options(QuestionAttempt::RESULTS),
                'mistake_categories' => $this->options(QuestionAttempt::MISTAKES),
            ],
            'rules' => [
                'mastery_pct' => (int) config('tracker.mastery_pct'),
                'min_attempts_for_advice' => \App\Services\AnalyticsService::MIN_ATTEMPTS,
                'max_minutes_per_day' => 1440,
            ],
        ]);
    }
}
