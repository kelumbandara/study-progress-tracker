<?php

namespace App\Services;

use App\Models\ExamQuestion;
use App\Models\MistakeReview;
use App\Models\QuestionAttempt;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class MistakeService
{
    /**
     * Questions whose latest attempt still needs work. A question leaves the queue only when its
     * latest attempt was independent and reached the mastery percentage - reading a solution is not enough.
     */
    public function queue(int $studentId): Collection
    {
        $questions = ExamQuestion::query()
            ->whereHas('attempts', fn ($q) => $q->where('student_id', $studentId))
            ->with(['paper.subject', 'topics', 'reviews' => fn ($q) => $q->where('student_id', $studentId), 'attempts' => fn ($q) => $q->where('student_id', $studentId)])
            ->get();

        return $questions->map(function (ExamQuestion $q) {
            $latest = $q->attempts->sortByDesc('attempt_no')->first();
            $subject = $q->paper->subject;
            $weakPct = $subject->weak_score_pct ?: config('tracker.review_below_pct');

            if ($latest->independent && $latest->percent >= config('tracker.mastery_pct')) {
                return null;
            }

            $reasons = [];
            if (in_array($latest->result, ['wrong', 'not_completed'], true)) {
                $reasons[] = $latest->result === 'wrong' ? __('Wrong') : __('Not completed');
            }
            if ($latest->percent < $weakPct) {
                $reasons[] = __('Low score (:pct% < :weak%)', ['pct' => $latest->percent, 'weak' => $weakPct]);
            }
            if (! $latest->independent) {
                $reasons[] = __('Needed assistance');
            }
            if ($subject->slow_minutes_per_mark !== null && $latest->worked_minutes !== null && $latest->max_marks > 0
                && $latest->worked_minutes / $latest->max_marks > (float) $subject->slow_minutes_per_mark) {
                $reasons[] = __('Slow (:v min per mark)', ['v' => sprintf('%.1f', $latest->worked_minutes / $latest->max_marks)]);
            }
            if ($q->topics->contains(fn ($t) => $t->confidence !== null && $t->confidence <= 2)) {
                $reasons[] = __('Topic marked difficult');
            }
            if (! $reasons) {
                return null;
            }

            $weakAttempts = $q->attempts->filter(fn ($a) => $a->percent < $weakPct)->count();

            return [
                'question' => $q, 'latest' => $latest, 'reasons' => $reasons,
                'attempts' => $q->attempts->count(), 'weak_attempts' => $weakAttempts,
                'repeated' => $weakAttempts >= 2, 'last_review' => $q->reviews->first(),
            ];
        })->filter()->sortByDesc(fn ($r) => [$r['repeated'], $r['weak_attempts']])->values();
    }

    public function review(int $studentId, array $d): MistakeReview
    {
        $question = ExamQuestion::query()->findOrFail($d['question_id']);
        $latest = QuestionAttempt::query()->where(['student_id' => $studentId, 'question_id' => $question->id])->orderByDesc('attempt_no')->first();

        if ($latest === null) {
            throw ValidationException::withMessages(['question_id' => 'This question has no attempts yet.']);
        }
        $mastered = (bool) ($d['mastered_independently'] ?? false);
        if ($mastered && ! ($latest->independent && $latest->percent >= config('tracker.mastery_pct'))) {
            throw ValidationException::withMessages(['mastered_independently' => sprintf(
                'Mastery needs an independent attempt scoring at least %d%%. Record another attempt first; reading the solution is not enough.',
                config('tracker.mastery_pct')
            )]);
        }

        return MistakeReview::create([
            'student_id' => $studentId, 'question_id' => $question->id, 'attempt_id' => $latest->id,
            'reviewed_on' => $d['reviewed_on'],
            'theory_reviewed' => (bool) ($d['theory_reviewed'] ?? false),
            'solution_understood' => (bool) ($d['solution_understood'] ?? false),
            'reattempted' => (bool) ($d['reattempted'] ?? false),
            'mastered_independently' => $mastered,
            'notes' => $d['notes'] ?? null,
        ]);
    }
}
