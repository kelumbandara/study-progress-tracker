<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\StudentProfile;
use App\Models\StudySetting;
use App\Models\Subject;
use App\Models\SyllabusTopic;
use App\Models\TheoryStudyEntry;
use App\Services\DailyLogService;
use App\Services\TheoryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class TheoryController extends Controller
{
    public function index()
    {
        $student = StudentProfile::current();
        $settings = StudySetting::query()->where('student_id', $student->id)->firstOrFail();

        $topics = SyllabusTopic::query()->with(['unit.subject', 'parent'])->orderBy('unit_id')->orderBy('sort_order')->get()
            ->groupBy(fn ($t) => $t->unit->subject->name . ' › ' . $t->unit->name);

        return view('theory.index', [
            'entries' => TheoryStudyEntry::query()->with('topic.unit.subject')->where('student_id', $student->id)->latest('study_date')->latest('id')->paginate(20),
            'topicGroups' => $topics, 'today' => CarbonImmutable::now($settings->timezone)->toDateString(),
        ]);
    }

    public function edit(TheoryStudyEntry $entry)
    {
        $entry->load('topic.unit.subject');
        $tz = StudySetting::query()->value('timezone');

        return view('theory.edit', [
            'entry' => $entry, 'today' => CarbonImmutable::now($tz)->toDateString(),
            'audit' => AuditLog::query()->where(['entity_type' => 'theory_study_entries', 'entity_id' => $entry->id])->latest('id')->limit(10)->get(),
        ]);
    }

    public function update(Request $request, TheoryStudyEntry $entry, TheoryService $service, DailyLogService $logs)
    {
        $tz = StudySetting::query()->value('timezone');
        $oldDate = $entry->study_date->toDateString();
        $d = $request->validate([
            'study_date' => ['required', 'date', 'before_or_equal:' . CarbonImmutable::now($tz)->toDateString()],
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'confidence' => ['nullable', 'integer', 'between:1,5'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $service->update($entry, $d);

        $redirect = redirect()->route('theory.index')->with('status', __('Theory entry corrected. The change is kept in the history.'));
        foreach (array_unique([$d['study_date'], $oldDate]) as $date) {
            if ($warnings = $logs->allocationWarnings($entry->student_id, $date)) {
                $redirect->with('warnings', $warnings);
                break;
            }
        }

        return $redirect;
    }

    public function store(Request $request, TheoryService $service, DailyLogService $logs)
    {
        $student = StudentProfile::current();
        $tz = StudySetting::query()->where('student_id', $student->id)->value('timezone');
        $d = $request->validate([
            'study_date' => ['required', 'date', 'before_or_equal:' . CarbonImmutable::now($tz)->toDateString()],
            'topic_id' => ['required', 'exists:syllabus_topics,id'], 'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'confidence' => ['nullable', 'integer', 'between:1,5'], 'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $d['marked_complete'] = $request->boolean('marked_complete');
        $service->record($student->id, $d);

        $redirect = redirect()->route('theory.index')->with('status', __('Theory study recorded.'));
        if ($warnings = $logs->allocationWarnings($student->id, $d['study_date'])) {
            $redirect->with('warnings', $warnings);
        }

        return $redirect;
    }
}
