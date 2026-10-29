<?php

namespace App\Services;

use App\Models\MistakeReview;
use App\Models\PaperAttempt;
use App\Models\StudentProfile;
use App\Models\StudyDailyLog;
use App\Models\StudySetting;
use App\Models\Subject;
use App\Models\TopicStatusHistory;
use App\Support\Category;
use App\Support\Duration;
use Carbon\CarbonImmutable;

class ReportService
{
    public function __construct(
        private AnalyticsService $analytics,
        private TargetProgressService $progress,
        private StudyPeriodService $periods,
        private MistakeService $mistakes,
        private RecommendationService $recommendations,
    ) {
    }

    public function build(StudentProfile $student, StudySetting $settings, string $type, CarbonImmutable $from, CarbonImmutable $to, ?int $subjectId = null): array
    {
        $period = $this->periods->summarize($settings);
        $categories = $this->progress->categories($student, $settings);
        $f = $from->toDateString();
        $t = $to->toDateString();
        $attempts = $this->analytics->attempts($student->id, $f, $t, $subjectId);
        $subjects = Subject::query()->when($subjectId, fn ($q) => $q->whereKey($subjectId))->orderBy('sort_order')->get();
        $queue = $this->mistakes->queue($student->id); // once per report

        $report = [
            'type' => $type, 'from' => $from, 'to' => $to,
            'subject' => $subjectId ? $subjects->first()?->name : 'All subjects',
            'hours' => $this->hours($student->id, $categories, $period, $from, $to),
            'consistency' => $this->analytics->consistency($student->id, $settings, $from, $to),
            'questions' => [
                'attempts' => $attempts->count(), 'distinct' => $attempts->pluck('question_id')->unique()->count(),
                'earned' => (float) $attempts->sum('marks_earned'), 'available' => (float) $attempts->sum('max_marks'),
                'weighted_pct' => AnalyticsService::weightedPct($attempts),
                'first' => ['n' => $attempts->where('attempt_no', 1)->count(), 'pct' => AnalyticsService::meanPct($attempts->where('attempt_no', 1))],
                'retry' => ['n' => $attempts->where('attempt_no', '>', 1)->count(), 'pct' => AnalyticsService::meanPct($attempts->where('attempt_no', '>', 1))],
            ],
            'topics_completed' => $this->topicsCompleted($f, $t, $subjectId),
            'timed_papers' => $this->timedPapers($student->id, $f, $t, $subjectId),
            'strong' => $this->analytics->topicStrength($attempts)->take(3)->all(),
            'weak' => $this->analytics->topicStrength($attempts)->sortBy('pct')->take(3)->values()->all(),
            'reviews' => $this->reviews($student->id, $f, $t, $subjectId),
            'outstanding' => $queue->filter(fn ($r) => ! $subjectId || $r['question']->paper->subject_id === $subjectId)->count(),
        ];

        $summaries = $subjects->mapWithKeys(fn ($s) => [$s->id => $this->analytics->subjectSummary($s, $student->id, null, null, $queue)])->all();
        $report['recommendations'] = $this->recommendations->build($summaries, $categories, $period);

        if ($type === 'monthly') {
            $report['monthly'] = [
                'coverage' => collect($summaries)->map(fn ($s) => ['subject' => $s['subject']->name, 'theory_pct' => $s['syllabus']['theory_pct'], 'mastered_pct' => $s['syllabus']['mastered_pct'], 'total' => $s['syllabus']['total']])->values()->all(),
                'trend' => $subjects->map(fn ($s) => ['subject' => $s->name, 'weeks' => $this->weeklyScores($attempts->filter(fn ($a) => $a->question->paper->subject_id === $s->id))])->all(),
                'papers' => $this->analytics->paperPerformance($student->id, $subjectId),
                'time_change' => $this->analytics->questionTimeChange($attempts),
                'missed' => collect($categories)->filter(fn ($c) => $c['configured'] && in_array($c['status'], ['behind'], true))->map(fn ($c) => ['label' => $c['label'], 'behind_by' => $c['behind_by']])->values()->all(),
                'forecast' => collect($categories)->filter(fn ($c) => $c['configured'])->map(fn ($c) => [
                    'label' => $c['label'], 'remaining' => $c['remaining'], 'required_weekly' => $c['required_weekly'] !== null ? (int) round($c['required_weekly']) : null,
                    'weekly_plan' => (int) round($c['weekly_plan']), 'status' => $c['status'],
                ])->values()->all(),
                'remaining_days' => $period['remaining_days'],
            ];
        }

        return $report;
    }

    private function hours(int $studentId, array $categories, array $period, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $logs = StudyDailyLog::query()->where('student_id', $studentId)->whereBetween('log_date', [$from->toDateString(), $to->toDateString()])->get();
        $cumulative = StudyDailyLog::query()->where('student_id', $studentId)->whereDate('log_date', '<=', $to->toDateString())
            ->selectRaw('COALESCE(SUM(school_minutes),0) school, COALESCE(SUM(tuition_minutes),0) tuition, COALESCE(SUM(theory_minutes),0) theory, COALESCE(SUM(papers_minutes),0) papers')->first();

        $overlapStart = $from->gt($period['start']) ? $from : $period['start'];
        $overlapEnd = $to->lt($period['end']) ? $to : $period['end'];
        $overlap = $overlapEnd->gte($overlapStart) ? (int) $overlapStart->diffInDays($overlapEnd) + 1 : 0;

        $rows = [];
        foreach (Category::LABELS as $k => $label) {
            $planned = $categories[$k]['planned'];
            $rows[$k] = [
                'label' => $label,
                'planned_range' => $planned ? (int) round($planned * $overlap / max(1, $period['total_days'])) : null,
                'actual_range' => (int) $logs->sum(Category::column($k)),
                'cumulative' => (int) $cumulative->{$k},
                'planned_total' => $planned,
            ];
        }

        return ['rows' => $rows, 'logged_days' => $logs->count()];
    }

    private function topicsCompleted(string $from, string $to, ?int $subjectId): array
    {
        return TopicStatusHistory::query()->with('topic.unit.subject')->where('to_status', 'theory_completed')
            ->where('changed_at', '>=', $from . ' 00:00:00')->where('changed_at', '<=', $to . ' 23:59:59')
            ->when($subjectId, fn ($q) => $q->whereHas('topic.unit', fn ($u) => $u->where('subject_id', $subjectId)))
            ->get()->unique('topic_id')->map(fn ($h) => __($h->topic->unit->subject->name) . ': ' . $h->topic->name)->values()->all();
    }

    private function timedPapers(int $studentId, string $from, string $to, ?int $subjectId): array
    {
        return PaperAttempt::query()->with(['paper.subject', 'questionAttempts'])->where(['student_id' => $studentId, 'timed' => true])
            ->whereBetween('attempt_date', [$from, $to])
            ->when($subjectId, fn ($q) => $q->whereHas('paper', fn ($p) => $p->where('subject_id', $subjectId)))
            ->orderBy('attempt_date')->get()->map(function ($a) {
                $t = $a->totals();

                return ['label' => __($a->paper->subject->name) . ' ' . $a->paper->label, 'date' => $a->attempt_date->translatedFormat('d M Y'),
                    'pct' => ($t['earned'] !== null && $t['max']) ? round($t['earned'] / $t['max'] * 100, 1) : null, 'attempt_no' => $a->attempt_no];
            })->all();
    }

    private function reviews(int $studentId, string $from, string $to, ?int $subjectId): array
    {
        $r = MistakeReview::query()->where('student_id', $studentId)->whereBetween('reviewed_on', [$from, $to])
            ->when($subjectId, fn ($q) => $q->whereHas('question.paper', fn ($p) => $p->where('subject_id', $subjectId)))->get();

        return ['reviewed' => $r->count(), 'theory' => $r->where('theory_reviewed', true)->count(), 'understood' => $r->where('solution_understood', true)->count(),
            'reattempted' => $r->where('reattempted', true)->count(), 'mastered' => $r->where('mastered_independently', true)->count()];
    }

    private function weeklyScores($attempts): array
    {
        return $attempts->groupBy(fn ($a) => CarbonImmutable::parse($a->attempt_date)->startOfWeek()->format('d M'))
            ->map(fn ($g) => ['pct' => AnalyticsService::meanPct($g), 'n' => $g->count()])->all();
    }

    /** Flat rows for CSV/Excel: section, item, value, detail. */
    public function rows(array $r): array
    {
        $h = fn (?int $m) => $m === null ? 'Not configured' : Duration::format($m);
        $rows = [
            ['Report', ucfirst($r['type']) . ' report', $r['from']->format('d M Y') . ' to ' . $r['to']->format('d M Y'), $r['subject']],
            ['Section', 'Item', 'Value', 'Detail'],
        ];
        foreach ($r['hours']['rows'] as $c) {
            $rows[] = ['Hours', $c['label'], 'Actual ' . $h($c['actual_range']), 'Planned for range ' . $h($c['planned_range']) . '; cumulative ' . $h($c['cumulative'])];
        }
        $c = $r['consistency'];
        $rows[] = ['Consistency', 'Days logged / in range', "{$c['days_logged']} / {$c['days_in_range']}", "Missing {$c['days_missing']} (not counted as zero); days with independent study {$c['days_with_independent']}"];
        $q = $r['questions'];
        $rows[] = ['Questions', 'Attempts', $q['attempts'], "{$q['distinct']} distinct questions"];
        $rows[] = ['Questions', 'Marks', "{$q['earned']} / {$q['available']}", $q['weighted_pct'] !== null ? $q['weighted_pct'] . '% (marks-weighted)' : 'No attempts'];
        $rows[] = ['Questions', 'First attempts', $q['first']['pct'] !== null ? $q['first']['pct'] . '%' : '—', "n={$q['first']['n']}"];
        $rows[] = ['Questions', 'Retries', $q['retry']['pct'] !== null ? $q['retry']['pct'] . '%' : '—', "n={$q['retry']['n']}"];
        foreach ($r['topics_completed'] as $name) {
            $rows[] = ['Theory completed', $name, '', ''];
        }
        foreach ($r['timed_papers'] as $p) {
            $rows[] = ['Timed paper', $p['label'], $p['pct'] !== null ? $p['pct'] . '%' : '—', "{$p['date']} (attempt {$p['attempt_no']})"];
        }
        foreach ($r['strong'] as $s) {
            $rows[] = ['Strongest topic', $s['name'], $s['pct'] . '%', "n={$s['n']}"];
        }
        foreach ($r['weak'] as $s) {
            $rows[] = ['Weakest topic', $s['name'], $s['pct'] . '%', "n={$s['n']}"];
        }
        $v = $r['reviews'];
        $rows[] = ['Mistakes', 'Reviewed / reattempted / mastered', "{$v['reviewed']} / {$v['reattempted']} / {$v['mastered']}", "Outstanding revision items: {$r['outstanding']}"];
        foreach ($r['recommendations'] as $rec) {
            $rows[] = ['Recommendation', $rec['text'], '', ucfirst($rec['level'])];
        }
        if (isset($r['monthly'])) {
            $m = $r['monthly'];
            foreach ($m['coverage'] as $cv) {
                $rows[] = ['Coverage', $cv['subject'], ($cv['theory_pct'] ?? 0) . '% theory', ($cv['mastered_pct'] ?? 0) . '% mastered of ' . $cv['total'] . ' items'];
            }
            foreach ($m['trend'] as $tr) {
                foreach ($tr['weeks'] as $week => $w) {
                    $rows[] = ['Score trend', $tr['subject'], $w['pct'] . '%', "week of {$week}, n={$w['n']}"];
                }
            }
            foreach ($m['papers']['by_paper'] as $p) {
                $rows[] = ['Paper result', $p['label'], ($p['first_pct'] !== null ? $p['first_pct'] . '% first attempt' : 'no score'), 'latest ' . ($p['latest_pct'] ?? '—') . '%, best ' . ($p['best_pct'] ?? '—') . '%, ' . $p['attempts'] . ' attempt(s); time ' . $h($p['first_minutes']) . ' of official ' . $h($p['official'])];
            }
            $rows[] = ['Solving time', 'Average change first to latest attempt', $m['time_change'] ? $m['time_change']['avg_change'] . ' min' : 'Not enough data yet', $m['time_change'] ? $m['time_change']['questions'] . ' questions' : ''];
            foreach ($m['missed'] as $x) {
                $rows[] = ['Missed target', $x['label'], 'Behind by ' . $h($x['behind_by']), ''];
            }
            foreach ($m['forecast'] as $x) {
                $rows[] = ['Forecast', $x['label'], 'Needs ' . $h($x['required_weekly']) . ' per week', "Remaining {$h($x['remaining'])}; original plan {$h($x['weekly_plan'])} per week; {$m['remaining_days']} days left"];
            }
        }

        return $rows;
    }
}
