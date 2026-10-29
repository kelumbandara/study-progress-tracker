<?php

namespace App\Http\Controllers;

use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\SyllabusTopic;
use App\Models\SyllabusUnit;
use App\Services\ImportService;
use App\Services\SyllabusStatsService;
use App\Services\TopicService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SyllabusController extends Controller
{
    public function index(Request $request, SyllabusStatsService $stats)
    {
        $student = StudentProfile::current();
        $subjects = Subject::query()->orderBy('sort_order')->get();
        $subject = $subjects->firstWhere('id', (int) $request->query('subject')) ?? $subjects->first();

        $units = SyllabusUnit::query()->where('subject_id', $subject->id)->orderBy('sort_order')->orderBy('id')->get();
        $allTopics = SyllabusTopic::query()->whereIn('unit_id', $units->pluck('id'))->orderBy('sort_order')->orderBy('id')->get();
        $attempted = $stats->attemptedTopicIds($student->id);

        $unitStats = $units->mapWithKeys(fn ($u) => [$u->id => $stats->summarize($allTopics->where('unit_id', $u->id), $student->id)]);

        return view('syllabus.index', [
            'subjects' => $subjects, 'subject' => $subject, 'units' => $units,
            'topics' => $allTopics->whereNull('parent_id')->groupBy('unit_id'),
            'children' => $allTopics->whereNotNull('parent_id')->groupBy('parent_id'),
            'attempted' => $attempted, 'unitStats' => $unitStats,
            'subjectStats' => $stats->summarize($allTopics, $student->id),
            'statuses' => SyllabusTopic::STATUSES,
            'hasSample' => $units->contains('is_sample', true) || $allTopics->contains('is_sample', true),
            'imports' => \App\Models\ImportLog::query()->where('type', 'syllabus')->latest('id')->limit(3)->get(),
        ]);
    }

    public function storeUnit(Request $request)
    {
        $d = $request->validate(['subject_id' => ['required', 'exists:subjects,id'], 'name' => ['required', 'string', 'max:255'], 'reference' => ['nullable', 'string', 'max:64']]);
        abort_if(SyllabusUnit::query()->where(['subject_id' => $d['subject_id'], 'name' => $d['name']])->exists(), 422, 'That unit already exists.');
        SyllabusUnit::create($d + ['sort_order' => (int) SyllabusUnit::query()->where('subject_id', $d['subject_id'])->max('sort_order') + 1]);

        return back()->with('status', __('Unit added.'));
    }

    public function storeTopic(Request $request)
    {
        $d = $request->validate([
            'unit_id' => ['required', 'exists:syllabus_units,id'], 'parent_id' => ['nullable', 'exists:syllabus_topics,id'],
            'name' => ['required', 'string', 'max:255'], 'reference' => ['nullable', 'string', 'max:64'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ]);
        $exists = SyllabusTopic::query()->where(['unit_id' => $d['unit_id'], 'parent_id' => $d['parent_id'] ?? null, 'name' => $d['name']])->exists();
        abort_if($exists, 422, 'That topic already exists in this unit.');
        SyllabusTopic::create([
            'unit_id' => $d['unit_id'], 'parent_id' => $d['parent_id'] ?? null, 'name' => $d['name'], 'reference' => $d['reference'] ?? null,
            'estimated_minutes' => isset($d['estimated_hours']) ? (int) round($d['estimated_hours'] * 60) : null,
            'sort_order' => (int) SyllabusTopic::query()->where(['unit_id' => $d['unit_id'], 'parent_id' => $d['parent_id'] ?? null])->max('sort_order') + 1,
        ]);

        return back()->with('status', __('Topic added.'));
    }

    public function updateTopic(Request $request, SyllabusTopic $topic, TopicService $service)
    {
        $d = $request->validate([
            'status' => ['required', Rule::in(array_keys(SyllabusTopic::STATUSES))],
            'confidence' => ['nullable', 'integer', 'between:1,5'], 'note' => ['nullable', 'string', 'max:255'],
        ]);
        $service->setStatus($topic, $d['status'], $d['note'] ?? null);
        if (($d['confidence'] ?? null) !== null) {
            $topic->update(['confidence' => $d['confidence']]);
        }

        return back()->with('status', __('Updated ":n".', ['n' => $topic->name]));
    }

    public function import(Request $request, ImportService $imports)
    {
        $request->validate(['file' => ['required', 'file', 'extensions:csv,txt,xlsx,xls', 'mimes:csv,txt,xlsx,xls', 'max:5120']]);
        $log = $imports->importFile('syllabus', $request->file('file'));

        return back()->with('import', $log->id)->with('status', __('Import finished: :ok added, :bad with errors.', ['ok' => $log->rows_ok, 'bad' => $log->rows_failed]));
    }

    /** Loads the NIE-derived file shipped with the app (database/data/nie_syllabus_2017.csv). */
    public function loadNie(ImportService $imports)
    {
        $log = $imports->importFile('syllabus', database_path('data/nie_syllabus_2017.csv'));

        return back()->with('import', $log->id)->with('status', __('NIE syllabus loaded: :ok added, :bad with errors.', ['ok' => $log->rows_ok, 'bad' => $log->rows_failed]));
    }
}
