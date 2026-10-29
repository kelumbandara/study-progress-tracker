<?php

namespace App\Services;

use App\Models\SyllabusTopic;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Coverage versus demonstrated mastery. Counts leaf items only (a topic with subtopics is counted via its subtopics). */
class SyllabusStatsService
{
    private const THEORY_DONE = ['theory_completed', 'questions_practised', 'mastered'];

    /** @param Collection<int,SyllabusTopic> $topics all topics in scope */
    public function summarize(Collection $topics, int $studentId): array
    {
        $parents = $topics->pluck('parent_id')->filter()->unique()->all();
        $leaves = $topics->reject(fn ($t) => in_array($t->id, $parents, true));
        $attempted = $this->attemptedTopicIds($studentId);

        $count = fn (callable $f) => $leaves->filter($f)->count();
        $total = $leaves->count();
        $stats = [
            'total' => $total,
            'started' => $count(fn ($t) => $t->status !== 'not_started'),
            'theory_completed' => $count(fn ($t) => in_array($t->status, self::THEORY_DONE, true) || $t->theory_completed_on !== null),
            'practised' => $count(fn ($t) => in_array($t->id, $attempted, true)),
            'mastered' => $count(fn ($t) => $t->status === 'mastered'),
            'revision' => $count(fn ($t) => $t->status === 'revision_required'),
            'not_practised' => $count(fn ($t) => ! in_array($t->id, $attempted, true)),
        ];
        $stats['theory_pct'] = $total ? round($stats['theory_completed'] / $total * 100, 1) : null;
        $stats['mastered_pct'] = $total ? round($stats['mastered'] / $total * 100, 1) : null;
        $stats['remaining'] = $total - $stats['theory_completed'];

        return $stats;
    }

    /** Topic ids that have at least one attempt on a linked question. */
    public function attemptedTopicIds(int $studentId): array
    {
        return DB::table('question_topic_links as l')
            ->join('question_attempts as a', 'a.question_id', '=', 'l.question_id')
            ->where('a.student_id', $studentId)
            ->distinct()->pluck('l.topic_id')->all();
    }
}
