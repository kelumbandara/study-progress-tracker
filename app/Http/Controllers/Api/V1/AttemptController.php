<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use App\Models\ExamPaper;
use App\Models\ExamQuestion;
use App\Models\PaperAttempt;
use App\Models\QuestionAttempt;
use App\Services\AttemptService;
use App\Services\DailyLogService;
use App\Support\ApiPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AttemptController extends Controller
{
    use ResolvesStudent;

    private function questionRules(): array
    {
        return [
            'attempt_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->today()->toDateString()],
            'marks_earned' => ['required', 'numeric', 'min:0', 'max:1000'], 'worked_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'result' => ['required', Rule::in(array_keys(QuestionAttempt::RESULTS))],
            'independent' => ['required', 'boolean'], 'checked_with_scheme' => ['nullable', 'boolean'],
            'mistake_category' => ['nullable', Rule::in(array_keys(QuestionAttempt::MISTAKES))], 'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function paperRules(): array
    {
        return [
            'attempt_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . $this->today()->toDateString()],
            'marks_earned' => ['nullable', 'numeric', 'min:0', 'max:1000', 'required_with:max_marks'],
            'max_marks' => ['nullable', 'numeric', 'gt:0', 'max:1000', 'gte:marks_earned'],
            'worked_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'], 'independent' => ['required', 'boolean'],
            'timed' => ['nullable', 'boolean'], 'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** First/latest/best and the improvement since the first attempt (null until there are two attempts). */
    private function improvement($attempts): ?array
    {
        if ($attempts->count() < 2) {
            return null;
        }
        $first = $attempts->first();
        $latest = $attempts->last();

        return [
            'score_points' => round($latest->percent - $first->percent, 1),
            'minutes_change' => ($first->worked_minutes !== null && $latest->worked_minutes !== null) ? $latest->worked_minutes - $first->worked_minutes : null,
            'best_percent' => $attempts->max('percent'), 'first_percent' => $first->percent, 'latest_percent' => $latest->percent,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['subject_id' => ['nullable', 'integer', 'exists:subjects,id'], 'per_page' => ['nullable', 'integer', 'between:1,60']]);
        $p = QuestionAttempt::query()->with('question.paper.subject')->where('student_id', $this->student()->id)
            ->when($d['subject_id'] ?? null, fn ($q, $v) => $q->whereHas('question.paper', fn ($x) => $x->where('subject_id', $v)))
            ->latest('attempt_date')->latest('id')->paginate((int) ($d['per_page'] ?? 25));

        return response()->json([
            'data' => $p->getCollection()->map(fn ($a) => ApiPresenter::attempt($a))->all(),
            'meta' => ['page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
        ]);
    }

    /** All attempts of one question, oldest first, with the improvement summary. */
    public function history(ExamQuestion $question): JsonResponse
    {
        $question->load('paper.subject', 'topics:id,name');
        $attempts = $question->attempts()->where('student_id', $this->student()->id)->get();

        return response()->json([
            'question' => ApiPresenter::question($question) + ['paper_label' => $question->paper->label, 'subject' => $question->paper->subject->name, 'topics' => $question->topics->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->all()],
            'attempts' => $attempts->map(fn ($a) => ApiPresenter::attempt($a))->all(),
            'improvement' => $this->improvement($attempts),
        ]);
    }

    /** Always adds a NEW attempt (the next number); earlier attempts are never overwritten. */
    public function store(Request $request, AttemptService $service, DailyLogService $logs): JsonResponse
    {
        $d = $request->validate($this->questionRules() + ['question_id' => ['required', 'integer', 'exists:exam_questions,id'], 'paper_attempt_id' => ['nullable', 'integer', 'exists:paper_attempts,id']]);
        $d['checked_with_scheme'] = (bool) ($d['checked_with_scheme'] ?? false);

        $attempt = $service->recordQuestion($this->student()->id, $d);
        $attempt->load('question.paper.subject', 'question.topics');
        $history = $attempt->question->attempts()->where('student_id', $this->student()->id)->get();

        return response()->json([
            'attempt' => ApiPresenter::attempt($attempt),
            'improvement' => $this->improvement($history),
            // Topics linked to this question and where they stand now (practice moves a topic forward or flags revision).
            'topics' => $attempt->question->topics->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'status' => $t->fresh()->status])->all(),
            'warnings' => $logs->allocationWarnings($this->student()->id, $d['attempt_date']),
        ], 201);
    }

    public function update(Request $request, QuestionAttempt $attempt, AttemptService $service): JsonResponse
    {
        $d = $request->validate($this->questionRules());
        $d['checked_with_scheme'] = (bool) ($d['checked_with_scheme'] ?? false);
        $updated = $service->updateQuestionAttempt($attempt, $d);

        return response()->json(['attempt' => ApiPresenter::attempt($updated->load('question.paper.subject'))]);
    }

    // ---- complete-paper attempts ------------------------------------------------------------

    public function paperIndex(Request $request): JsonResponse
    {
        $rows = PaperAttempt::query()->with(['paper.subject', 'questionAttempts'])->where('student_id', $this->student()->id)
            ->latest('attempt_date')->latest('id')->limit((int) $request->query('limit', 20))->get();

        return response()->json(['data' => $rows->map(fn ($p) => ApiPresenter::paperAttempt($p))->all()]);
    }

    public function paperStore(Request $request, AttemptService $service): JsonResponse
    {
        $d = $request->validate($this->paperRules() + ['paper_id' => ['required', 'integer', 'exists:exam_papers,id']], ['max_marks.gte' => 'Total marks earned cannot be more than the maximum.']);
        $d['max_marks'] ??= ExamPaper::find($d['paper_id'])->max_marks;
        $pa = $service->recordPaper($this->student()->id, $d + ['timed' => (bool) ($d['timed'] ?? false)]);

        return response()->json(['paper_attempt' => ApiPresenter::paperAttempt($pa->load('paper.subject', 'questionAttempts'))], 201);
    }

    public function paperUpdate(Request $request, PaperAttempt $paperAttempt, AttemptService $service): JsonResponse
    {
        $d = $request->validate($this->paperRules(), ['max_marks.gte' => 'Total marks earned cannot be more than the maximum.']);
        $pa = $service->updatePaperAttempt($paperAttempt, $d + ['timed' => (bool) ($d['timed'] ?? false)]);

        return response()->json(['paper_attempt' => ApiPresenter::paperAttempt($pa->load('paper.subject', 'questionAttempts'))]);
    }
}
