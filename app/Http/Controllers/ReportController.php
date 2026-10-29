<?php

namespace App\Http\Controllers;

use App\Exports\ReportExport;
use App\Models\StudentProfile;
use App\Models\StudySetting;
use App\Models\Subject;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function index(Request $request, ReportService $reports)
    {
        [$student, $settings, $type, $from, $to, $subjectId] = $this->resolve($request);

        return view('reports.index', [
            'report' => $reports->build($student, $settings, $type, $from, $to, $subjectId),
            'subjects' => Subject::query()->orderBy('sort_order')->get(),
            'type' => $type, 'subjectId' => $subjectId, 'from' => $from, 'to' => $to,
            'custom' => $request->filled('from') && $request->filled('to'),
            'anchor' => $request->query('anchor', CarbonImmutable::now($settings->timezone)->toDateString()),
            'query' => $request->query(),
        ]);
    }

    public function export(Request $request, ReportService $reports)
    {
        [$student, $settings, $type, $from, $to, $subjectId] = $this->resolve($request);
        $format = $request->query('format') === 'csv' ? 'csv' : 'xlsx';
        $rows = $reports->rows($reports->build($student, $settings, $type, $from, $to, $subjectId));
        $name = "{$type}-report-{$from->toDateString()}-to-{$to->toDateString()}.{$format}";

        return Excel::download(new ReportExport($rows), $name, $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX);
    }

    private function resolve(Request $request): array
    {
        $student = StudentProfile::current();
        $settings = StudySetting::query()->where('student_id', $student->id)->firstOrFail();
        $v = $request->validate([
            'type' => ['nullable', Rule::in(['weekly', 'monthly'])], 'anchor' => ['nullable', 'date'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'subject' => ['nullable', 'integer', 'exists:subjects,id'], 'format' => ['nullable', Rule::in(['csv', 'xlsx'])],
        ]);
        $type = $v['type'] ?? 'weekly';
        $anchor = CarbonImmutable::parse($v['anchor'] ?? 'now', $settings->timezone);

        if (! empty($v['from']) && ! empty($v['to'])) {
            $from = CarbonImmutable::parse($v['from'], $settings->timezone)->startOfDay();
            $to = CarbonImmutable::parse($v['to'], $settings->timezone)->startOfDay();
            abort_if($from->diffInDays($to) > 400, 422, 'Please choose a range of at most 400 days.');
        } elseif ($type === 'monthly') {
            $from = $anchor->startOfMonth()->startOfDay();
            $to = $anchor->endOfMonth()->startOfDay();
        } else {
            $from = $anchor->startOfWeek()->startOfDay();
            $to = $anchor->endOfWeek()->startOfDay();
        }

        return [$student, $settings, $type, $from, $to, isset($v['subject']) ? (int) $v['subject'] : null];
    }
}
