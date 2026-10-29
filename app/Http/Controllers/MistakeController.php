<?php

namespace App\Http\Controllers;

use App\Models\StudentProfile;
use App\Models\StudySetting;
use App\Services\MistakeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class MistakeController extends Controller
{
    public function index(MistakeService $service)
    {
        $student = StudentProfile::current();
        $tz = StudySetting::query()->where('student_id', $student->id)->value('timezone');

        return view('mistakes.index', [
            'queue' => $service->queue($student->id), 'today' => CarbonImmutable::now($tz)->toDateString(),
        ]);
    }

    public function store(Request $request, MistakeService $service)
    {
        $student = StudentProfile::current();
        $tz = StudySetting::query()->where('student_id', $student->id)->value('timezone');
        $d = $request->validate([
            'question_id' => ['required', 'exists:exam_questions,id'],
            'reviewed_on' => ['required', 'date', 'before_or_equal:' . CarbonImmutable::now($tz)->toDateString()],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        foreach (['theory_reviewed', 'solution_understood', 'reattempted', 'mastered_independently'] as $f) {
            $d[$f] = $request->boolean($f);
        }
        $service->review($student->id, $d);

        return back()->with('status', __('Review saved.'));
    }
}
