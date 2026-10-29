<?php

namespace App\Support;

use App\Models\ExamPaper;
use App\Models\ExamQuestion;
use App\Models\PaperAttempt;
use App\Models\QuestionAttempt;
use App\Models\StudyDailyLog;
use App\Models\Subject;
use App\Models\SyllabusTopic;
use App\Models\TheoryStudyEntry;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;

/**
 * The phone API's response shapes, written out explicitly. Models are never dumped directly:
 * Laravel would serialise a plain date such as 2026-11-09 as a UTC timestamp, which in Colombo time
 * is a different day. Dates here are always "Y-m-d" strings in the app's own time zone.
 */
class ApiPresenter
{
    /** Recursively turns service output (Carbon, collections) into JSON-safe plain data. */
    public static function plain(mixed $v): mixed
    {
        return match (true) {
            $v instanceof CarbonInterface => $v->isStartOfDay() ? $v->toDateString() : $v->toIso8601String(),
            $v instanceof Arrayable => self::plain($v->toArray()),
            is_array($v) => array_map(fn ($x) => self::plain($x), $v),
            default => $v,
        };
    }

    private static function num(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }

    public static function subject(Subject $s): array
    {
        return [
            'id' => $s->id, 'name' => $s->name, 'priority' => $s->priority, 'priority_label' => Subject::PRIORITIES[$s->priority] ?? $s->priority,
            'weak_score_pct' => (int) $s->weak_score_pct, 'slow_minutes_per_mark' => self::num($s->slow_minutes_per_mark),
        ];
    }

    public static function dailyLog(?StudyDailyLog $l, string $date): array
    {
        // A date with no row is MISSING, which is different from a confirmed day of zeros.
        if ($l === null) {
            return ['date' => $date, 'logged' => false, 'minutes' => null, 'total_minutes' => null, 'is_rest_day' => false, 'rating' => null, 'notes' => null, 'is_sample' => false];
        }
        $m = ['school' => $l->school_minutes, 'tuition' => $l->tuition_minutes, 'theory' => $l->theory_minutes, 'papers' => $l->papers_minutes];

        return [
            'date' => $l->log_date->toDateString(), 'logged' => true, 'minutes' => $m, 'total_minutes' => array_sum($m),
            'is_rest_day' => (bool) $l->is_rest_day, 'rating' => $l->rating, 'notes' => $l->notes, 'is_sample' => (bool) $l->is_sample,
        ];
    }

    public static function topic(SyllabusTopic $t, array $practisedIds = []): array
    {
        return [
            'id' => $t->id, 'name' => $t->name, 'reference' => $t->reference, 'status' => $t->status,
            'status_label' => SyllabusTopic::STATUSES[$t->status] ?? $t->status, 'confidence' => $t->confidence,
            'theory_completed_on' => $t->theory_completed_on?->toDateString(), 'theory_minutes' => (int) $t->theory_minutes,
            'practised' => in_array($t->id, $practisedIds, true), 'is_sample' => (bool) $t->is_sample,
        ];
    }

    public static function paper(ExamPaper $p): array
    {
        return [
            'id' => $p->id, 'subject_id' => $p->subject_id, 'year' => (int) $p->exam_year, 'name' => $p->paper_name, 'label' => $p->label,
            'exam_type' => $p->exam_type, 'max_marks' => $p->max_marks, 'duration_minutes' => $p->duration_minutes,
            'paper_url' => $p->paper_url, 'marking_scheme_url' => $p->marking_scheme_url, 'availability' => $p->availability,
            'questions_count' => $p->questions_count ?? null, 'attempts_count' => $p->attempts_count ?? null, 'is_sample' => (bool) $p->is_sample,
        ];
    }

    public static function question(ExamQuestion $q): array
    {
        return [
            'id' => $q->id, 'paper_id' => $q->paper_id, 'number' => $q->question_no, 'max_marks' => self::num($q->max_marks), 'label' => $q->label,
            'reference' => $q->reference, 'notes' => $q->notes, 'attempts_count' => $q->attempts_count ?? null,
            'topic_ids' => $q->relationLoaded('topics') ? $q->topics->pluck('id')->all() : null,
        ];
    }

    public static function attempt(QuestionAttempt $a): array
    {
        $out = [
            'id' => $a->id, 'question_id' => $a->question_id, 'attempt_no' => $a->attempt_no, 'date' => $a->attempt_date->toDateString(),
            'marks_earned' => self::num($a->marks_earned), 'max_marks' => self::num($a->max_marks), 'percent' => $a->percent,
            'worked_minutes' => $a->worked_minutes, 'result' => $a->result, 'result_label' => QuestionAttempt::RESULTS[$a->result] ?? $a->result,
            'independent' => (bool) $a->independent, 'checked_with_scheme' => (bool) $a->checked_with_scheme,
            'mistake_category' => $a->mistake_category, 'notes' => $a->notes, 'paper_attempt_id' => $a->paper_attempt_id,
        ];
        if ($a->relationLoaded('question') && $a->question->relationLoaded('paper')) {
            $out['question'] = ['id' => $a->question->id, 'number' => $a->question->question_no, 'paper_label' => $a->question->paper->label,
                'subject' => $a->question->paper->relationLoaded('subject') ? $a->question->paper->subject->name : null];
        }

        return $out;
    }

    public static function paperAttempt(PaperAttempt $p): array
    {
        $t = $p->totals();

        return [
            'id' => $p->id, 'paper_id' => $p->paper_id, 'attempt_no' => $p->attempt_no, 'date' => $p->attempt_date->toDateString(),
            'marks_earned' => self::num($p->marks_earned), 'max_marks' => self::num($p->max_marks), 'worked_minutes' => $p->worked_minutes,
            'independent' => (bool) $p->independent, 'timed' => (bool) $p->timed, 'notes' => $p->notes,
            // The total comes from linked question attempts when there are any, never both added together.
            'total' => ['earned' => $t['earned'], 'max' => $t['max'], 'source' => $t['source'],
                'percent' => ($t['earned'] !== null && $t['max']) ? round($t['earned'] / $t['max'] * 100, 1) : null],
            'paper' => $p->relationLoaded('paper') ? ['id' => $p->paper->id, 'label' => $p->paper->label,
                'subject' => $p->paper->relationLoaded('subject') ? $p->paper->subject->name : null, 'duration_minutes' => $p->paper->duration_minutes] : null,
        ];
    }

    public static function theory(TheoryStudyEntry $e): array
    {
        return [
            'id' => $e->id, 'topic_id' => $e->topic_id, 'date' => $e->study_date->toDateString(), 'minutes' => $e->minutes,
            'marked_complete' => (bool) $e->marked_complete, 'confidence' => $e->confidence, 'notes' => $e->notes,
            'topic' => $e->relationLoaded('topic') ? ['id' => $e->topic->id, 'name' => $e->topic->name, 'status' => $e->topic->status,
                'subject' => $e->topic->relationLoaded('unit') && $e->topic->unit->relationLoaded('subject') ? $e->topic->unit->subject->name : null] : null,
        ];
    }
}
