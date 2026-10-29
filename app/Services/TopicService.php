<?php

namespace App\Services;

use App\Models\QuestionAttempt;
use App\Models\SyllabusTopic;
use App\Models\TopicStatusHistory;
use Illuminate\Validation\ValidationException;

class TopicService
{
    /** Mastery needs an independent attempt on a linked question at or above the configured percentage. */
    public function hasMasteryEvidence(SyllabusTopic $topic): bool
    {
        return QuestionAttempt::query()
            ->where('independent', true)
            ->whereRaw('max_marks > 0 AND marks_earned * 100 >= ? * max_marks', [config('tracker.mastery_pct')])
            ->whereIn('question_id', $topic->questions()->select('exam_questions.id'))
            ->exists();
    }

    public function setStatus(SyllabusTopic $topic, string $status, ?string $note = null): void
    {
        if (! array_key_exists($status, SyllabusTopic::STATUSES)) {
            throw ValidationException::withMessages(['status' => 'Unknown status.']);
        }
        if ($status === 'mastered' && ! $this->hasMasteryEvidence($topic)) {
            throw ValidationException::withMessages(['status' => sprintf(
                'Cannot mark "%s" as Mastered yet: it needs an independent attempt scoring at least %d%% on a question linked to this topic.',
                $topic->name,
                config('tracker.mastery_pct')
            )]);
        }
        if ($topic->status === $status) {
            return;
        }

        TopicStatusHistory::create(['topic_id' => $topic->id, 'from_status' => $topic->status, 'to_status' => $status, 'note' => $note]);
        $topic->status = $status;
        if ($status === 'theory_completed' && $topic->theory_completed_on === null) {
            $topic->theory_completed_on = now()->toDateString();
        }
        $topic->save();
    }

    /**
     * Records a (re)completion of theory. Always writes a history row, so repeated
     * completions after revision are kept; later stages (practised/mastered) are not downgraded.
     */
    public function forceStatus(SyllabusTopic $topic, string $status, string $note): void
    {
        $keep = in_array($topic->status, ['questions_practised', 'mastered'], true);
        $to = $keep ? $topic->status : $status;
        TopicStatusHistory::create(['topic_id' => $topic->id, 'from_status' => $topic->status, 'to_status' => $to, 'note' => $note]);
        $topic->status = $to;
        $topic->save();
    }

    /** After an attempt is edited, a Mastered topic that no longer has evidence goes back to Revision Required. */
    public function reconcileMastery(\Illuminate\Support\Collection $topics): void
    {
        foreach ($topics as $topic) {
            if ($topic->status === 'mastered' && ! $this->hasMasteryEvidence($topic)) {
                $this->setStatus($topic, 'revision_required', 'Mastery evidence changed after an attempt was edited');
            }
        }
    }

    /** Practice moves a topic forward or flags it for revision; it never sets Mastered by itself. */
    public function afterAttempt(QuestionAttempt $attempt, int $weakPct): void
    {
        $weak = $attempt->percent < $weakPct;
        foreach ($attempt->question->topics as $topic) {
            if ($weak && in_array($topic->status, ['questions_practised', 'mastered'], true)) {
                $this->setStatus($topic, 'revision_required', 'Low score on a linked question');
            } elseif (in_array($topic->status, ['not_started', 'in_progress', 'theory_completed'], true)) {
                $this->setStatus($topic, 'questions_practised', 'First practice on a linked question');
            }
        }
    }
}
