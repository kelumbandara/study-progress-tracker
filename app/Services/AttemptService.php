<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ExamPaper;
use App\Models\ExamQuestion;
use App\Models\PaperAttempt;
use App\Models\QuestionAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttemptService
{
    public function __construct(private TopicService $topics)
    {
    }

    /** Appends a new attempt; earlier attempts are never touched. */
    public function recordQuestion(int $studentId, array $d): QuestionAttempt
    {
        return DB::transaction(function () use ($studentId, $d) {
            $question = ExamQuestion::query()->with(['topics', 'paper.subject'])->lockForUpdate()->findOrFail($d['question_id']);

            if ((float) $d['marks_earned'] > (float) $question->max_marks) {
                throw ValidationException::withMessages(['marks_earned' => "Marks earned cannot be more than the question's maximum ({$question->max_marks})."]);
            }
            if (! empty($d['paper_attempt_id'])) {
                $pa = PaperAttempt::query()->findOrFail($d['paper_attempt_id']);
                if ($pa->paper_id !== $question->paper_id) {
                    throw ValidationException::withMessages(['paper_attempt_id' => 'That question belongs to a different paper.']);
                }
            }

            $attempt = QuestionAttempt::create([
                'student_id' => $studentId,
                'question_id' => $question->id,
                'paper_attempt_id' => $d['paper_attempt_id'] ?? null,
                'attempt_date' => $d['attempt_date'],
                'attempt_no' => (int) QuestionAttempt::query()->where(['student_id' => $studentId, 'question_id' => $question->id])->max('attempt_no') + 1,
                'marks_earned' => $d['marks_earned'],
                'max_marks' => $question->max_marks, // snapshot
                'worked_minutes' => $d['worked_minutes'] ?? null,
                'result' => $d['result'],
                'independent' => (bool) ($d['independent'] ?? true),
                'checked_with_scheme' => (bool) ($d['checked_with_scheme'] ?? false),
                'mistake_category' => $d['mistake_category'] ?? null,
                'notes' => $d['notes'] ?? null,
            ]);
            $attempt->setRelation('question', $question);
            $this->topics->afterAttempt($attempt, $question->paper->subject->weak_score_pct);

            return $attempt;
        });
    }

    /** Edits an existing attempt (never creates one) and keeps the before/after values in the audit log. */
    public function updateQuestionAttempt(QuestionAttempt $attempt, array $d): QuestionAttempt
    {
        return DB::transaction(function () use ($attempt, $d) {
            $attempt = QuestionAttempt::query()->with('question.topics')->lockForUpdate()->findOrFail($attempt->id);

            if ((float) $d['marks_earned'] > (float) $attempt->max_marks) {
                throw ValidationException::withMessages(['marks_earned' => "Marks earned cannot be more than this attempt's maximum (" . ($attempt->max_marks + 0) . ').']);
            }
            $attempt->fill([
                'attempt_date' => $d['attempt_date'], 'marks_earned' => $d['marks_earned'], 'worked_minutes' => $d['worked_minutes'] ?? null,
                'result' => $d['result'], 'independent' => (bool) $d['independent'], 'checked_with_scheme' => (bool) ($d['checked_with_scheme'] ?? false),
                'mistake_category' => $d['mistake_category'] ?? null, 'notes' => $d['notes'] ?? null,
            ]);
            if ($attempt->isDirty()) {
                AuditLog::record('question_attempts', $attempt->id, 'updated', array_intersect_key($attempt->getOriginal(), $attempt->getDirty()), $attempt->getDirty());
                $attempt->save();
                $this->topics->reconcileMastery($attempt->question->topics);
            }

            return $attempt;
        });
    }

    public function updatePaperAttempt(PaperAttempt $pa, array $d): PaperAttempt
    {
        return DB::transaction(function () use ($pa, $d) {
            $pa = PaperAttempt::query()->lockForUpdate()->findOrFail($pa->id);
            $pa->fill([
                'attempt_date' => $d['attempt_date'], 'marks_earned' => $d['marks_earned'] ?? null, 'max_marks' => $d['max_marks'] ?? null,
                'worked_minutes' => $d['worked_minutes'] ?? null, 'independent' => (bool) $d['independent'], 'timed' => (bool) $d['timed'], 'notes' => $d['notes'] ?? null,
            ]);
            if ($pa->isDirty()) {
                AuditLog::record('paper_attempts', $pa->id, 'updated', array_intersect_key($pa->getOriginal(), $pa->getDirty()), $pa->getDirty());
                $pa->save();
            }

            return $pa;
        });
    }

    public function recordPaper(int $studentId, array $d): PaperAttempt
    {
        // Lock the paper row so two simultaneous saves cannot both take the same attempt number.
        return DB::transaction(function () use ($studentId, $d) {
            ExamPaper::query()->lockForUpdate()->findOrFail($d['paper_id']);

            return $this->createPaperAttempt($studentId, $d);
        });
    }

    private function createPaperAttempt(int $studentId, array $d): PaperAttempt
    {
        return PaperAttempt::create([
            'student_id' => $studentId,
            'paper_id' => $d['paper_id'],
            'attempt_date' => $d['attempt_date'],
            'attempt_no' => (int) PaperAttempt::query()->where(['student_id' => $studentId, 'paper_id' => $d['paper_id']])->max('attempt_no') + 1,
            'marks_earned' => $d['marks_earned'] ?? null,
            'max_marks' => $d['max_marks'] ?? null,
            'worked_minutes' => $d['worked_minutes'] ?? null,
            'independent' => (bool) ($d['independent'] ?? true),
            'timed' => (bool) ($d['timed'] ?? false),
            'notes' => $d['notes'] ?? null,
        ]);
    }
}
