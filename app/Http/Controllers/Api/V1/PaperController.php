<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use App\Models\ExamPaper;
use App\Models\PaperAttempt;
use App\Models\SyllabusTopic;
use App\Services\PaperRegistry;
use App\Support\ApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Lookups for the cascading Subject → Year → Paper → Question pickers, and on-the-spot registration of missing ones. */
class PaperController extends Controller
{
    use ResolvesStudent;

    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['subject_id' => ['nullable', 'integer', 'exists:subjects,id'], 'year' => ['nullable', 'integer', 'between:1990,2100']]);
        $papers = ExamPaper::query()->withCount(['questions', 'attempts'])
            ->when($d['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($d['year'] ?? null, fn ($q, $v) => $q->where('exam_year', $v))
            ->orderByDesc('exam_year')->orderBy('subject_id')->orderBy('paper_name')->limit(300)->get();

        return response()->json(['data' => $papers->map(fn ($p) => ApiPresenter::paper($p))->all()]);
    }

    public function questions(ExamPaper $paper): JsonResponse
    {
        $qs = $paper->questions()->with('topics:id')->withCount('attempts')->get();

        return response()->json(['paper' => ApiPresenter::paper($paper), 'data' => $qs->map(fn ($q) => ApiPresenter::question($q))->all()]);
    }

    public function storePaper(Request $request, PaperRegistry $registry): JsonResponse
    {
        $d = $request->validate([
            'subject_id' => ['required', 'integer', 'exists:subjects,id'], 'exam_year' => ['required', 'integer', 'between:1990,2100'],
            'paper_name' => ['required', 'string', 'max:32'], 'exam_type' => ['nullable', 'string', 'max:64'],
            'max_marks' => ['nullable', 'integer', 'min:1', 'max:1000'], 'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'paper_url' => ['nullable', 'url', 'max:512'], 'marking_scheme_url' => ['nullable', 'url', 'max:512'],
            'availability' => ['nullable', Rule::in(array_keys(ExamPaper::AVAILABILITY))],
        ]);
        [$paper, $created] = $registry->paper($d);

        // 201 when new; 200 with created=false when it already existed (so a retry from a flaky connection is harmless).
        return response()->json(['created' => $created, 'paper' => ApiPresenter::paper($paper)], $created ? 201 : 200);
    }

    public function storeQuestion(Request $request, ExamPaper $paper, PaperRegistry $registry): JsonResponse
    {
        $d = $request->validate([
            'question_no' => ['required', 'string', 'max:16'], 'max_marks' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'reference' => ['nullable', 'string', 'max:64'], 'notes' => ['nullable', 'string', 'max:1000'],
            'topic_ids' => ['nullable', 'array'], 'topic_ids.*' => ['integer', Rule::exists('syllabus_topics', 'id')],
        ]);
        [$q, $created] = $registry->question($paper, $d);
        $q->load('topics:id');

        return response()->json(['created' => $created, 'question' => ApiPresenter::question($q)], $created ? 201 : 200);
    }

    /** Topics of one subject for the "which topics does this question cover" picker. */
    public function topics(Request $request): JsonResponse
    {
        $d = $request->validate(['subject_id' => ['required', 'integer', 'exists:subjects,id']]);
        $topics = SyllabusTopic::query()->with(['unit', 'parent'])->whereHas('unit', fn ($q) => $q->where('subject_id', $d['subject_id']))
            ->orderBy('unit_id')->orderBy('sort_order')->get();

        return response()->json(['data' => $topics->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'full_name' => $t->full_name, 'unit' => $t->unit->name, 'status' => $t->status])->all()]);
    }

    /** The student's earlier full-paper attempts of one paper, for "this question belongs to that sitting". */
    public function paperAttempts(ExamPaper $paper): JsonResponse
    {
        $rows = PaperAttempt::query()->where(['student_id' => $this->student()->id, 'paper_id' => $paper->id])->orderByDesc('attempt_date')->orderByDesc('id')->get();

        return response()->json(['data' => $rows->map(fn ($a) => ['id' => $a->id, 'attempt_no' => $a->attempt_no, 'date' => $a->attempt_date->toDateString()])->all()]);
    }
}
