<?php

namespace App\Http\Controllers;

use App\Models\ExamPaper;
use App\Models\ExamQuestion;
use App\Models\ImportLog;
use App\Models\StudentProfile;
use App\Models\StudySetting;
use App\Models\Subject;
use App\Models\SyllabusTopic;
use App\Services\ImportService;
use App\Services\PaperRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class PaperController extends Controller
{
    public function index(Request $request)
    {
        $settings = StudySetting::query()->where('student_id', StudentProfile::current()->id)->firstOrFail();
        $papers = ExamPaper::query()->with('subject')->withCount(['questions', 'attempts'])
            ->when($request->query('subject'), fn ($q, $v) => $q->where('subject_id', $v))
            ->when($request->query('year'), fn ($q, $v) => $q->where('exam_year', $v))
            ->orderByDesc('exam_year')->orderBy('subject_id')->orderBy('paper_name')->paginate(25)->withQueryString();

        return view('papers.index', [
            'papers' => $papers, 'subjects' => Subject::query()->orderBy('sort_order')->get(),
            'years' => range($settings->paper_year_to, $settings->paper_year_from),
            'availability' => ExamPaper::AVAILABILITY,
            'imports' => ImportLog::query()->whereIn('type', ['papers', 'questions'])->latest('id')->limit(4)->get(),
        ]);
    }

    public function show(ExamPaper $paper)
    {
        $paper->load(['subject', 'questions.topics']);

        return view('papers.show', [
            'paper' => $paper, 'availability' => ExamPaper::AVAILABILITY,
            'topics' => $this->topicOptions($paper->subject_id),
        ]);
    }

    public function storePaper(Request $request)
    {
        $d = $request->validate([
            'subject_id' => ['required', 'exists:subjects,id'], 'exam_year' => ['required', 'integer', 'between:1990,2100'],
            'paper_name' => ['required', 'string', 'max:32'], 'exam_type' => ['nullable', 'string', 'max:64'],
            'max_marks' => ['nullable', 'integer', 'min:1', 'max:1000'], 'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'paper_url' => ['nullable', 'url', 'max:512'], 'marking_scheme_url' => ['nullable', 'url', 'max:512'],
            'availability' => ['nullable', Rule::in(array_keys(ExamPaper::AVAILABILITY))],
        ]);
        [$paper, $created] = app(PaperRegistry::class)->paper($d);
        if (! $created) {
            // Already there (or a double-click / second tab registered it an instant earlier): report it, not an error.
            return $request->expectsJson()
                ? response()->json(['id' => $paper->id, 'label' => $paper->label, 'existing' => true])
                : back()->withInput()->withErrors(['paper_name' => 'That paper is already registered.']);
        }

        return $request->expectsJson()
            ? response()->json(['id' => $paper->id, 'label' => $paper->label], 201)
            : redirect()->route('papers.show', $paper)->with('status', __('Paper registered.'));
    }

    public function updatePaper(Request $request, ExamPaper $paper)
    {
        $d = $request->validate([
            'exam_type' => ['nullable', 'string', 'max:64'], 'max_marks' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:600'], 'paper_url' => ['nullable', 'url', 'max:512'],
            'marking_scheme_url' => ['nullable', 'url', 'max:512'], 'availability' => ['required', Rule::in(array_keys(ExamPaper::AVAILABILITY))],
        ]);
        $paper->update($d);

        return back()->with('status', __('Paper updated.'));
    }

    public function storeQuestion(Request $request, ExamPaper $paper)
    {
        $d = $request->validate([
            'question_no' => ['required', 'string', 'max:16'], 'max_marks' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'reference' => ['nullable', 'string', 'max:64'], 'notes' => ['nullable', 'string', 'max:1000'],
            'topic_ids' => ['nullable', 'array'], 'topic_ids.*' => ['integer', Rule::exists('syllabus_topics', 'id')],
        ]);
        [$q, $created] = app(PaperRegistry::class)->question($paper, $d);
        if (! $created) {
            return $request->expectsJson()
                ? response()->json(['id' => $q->id, 'label' => $q->label, 'max_marks' => $q->max_marks, 'existing' => true])
                : back()->withInput()->withErrors(['question_no' => 'That question number is already registered for this paper.']);
        }

        return $request->expectsJson()
            ? response()->json(['id' => $q->id, 'label' => $q->label, 'max_marks' => $q->max_marks], 201)
            : back()->with('status', __('Question :n added.', ['n' => $q->question_no]));
    }

    public function updateQuestion(Request $request, ExamQuestion $question)
    {
        $d = $request->validate([
            'max_marks' => ['required', 'numeric', 'gt:0', 'max:1000'], 'reference' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'], 'topic_ids' => ['nullable', 'array'], 'topic_ids.*' => ['integer', Rule::exists('syllabus_topics', 'id')],
        ]);
        $question->update(collect($d)->except('topic_ids')->all());
        $question->topics()->sync($d['topic_ids'] ?? []);

        return back()->with('status', __('Question :n updated.', ['n' => $question->question_no]));
    }

    public function import(Request $request, ImportService $imports)
    {
        $d = $request->validate(['type' => ['required', Rule::in(['papers', 'questions'])], 'file' => ['required', 'file', 'extensions:csv,txt,xlsx,xls', 'mimes:csv,txt,xlsx,xls', 'max:5120']]);
        $log = $imports->importFile($d['type'], $request->file('file'));

        return back()->with('status', __('Import finished: :ok added, :bad with errors.', ['ok' => $log->rows_ok, 'bad' => $log->rows_failed]));
    }

    // ---- JSON helpers for the cascading dropdowns --------------------------------------------

    public function papersJson(Request $request)
    {
        return ExamPaper::query()->where('subject_id', $request->query('subject_id'))->where('exam_year', $request->query('year'))
            ->orderBy('paper_name')->get()->map(fn ($p) => ['id' => $p->id, 'label' => $p->paper_name, 'max_marks' => $p->max_marks]);
    }

    public function questionsJson(ExamPaper $paper)
    {
        return $paper->questions()->withCount('attempts')->get()->map(fn ($q) => [
            'id' => $q->id, 'label' => $q->label, 'max_marks' => $q->max_marks, 'attempts' => $q->attempts_count,
        ]);
    }

    public function topicsJson(Request $request)
    {
        return $this->topicOptions((int) $request->query('subject_id'));
    }

    private function topicOptions(int $subjectId): array
    {
        return SyllabusTopic::query()->with(['unit', 'parent'])->whereHas('unit', fn ($q) => $q->where('subject_id', $subjectId))
            ->orderBy('unit_id')->orderBy('sort_order')->get()
            ->map(fn ($t) => ['id' => $t->id, 'text' => $t->full_name, 'group' => $t->unit->name])->values()->all();
    }
}
