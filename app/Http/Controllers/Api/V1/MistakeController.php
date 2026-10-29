<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use App\Services\MistakeService;
use App\Support\ApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MistakeController extends Controller
{
    use ResolvesStudent;

    /** Questions that still need work. A question leaves only after an independent attempt at or above the mastery percentage. */
    public function index(MistakeService $service): JsonResponse
    {
        $rows = $service->queue($this->student()->id)->map(function ($r) {
            $q = $r['question'];

            return [
                'question' => ['id' => $q->id, 'number' => $q->question_no, 'max_marks' => (float) $q->max_marks, 'paper_label' => $q->paper->label, 'subject' => $q->paper->subject->name,
                    'topics' => $q->topics->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->all()],
                'latest_attempt' => ApiPresenter::attempt($r['latest']),
                'reasons' => $r['reasons'], 'attempts' => $r['attempts'], 'weak_attempts' => $r['weak_attempts'], 'repeated' => (bool) $r['repeated'],
                'last_review' => $r['last_review']?->reviewed_on->toDateString(),
            ];
        })->values()->all();

        return response()->json(['count' => count($rows), 'data' => $rows]);
    }

    public function store(Request $request, MistakeService $service): JsonResponse
    {
        $d = $request->validate([
            'question_id' => ['required', 'integer', 'exists:exam_questions,id'],
            'reviewed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->today()->toDateString()],
            'theory_reviewed' => ['nullable', 'boolean'], 'solution_understood' => ['nullable', 'boolean'],
            'reattempted' => ['nullable', 'boolean'], 'mastered_independently' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        foreach (['theory_reviewed', 'solution_understood', 'reattempted', 'mastered_independently'] as $f) {
            $d[$f] = (bool) ($d[$f] ?? false);
        }
        $review = $service->review($this->student()->id, $d); // refuses "mastered independently" without a real independent high-scoring attempt

        return response()->json(['review' => ['id' => $review->id, 'question_id' => $review->question_id, 'reviewed_on' => $review->reviewed_on->toDateString()]], 201);
    }
}
