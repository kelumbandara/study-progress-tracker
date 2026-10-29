<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ExamPaper;
use App\Models\ExamQuestion;
use App\Models\PaperAttempt;
use App\Models\QuestionAttempt;
use App\Models\StudentProfile;
use App\Models\StudySetting;
use App\Models\Subject;
use App\Services\AttemptService;
use App\Services\DailyLogService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AttemptController extends Controller
{
    public function index(Request $request)
    {
        $student = StudentProfile::current();
        $attempts = QuestionAttempt::query()->with(['question.paper.subject'])->where('student_id', $student->id)
            ->when($request->query('subject'), fn ($q, $v) => $q->whereHas('question.paper', fn ($p) => $p->where('subject_id', $v)))
            ->latest('attempt_date')->latest('id')->paginate(25)->withQueryString();

        $paperAttempts = PaperAttempt::query()->with(['paper.subject', 'questionAttempts'])->where('student_id', $student->id)
            ->latest('attempt_date')->latest('id')->limit(10)->get();

        return view('attempts.index', ['attempts' => $attempts, 'paperAttempts' => $paperAttempts, 'subjects' => Subject::query()->orderBy('sort_order')->get()]);
    }

    public function create(Request $request)
    {
        $settings = StudySetting::query()->where('student_id', StudentProfile::current()->id)->firstOrFail();
        $pre = $request->query('question') ? ExamQuestion::query()->with('paper')->find($request->query('question')) : null;

        return view('attempts.create', [
            'subjects' => Subject::query()->orderBy('sort_order')->get(),
            'years' => range($settings->paper_year_to, $settings->paper_year_from),
            'today' => CarbonImmutable::now($settings->timezone)->toDateString(),
            'pre' => $pre,
            'results' => QuestionAttempt::RESULTS, 'mistakes' => QuestionAttempt::MISTAKES,
        ]);
    }

    public function store(Request $request, AttemptService $service, DailyLogService $logs)
    {
        $student = StudentProfile::current();
        $tz = StudySetting::query()->where('student_id', $student->id)->value('timezone');
        $d = $request->validate([
            'question_id' => ['required', 'exists:exam_questions,id'], 'paper_attempt_id' => ['nullable', 'exists:paper_attempts,id'],
            'attempt_date' => ['required', 'date', 'before_or_equal:' . CarbonImmutable::now($tz)->toDateString()],
            'marks_earned' => ['required', 'numeric', 'min:0', 'max:1000'], 'worked_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'result' => ['required', Rule::in(array_keys(QuestionAttempt::RESULTS))],
            'mistake_category' => ['nullable', Rule::in(array_keys(QuestionAttempt::MISTAKES))], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $d['independent'] = $request->input('assistance', 'independent') === 'independent';
        $d['checked_with_scheme'] = $request->boolean('checked_with_scheme');

        $attempt = $service->recordQuestion($student->id, $d);
        $redirect = redirect()->route('attempts.history', $attempt->question_id)
            ->with('status', __('Attempt :no saved (:marks/:max).', ['no' => $attempt->attempt_no, 'marks' => $attempt->marks_earned + 0, 'max' => $attempt->max_marks + 0]));
        if ($warnings = $logs->allocationWarnings($student->id, $d['attempt_date'])) {
            $redirect->with('warnings', $warnings);
        }

        return $redirect;
    }

    public function edit(QuestionAttempt $attempt)
    {
        $attempt->load('question.paper.subject');

        return view('attempts.edit', [
            'attempt' => $attempt, 'results' => QuestionAttempt::RESULTS, 'mistakes' => QuestionAttempt::MISTAKES,
            'today' => CarbonImmutable::now(StudySetting::query()->value('timezone'))->toDateString(),
            'audit' => $this->audit('question_attempts', $attempt->id),
        ]);
    }

    public function update(Request $request, QuestionAttempt $attempt, AttemptService $service)
    {
        $tz = StudySetting::query()->value('timezone');
        $d = $request->validate([
            'attempt_date' => ['required', 'date', 'before_or_equal:' . CarbonImmutable::now($tz)->toDateString()],
            'marks_earned' => ['required', 'numeric', 'min:0', 'max:1000'], 'worked_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'result' => ['required', Rule::in(array_keys(QuestionAttempt::RESULTS))],
            'mistake_category' => ['nullable', Rule::in(array_keys(QuestionAttempt::MISTAKES))], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $d['independent'] = $request->input('assistance', 'independent') === 'independent';
        $d['checked_with_scheme'] = $request->boolean('checked_with_scheme');
        $service->updateQuestionAttempt($attempt, $d);

        return redirect()->route('attempts.history', $attempt->question_id)
            ->with('status', __('Attempt :n corrected. The change is kept in the history.', ['n' => $attempt->attempt_no]));
    }

    public function editPaper(PaperAttempt $paperAttempt)
    {
        $paperAttempt->load('paper.subject', 'questionAttempts');

        return view('attempts.paper-edit', [
            'pa' => $paperAttempt, 'totals' => $paperAttempt->totals(),
            'today' => CarbonImmutable::now(StudySetting::query()->value('timezone'))->toDateString(),
            'audit' => $this->audit('paper_attempts', $paperAttempt->id),
        ]);
    }

    public function updatePaper(Request $request, PaperAttempt $paperAttempt, AttemptService $service)
    {
        $tz = StudySetting::query()->value('timezone');
        $d = $request->validate([
            'attempt_date' => ['required', 'date', 'before_or_equal:' . CarbonImmutable::now($tz)->toDateString()],
            'marks_earned' => ['nullable', 'numeric', 'min:0', 'max:1000', 'required_with:max_marks'],
            'max_marks' => ['nullable', 'numeric', 'gt:0', 'max:1000', 'gte:marks_earned'],
            'worked_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'], 'notes' => ['nullable', 'string', 'max:2000'],
        ], ['max_marks.gte' => __('Total marks earned cannot be more than the maximum.')]);
        $d['independent'] = $request->input('assistance', 'independent') === 'independent';
        $d['timed'] = $request->boolean('timed');
        $service->updatePaperAttempt($paperAttempt, $d);

        return redirect()->route('attempts.index')
            ->with('status', __('Paper attempt :n corrected. The change is kept in the history.', ['n' => $paperAttempt->attempt_no]));
    }

    private function audit(string $type, int $id)
    {
        return AuditLog::query()->where(['entity_type' => $type, 'entity_id' => $id])->latest('id')->limit(10)->get();
    }

    public function history(ExamQuestion $question)
    {
        $student = StudentProfile::current();
        $question->load(['paper.subject', 'topics']);
        $attempts = $question->attempts()->where('student_id', $student->id)->get();
        $first = $attempts->first();
        $latest = $attempts->last();

        return view('attempts.history', [
            'question' => $question, 'attempts' => $attempts,
            'improvement' => $attempts->count() > 1 ? [
                'score' => round($latest->percent - $first->percent, 1),
                'time' => ($first->worked_minutes !== null && $latest->worked_minutes !== null) ? $latest->worked_minutes - $first->worked_minutes : null,
                'best' => $attempts->max('percent'),
            ] : null,
        ]);
    }

    public function createPaper()
    {
        $settings = StudySetting::query()->where('student_id', StudentProfile::current()->id)->firstOrFail();

        return view('attempts.paper-create', [
            'subjects' => Subject::query()->orderBy('sort_order')->get(),
            'years' => range($settings->paper_year_to, $settings->paper_year_from),
            'today' => CarbonImmutable::now($settings->timezone)->toDateString(),
        ]);
    }

    public function storePaper(Request $request, AttemptService $service)
    {
        $student = StudentProfile::current();
        $tz = StudySetting::query()->where('student_id', $student->id)->value('timezone');
        $d = $request->validate([
            'paper_id' => ['required', 'exists:exam_papers,id'],
            'attempt_date' => ['required', 'date', 'before_or_equal:' . CarbonImmutable::now($tz)->toDateString()],
            'marks_earned' => ['nullable', 'numeric', 'min:0', 'max:1000', 'required_with:max_marks'],
            'max_marks' => ['nullable', 'numeric', 'gt:0', 'max:1000', 'gte:marks_earned'],
            'worked_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'], 'notes' => ['nullable', 'string', 'max:2000'],
        ], ['max_marks.gte' => __('Total marks earned cannot be more than the maximum.')]);
        $d['independent'] = $request->input('assistance', 'independent') === 'independent';
        $d['timed'] = $request->boolean('timed');
        $paper = ExamPaper::findOrFail($d['paper_id']);
        $d['max_marks'] ??= $paper->max_marks;

        $pa = $service->recordPaper($student->id, $d);

        return redirect()->route('attempts.index')->with('status', __('Paper attempt :n recorded for :p.', ['n' => $pa->attempt_no, 'p' => $paper->label]));
    }

    public function paperAttemptsJson(ExamPaper $paper)
    {
        return PaperAttempt::query()->where(['student_id' => StudentProfile::current()->id, 'paper_id' => $paper->id])
            ->orderByDesc('attempt_date')->get()->map(fn ($a) => ['id' => $a->id, 'label' => "Attempt {$a->attempt_no} on {$a->attempt_date->format('d M Y')}"]);
    }
}
