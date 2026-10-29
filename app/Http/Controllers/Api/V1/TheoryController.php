<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use App\Models\TheoryStudyEntry;
use App\Services\DailyLogService;
use App\Services\TheoryService;
use App\Support\ApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TheoryController extends Controller
{
    use ResolvesStudent;

    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'between:1,60']]);
        $p = TheoryStudyEntry::query()->with('topic.unit.subject')->where('student_id', $this->student()->id)
            ->latest('study_date')->latest('id')->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'data' => $p->getCollection()->map(fn ($e) => ApiPresenter::theory($e))->all(),
            'meta' => ['page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
        ]);
    }

    /** One entry per topic. Marking complete never marks the topic mastered. Minutes are part of the day's Study Theory total, not extra. */
    public function store(Request $request, TheoryService $service, DailyLogService $logs): JsonResponse
    {
        $d = $request->validate([
            'study_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->today()->toDateString()],
            'topic_id' => ['required', 'integer', 'exists:syllabus_topics,id'], 'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'marked_complete' => ['nullable', 'boolean'], 'confidence' => ['nullable', 'integer', 'between:1,5'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $entry = $service->record($this->student()->id, $d + ['marked_complete' => (bool) ($d['marked_complete'] ?? false)]);

        return response()->json([
            'entry' => ApiPresenter::theory($entry->load('topic.unit.subject')),
            'warnings' => $logs->allocationWarnings($this->student()->id, $d['study_date']),
        ], 201);
    }

    public function update(Request $request, TheoryStudyEntry $entry, TheoryService $service, DailyLogService $logs): JsonResponse
    {
        $d = $request->validate([
            'study_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->today()->toDateString()],
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'], 'confidence' => ['nullable', 'integer', 'between:1,5'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $oldDate = $entry->study_date->toDateString();
        $updated = $service->update($entry, $d);

        return response()->json([
            'entry' => ApiPresenter::theory($updated->load('topic.unit.subject')),
            'warnings' => collect(array_unique([$d['study_date'], $oldDate]))->flatMap(fn ($date) => $logs->allocationWarnings($this->student()->id, $date))->values()->all(),
        ]);
    }
}
