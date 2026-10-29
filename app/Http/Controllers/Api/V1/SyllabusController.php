<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Models\SyllabusTopic;
use App\Models\SyllabusUnit;
use App\Services\SyllabusStatsService;
use App\Services\TopicService;
use App\Support\ApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SyllabusController extends Controller
{
    use ResolvesStudent;

    public function index(Request $request, SyllabusStatsService $stats): JsonResponse
    {
        $d = $request->validate(['subject_id' => ['required', 'integer', 'exists:subjects,id']]);
        $subject = Subject::findOrFail($d['subject_id']);
        $studentId = $this->student()->id;

        $units = SyllabusUnit::query()->where('subject_id', $subject->id)->orderBy('sort_order')->orderBy('id')->get();
        $topics = SyllabusTopic::query()->whereIn('unit_id', $units->pluck('id'))->orderBy('sort_order')->orderBy('id')->get();
        $practised = $stats->attemptedTopicIds($studentId);
        $byParent = $topics->whereNotNull('parent_id')->groupBy('parent_id');

        $unitsOut = $units->map(function ($u) use ($topics, $byParent, $practised, $stats, $studentId) {
            return [
                'id' => $u->id, 'name' => $u->name, 'reference' => $u->reference, 'is_sample' => (bool) $u->is_sample,
                'stats' => $stats->summarize($topics->where('unit_id', $u->id), $studentId),
                'topics' => $topics->where('unit_id', $u->id)->whereNull('parent_id')->values()->map(fn ($t) => ApiPresenter::topic($t, $practised) + [
                    'subtopics' => ($byParent[$t->id] ?? collect())->map(fn ($c) => ApiPresenter::topic($c, $practised))->values()->all(),
                ])->all(),
            ];
        })->all();

        return response()->json(['subject' => ApiPresenter::subject($subject), 'stats' => $stats->summarize($topics, $studentId), 'units' => $unitsOut]);
    }

    /** Mastered is refused (HTTP 422) unless there is independent question evidence; the rule lives in TopicService. */
    public function updateTopic(Request $request, SyllabusTopic $topic, TopicService $service, SyllabusStatsService $stats): JsonResponse
    {
        $d = $request->validate([
            'status' => ['required', Rule::in(array_keys(SyllabusTopic::STATUSES))],
            'confidence' => ['nullable', 'integer', 'between:1,5'], 'note' => ['nullable', 'string', 'max:255'],
        ]);
        $service->setStatus($topic, $d['status'], $d['note'] ?? null);
        if (($d['confidence'] ?? null) !== null) {
            $topic->update(['confidence' => $d['confidence']]);
        }

        return response()->json(['topic' => ApiPresenter::topic($topic->fresh(), $stats->attemptedTopicIds($this->student()->id))]);
    }
}
