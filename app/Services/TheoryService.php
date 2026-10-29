<?php

namespace App\Services;

use App\Models\SyllabusTopic;
use App\Models\TheoryStudyEntry;
use Illuminate\Support\Facades\DB;

class TheoryService
{
    public function __construct(private TopicService $topics)
    {
    }

    /** Edits date, minutes, confidence and notes. The topic and the completion flag are kept (completion history is preserved). */
    public function update(TheoryStudyEntry $entry, array $d): TheoryStudyEntry
    {
        return DB::transaction(function () use ($entry, $d) {
            $entry = TheoryStudyEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $entry->fill(['study_date' => $d['study_date'], 'minutes' => $d['minutes'], 'confidence' => $d['confidence'] ?? null, 'notes' => $d['notes'] ?? null]);
            if ($entry->isDirty()) {
                \App\Models\AuditLog::record('theory_study_entries', $entry->id, 'updated', array_intersect_key($entry->getOriginal(), $entry->getDirty()), $entry->getDirty());
                $entry->save();
                $topic = SyllabusTopic::query()->lockForUpdate()->findOrFail($entry->topic_id);
                $topic->theory_minutes = (int) TheoryStudyEntry::query()->where('topic_id', $topic->id)->sum('minutes');
                $topic->save();
            }

            return $entry;
        });
    }

    /**
     * Each entry is an allocation of the day's Study Theory time (never added to totals).
     * Marking complete records the date and keeps history, but never marks mastered.
     */
    public function record(int $studentId, array $d): TheoryStudyEntry
    {
        return DB::transaction(function () use ($studentId, $d) {
            $topic = SyllabusTopic::query()->lockForUpdate()->findOrFail($d['topic_id']);
            $entry = TheoryStudyEntry::create([
                'student_id' => $studentId,
                'study_date' => $d['study_date'],
                'topic_id' => $topic->id,
                'minutes' => $d['minutes'],
                'marked_complete' => (bool) ($d['marked_complete'] ?? false),
                'confidence' => $d['confidence'] ?? null,
                'notes' => $d['notes'] ?? null,
            ]);

            $topic->theory_minutes = (int) TheoryStudyEntry::query()->where('topic_id', $topic->id)->sum('minutes');
            if (! empty($d['confidence'])) {
                $topic->confidence = $d['confidence'];
            }
            $topic->save();

            if ($entry->marked_complete) {
                // A repeat completion after revision is allowed and keeps its own history row.
                $topic->theory_completed_on = $d['study_date'];
                $topic->save();
                $this->topics->forceStatus($topic, 'theory_completed', 'Theory marked complete on ' . $d['study_date']);
            } elseif ($topic->status === 'not_started') {
                $this->topics->setStatus($topic, 'in_progress', 'Theory study recorded');
            }

            return $entry;
        });
    }
}
