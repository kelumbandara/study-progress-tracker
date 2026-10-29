<?php

namespace App\Services;

use App\Models\ExamPaper;
use App\Models\PaperAttempt;
use App\Models\QuestionAttempt;
use App\Models\StudyDailyLog;
use App\Models\StudySetting;
use App\Models\Subject;
use App\Models\SyllabusTopic;
use App\Support\Category;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Score, time and consistency calculations. Scores are always shown with the number of
 * attempts behind them, and first attempts are kept apart from retries.
 */
class AnalyticsService
{
    /** Attempts needed before a score-based statement is made. */
    public const MIN_ATTEMPTS = 5;

    public function __construct(private SyllabusStatsService $syllabus, private MistakeService $mistakes)
    {
    }

    public function attempts(int $studentId, ?string $from = null, ?string $to = null, ?int $subjectId = null): Collection
    {
        return QuestionAttempt::query()->with(['question.paper', 'question.topics'])
            ->where('student_id', $studentId)
            ->when($from, fn ($q) => $q->whereDate('attempt_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('attempt_date', '<=', $to))
            ->when($subjectId, fn ($q) => $q->whereHas('question.paper', fn ($p) => $p->where('subject_id', $subjectId)))
            ->orderBy('attempt_date')->orderBy('id')->get();
    }

    /** Mean of per-attempt percentages (each attempt counts equally), or null when empty. */
    public static function meanPct(Collection $attempts): ?float
    {
        return $attempts->isEmpty() ? null : round($attempts->avg(fn ($a) => $a->percent), 1);
    }

    /** Marks-weighted score: total earned / total available. */
    public static function weightedPct(Collection $attempts): ?float
    {
        $max = (float) $attempts->sum('max_marks');

        return $max > 0 ? round((float) $attempts->sum('marks_earned') / $max * 100, 1) : null;
    }

    /** @param Collection|null $queue the mistake queue, passed in so one page computes it only once */
    public function subjectSummary(Subject $subject, int $studentId, ?string $from = null, ?string $to = null, ?Collection $queue = null): array
    {
        $attempts = $this->attempts($studentId, $from, $to, $subject->id);
        $first = $attempts->where('attempt_no', 1);
        $retry = $attempts->where('attempt_no', '>', 1);

        $topics = SyllabusTopic::query()->whereHas('unit', fn ($q) => $q->where('subject_id', $subject->id))->get();
        $syllabus = $this->syllabus->summarize($topics, $studentId);

        $papers = $this->paperPerformance($studentId, $subject->id);
        $timed = collect($papers['attempts'])->where('timed', true)->last();
        $latestPaper = collect($papers['attempts'])->last();

        $queue = ($queue ?? $this->mistakes->queue($studentId))->filter(fn ($r) => $r['question']->paper->subject_id === $subject->id);

        return [
            'subject' => $subject,
            'syllabus' => $syllabus,
            'attempts' => $attempts->count(),
            'questions_attempted' => $attempts->pluck('question_id')->unique()->count(),
            'marks_earned' => (float) $attempts->sum('marks_earned'),
            'marks_available' => (float) $attempts->sum('max_marks'),
            'weighted_pct' => self::weightedPct($attempts),
            'first' => ['n' => $first->count(), 'pct' => self::meanPct($first)],
            'retry' => ['n' => $retry->count(), 'pct' => self::meanPct($retry)],
            'independent' => ['n' => $attempts->where('independent', true)->count(), 'pct' => self::meanPct($attempts->where('independent', true))],
            'assisted' => ['n' => $attempts->where('independent', false)->count(), 'pct' => self::meanPct($attempts->where('independent', false))],
            'latest_timed' => $timed,
            'latest_paper' => $latestPaper,
            'paper_count' => $papers['paper_count'],
            'paper_minutes' => $papers['minutes'],
            'weak_topics' => $this->weakTopics($attempts, $subject->weak_score_pct),
            'revision_backlog' => $queue->count(),
            'repeated' => $queue->where('repeated', true)->count(),
            'trend' => $this->trend($attempts),
            'enough_data' => $attempts->count() >= self::MIN_ATTEMPTS,
        ];
    }

    /** Last 5 attempts versus the 5 before them; needs at least 6 attempts. */
    public function trend(Collection $attempts): ?array
    {
        if ($attempts->count() < 6) {
            return null;
        }
        $recent = $attempts->slice(-5);
        $previous = $attempts->slice(max(0, $attempts->count() - 10), min(5, $attempts->count() - 5));

        return ['recent' => self::meanPct($recent), 'previous' => self::meanPct($previous),
            'delta' => round(self::meanPct($recent) - self::meanPct($previous), 1), 'n_previous' => $previous->count()];
    }

    /** Topics whose latest attempt per linked question averages below the subject's weak threshold. */
    public function weakTopics(Collection $attempts, int $weakPct, int $limit = 5): array
    {
        $latest = $attempts->groupBy('question_id')->map(fn ($g) => $g->sortBy('attempt_no')->last());
        $byTopic = [];
        foreach ($latest as $a) {
            foreach ($a->question->topics as $t) {
                $byTopic[$t->id]['name'] = $t->name;
                $byTopic[$t->id]['pcts'][] = $a->percent;
            }
        }
        $rows = collect($byTopic)->map(fn ($r) => ['name' => $r['name'], 'n' => count($r['pcts']), 'pct' => round(array_sum($r['pcts']) / count($r['pcts']), 1)])
            ->sortBy('pct')->values();

        return $rows->filter(fn ($r) => $r['pct'] < $weakPct)->take($limit)->all();
    }

    public function topicStrength(Collection $attempts): Collection
    {
        $by = [];
        foreach ($attempts as $a) {
            foreach ($a->question->topics as $t) {
                $by[$t->id]['name'] = $t->name;
                $by[$t->id]['pcts'][] = $a->percent;
            }
        }

        return collect($by)->map(fn ($r) => ['name' => $r['name'], 'n' => count($r['pcts']), 'pct' => round(array_sum($r['pcts']) / count($r['pcts']), 1)])->sortByDesc('pct')->values();
    }

    /**
     * Paper results. Each paper's FIRST attempt is its result for trends; later attempts of
     * the same paper are retries and are not treated as new examination results.
     */
    public function paperPerformance(int $studentId, ?int $subjectId = null): array
    {
        $attempts = PaperAttempt::query()->with(['paper.subject', 'questionAttempts'])->where('student_id', $studentId)
            ->when($subjectId, fn ($q) => $q->whereHas('paper', fn ($p) => $p->where('subject_id', $subjectId)))
            ->orderBy('attempt_date')->orderBy('id')->get();

        $rows = $attempts->map(function (PaperAttempt $a) {
            $t = $a->totals();
            // The paper's time is authoritative; linked question minutes are only a fallback (never added on top).
            $minutes = $a->worked_minutes ?? ($a->questionAttempts->whereNotNull('worked_minutes')->sum('worked_minutes') ?: null);

            return [
                'id' => $a->id, 'paper_id' => $a->paper_id, 'subject' => $a->paper->subject->name, 'year' => $a->paper->exam_year,
                'label' => __($a->paper->subject->name) . ' ' . $a->paper->label, 'date' => $a->attempt_date, 'attempt_no' => $a->attempt_no,
                'pct' => ($t['earned'] !== null && $t['max']) ? round($t['earned'] / $t['max'] * 100, 1) : null,
                'earned' => $t['earned'], 'max' => $t['max'], 'minutes' => $minutes, 'official' => $a->paper->duration_minutes,
                'timed' => $a->timed, 'independent' => $a->independent,
            ];
        });
        $firsts = $rows->groupBy('paper_id')->map(fn ($g) => $g->first())->sortBy('date')->values();

        $papersAttempted = $rows->pluck('paper_id')->unique();
        $byPaper = $papersAttempted->map(function ($pid) use ($rows) {
            $g = $rows->where('paper_id', $pid)->values();
            $scored = $g->whereNotNull('pct');

            return ['label' => $g[0]['label'], 'subject' => $g[0]['subject'], 'year' => $g[0]['year'], 'attempts' => $g->count(), 'first_pct' => $g[0]['pct'],
                'latest_pct' => $scored->last()['pct'] ?? null, 'best_pct' => $scored->max('pct'), 'first_minutes' => $g[0]['minutes'], 'official' => $g[0]['official']];
        })->values();

        $scoredFirsts = $firsts->whereNotNull('pct');
        $trend = null;
        if ($scoredFirsts->count() >= 2) {
            $trend = round($scoredFirsts->last()['pct'] - $scoredFirsts->slice(-2, 1)->first()['pct'], 1);
        }

        return [
            'attempts' => $rows->all(), 'first_results' => $firsts->all(), 'by_paper' => $byPaper->all(),
            'paper_count' => $papersAttempted->count(), 'minutes' => (int) $rows->sum('minutes'),
            'first_avg' => $scoredFirsts->isEmpty() ? null : round($scoredFirsts->avg('pct'), 1), 'first_n' => $scoredFirsts->count(), 'trend' => $trend,
        ];
    }

    public function questionTimeChange(Collection $attempts): ?array
    {
        $deltas = $attempts->groupBy('question_id')->map(function ($g) {
            $timed = $g->sortBy('attempt_no')->whereNotNull('worked_minutes')->values();

            return $timed->count() >= 2 ? $timed->last()->worked_minutes - $timed->first()->worked_minutes : null;
        })->filter(fn ($v) => $v !== null);

        return $deltas->isEmpty() ? null : ['questions' => $deltas->count(), 'avg_change' => round($deltas->avg(), 1)];
    }

    /** Daily/weekly hours and missing logs. A missing day is never treated as zero. */
    public function consistency(int $studentId, StudySetting $settings, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $tz = $settings->timezone;
        $today = CarbonImmutable::now($tz)->startOfDay();
        $start = $from ?? CarbonImmutable::parse($settings->study_start->toDateString(), $tz);
        $end = $to ?? CarbonImmutable::parse($settings->study_end->toDateString(), $tz);
        $end = $end->gt($today) ? $today : $end;

        $logs = StudyDailyLog::query()->where('student_id', $studentId)->whereBetween('log_date', [$start->toDateString(), $end->toDateString()])->get();
        $days = $end->gte($start) ? (int) $start->diffInDays($end) + 1 : 0;
        $logged = $logs->count();
        $total = $logs->sum(fn ($l) => $l->school_minutes + $l->tuition_minutes + $l->theory_minutes + $l->papers_minutes);
        $independent = $logs->sum(fn ($l) => $l->theory_minutes + $l->papers_minutes);

        return [
            'days_in_range' => $days, 'days_logged' => $logged, 'days_missing' => max(0, $days - $logged),
            'days_with_independent' => $logs->filter(fn ($l) => $l->theory_minutes + $l->papers_minutes > 0)->count(),
            'rest_days' => $logs->where('is_rest_day', true)->count(),
            'avg_daily_minutes' => $logged ? (int) round($total / $logged) : null,
            'avg_daily_independent' => $logged ? (int) round($independent / $logged) : null,
            'avg_weekly_minutes' => $logged ? (int) round($total / $logged * 7) : null, // typical week if logged days are typical
            'avg_weekly_independent' => $logged ? (int) round($independent / $logged * 7) : null,
        ];
    }

    /** Minutes per category for each Monday-start week that has at least one log. */
    public function weeklyHours(int $studentId, ?string $from = null, ?string $to = null): array
    {
        $logs = StudyDailyLog::query()->where('student_id', $studentId)
            ->when($from, fn ($q) => $q->whereDate('log_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('log_date', '<=', $to))
            ->orderBy('log_date')->get();

        $weeks = [];
        foreach ($logs as $l) {
            $key = CarbonImmutable::parse($l->log_date)->startOfWeek()->toDateString();
            foreach (Category::keys() as $k) {
                $weeks[$key][$k] = ($weeks[$key][$k] ?? 0) + $l->{Category::column($k)};
            }
        }
        ksort($weeks);

        return $weeks;
    }

    /** Weekly mean attempt score per subject, for the trend chart. @return array<string,array<string,float>> */
    public function scoreTrend(int $studentId): array
    {
        $out = [];
        foreach ($this->attempts($studentId)->groupBy(fn ($a) => $a->question->paper->subject_id) as $subjectId => $rows) {
            $name = Subject::query()->whereKey($subjectId)->value('name');
            foreach ($rows->groupBy(fn ($a) => CarbonImmutable::parse($a->attempt_date)->startOfWeek()->toDateString()) as $week => $g) {
                $out[$name][$week] = self::meanPct($g);
            }
            ksort($out[$name]);
        }

        return $out;
    }
}
