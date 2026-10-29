<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesStudent;
use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Support\ApiPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    use ResolvesStudent;

    /** Weekly or monthly report (or a custom range up to 400 days) as JSON. Excel/CSV export stays on the website. */
    public function show(Request $request, ReportService $reports): JsonResponse
    {
        $v = $request->validate([
            'type' => ['nullable', Rule::in(['weekly', 'monthly'])], 'anchor' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'], 'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
        ]);
        $settings = $this->settings();
        $type = $v['type'] ?? 'weekly';
        $anchor = CarbonImmutable::parse($v['anchor'] ?? 'now', $settings->timezone);

        if (! empty($v['from'])) {
            $from = CarbonImmutable::parse($v['from'], $settings->timezone)->startOfDay();
            $to = CarbonImmutable::parse($v['to'], $settings->timezone)->startOfDay();
            abort_if($from->diffInDays($to) > 400, 422, 'Please choose a range of at most 400 days.');
        } elseif ($type === 'monthly') {
            [$from, $to] = [$anchor->startOfMonth()->startOfDay(), $anchor->endOfMonth()->startOfDay()];
        } else {
            [$from, $to] = [$anchor->startOfWeek()->startOfDay(), $anchor->endOfWeek()->startOfDay()];
        }

        $report = $reports->build($this->student(), $settings, $type, $from, $to, isset($v['subject_id']) ? (int) $v['subject_id'] : null);

        return response()->json(ApiPresenter::plain($report));
    }
}
