<?php

namespace App\Services;

use App\Models\Subject;
use App\Support\Duration;

/**
 * Rule-based advice from recorded data only. Where there is too little data it says so,
 * and it never predicts a grade.
 */
class RecommendationService
{
    public const NOT_ENOUGH = 'Not enough data yet.';

    /**
     * @param array $summaries subject id => AnalyticsService::subjectSummary()
     * @param array $categories TargetProgressService::categories()
     * @return array<int,array{level:string,text:string}>
     */
    public function build(array $summaries, array $categories, array $period): array
    {
        $out = [];

        foreach ($summaries as $s) {
            /** @var Subject $subject */
            $subject = $s['subject'];
            $name = $subject->name;
            $weak = $subject->weak_score_pct;

            if (! $s['enough_data']) {
                $out[] = ['level' => 'info', 'text' => __(':name: Not enough data yet. (:n of :min question attempts recorded.)', ['name' => __($name), 'n' => $s['attempts'], 'min' => AnalyticsService::MIN_ATTEMPTS])];
            } else {
                $avg = $s['weighted_pct'];
                $topics = collect($s['weak_topics'])->pluck('name')->take(3)->join(', ');

                if ($subject->priority === 'high' && $avg < $weak) {
                    $out[] = ['level' => 'warn', 'text' => __(':name (high priority) is scoring :avg% across :n attempts, below your :weak% weak threshold. Prioritise its weak topics:topics', ['name' => __($name), 'avg' => $avg, 'n' => $s['attempts'], 'weak' => $weak, 'topics' => $topics ? ': ' . $topics . '.' : '.'])];
                } elseif ($avg < $weak) {
                    $out[] = ['level' => 'warn', 'text' => __(':name is scoring :avg%, below your :weak% weak threshold:rest', ['name' => __($name), 'avg' => $avg, 'weak' => $weak, 'rest' => $topics ? '. ' . __('Weakest topics: :topics.', ['topics' => $topics]) : '.'])];
                }

                if ($avg < $weak && ($s['syllabus']['theory_pct'] ?? 0) >= 60) {
                    $out[] = ['level' => 'warn', 'text' => __(':name: theory is :pct% complete but question scores are still low. Do more written question practice and review errors.', ['name' => __($name), 'pct' => $s['syllabus']['theory_pct']])];
                }
                if ($subject->priority === 'maintain' && $avg >= 75) {
                    $out[] = ['level' => 'good', 'text' => __(':name is consistently strong (:avg% over :n attempts). Maintain it, and protect enough time for the subjects that need more.', ['name' => __($name), 'avg' => $avg, 'n' => $s['attempts']])];
                }
            }

            if ($s['repeated'] > 0) {
                $out[] = ['level' => 'warn', 'text' => __(':name: :n question(s) have scored low at least twice. They are in the Review Mistakes queue.', ['name' => __($name), 'n' => $s['repeated']])];
            }
        }

        // Theory vs written practice, in recorded hours.
        $theory = $categories['theory']['actual'];
        $papers = $categories['papers']['actual'];
        if ($theory + $papers < 600) {
            $out[] = ['level' => 'info', 'text' => __('Theory vs practice balance: Not enough data yet. (fewer than 10 independent-study hours logged.)')];
        } elseif ($papers < $theory * 0.5) {
            $out[] = ['level' => 'warn', 'text' => __('Theory time (:theory) is much higher than written-paper time (:papers). Add more written practice.', ['theory' => Duration::format($theory), 'papers' => Duration::format($papers)])];
        }

        // Time plan.
        foreach ($categories as $c) {
            if (! $c['configured']) {
                continue;
            }
            if ($c['status'] === 'behind' && $c['required_weekly'] !== null) {
                $extra = max(0, (int) round($c['required_weekly'] - $c['weekly_plan']));
                $out[] = ['level' => 'warn', 'text' => __(':label is behind plan. About :need per week is needed from now, which is :extra more per week than the original plan.', ['label' => __($c['label']), 'need' => Duration::format((int) round($c['required_weekly'])), 'extra' => Duration::format($extra)])];
            }
            if (! empty($c['flags']['well_above']) || ! empty($c['flags']['end_passed'])) {
                foreach ($c['warnings'] as $w) {
                    if ($w !== __('Behind the linear plan by :time.', ['time' => Duration::format((int) $c['behind_by'])])) {
                        $out[] = ['level' => 'danger', 'text' => __(':label: :warning Review the target in Settings; it has not been changed automatically.', ['label' => __($c['label']), 'warning' => $w])];
                    }
                }
            }
        }

        if (! array_filter($categories, fn ($c) => $c['configured'])) {
            $out[] = ['level' => 'info', 'text' => __('Time plan: Not enough data yet. Planned hours are not configured yet (Settings).')];
        }

        return $out;
    }
}
