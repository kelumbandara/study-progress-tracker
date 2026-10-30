<?php

namespace App\Http\Controllers;

use App\Models\StudentProfile;
use App\Models\StudySetting;
use App\Services\PhoneUsageService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class PhoneUsageController extends Controller
{
    public function index(Request $request, PhoneUsageService $usage)
    {
        $student = StudentProfile::current();
        $settings = StudySetting::query()->where('student_id', $student->id)->firstOrFail();
        $days = in_array((int) $request->query('days'), [7, 14, 30], true) ? (int) $request->query('days') : 7;
        $today = CarbonImmutable::now($settings->timezone)->startOfDay();

        return view('phone-usage.index', [
            'days' => $days,
            'today' => $today,
            's' => $usage->summary($student->id, $today->subDays($days - 1), $today, $today),
        ]);
    }

    /** The student can erase everything the phone has sent. Needs an explicit confirm field. */
    public function destroy(Request $request, PhoneUsageService $usage)
    {
        $request->validate(['confirm' => ['required', 'in:erase']]);
        $n = $usage->clear(StudentProfile::current()->id);

        return redirect()->route('phone-usage.index')->with('status', __('Phone use erased (:n rows).', ['n' => $n]));
    }
}
