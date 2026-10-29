<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\QuestionAttempt;
use App\Models\StudyDailyLog;
use App\Models\TheoryStudyEntry;
use App\Support\Category;
use App\Support\Duration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A daily-log row is a confirmed entry (zeros included). A date with no row is a
 * missing log, never an implied zero.
 */
class DailyLogService
{
    public function save(int $studentId, string $date, array $minutes, array $extra = []): StudyDailyLog
    {
        try {
            return $this->persist($studentId, $date, $minutes, $extra);
        } catch (UniqueConstraintViolationException) {
            // Two submissions for the same date raced: the other one created the row first, so this one edits it.
            return $this->persist($studentId, $date, $minutes, $extra);
        }
    }

    private function persist(int $studentId, string $date, array $minutes, array $extra): StudyDailyLog
    {
        return DB::transaction(function () use ($studentId, $date, $minutes, $extra) {
            $log = StudyDailyLog::query()->where(['student_id' => $studentId, 'log_date' => $date])->lockForUpdate()->first();
            $values = [];
            foreach (Category::keys() as $key) {
                $values[Category::column($key)] = (int) ($minutes[$key] ?? 0);
            }
            $values += [
                'is_rest_day' => (bool) ($extra['is_rest_day'] ?? false),
                'rating' => $extra['rating'] ?? null,
                'notes' => $extra['notes'] ?? null,
                'is_sample' => false, // anything the student saves is a real record, even over a sample day
            ];

            if ($log === null) {
                $log = StudyDailyLog::create(['student_id' => $studentId, 'log_date' => $date] + $values);
                AuditLog::record('study_daily_logs', $log->id, 'created', null, $values + ['log_date' => $date]);

                return $log;
            }

            $log->fill($values);
            if ($log->isDirty()) {
                AuditLog::record('study_daily_logs', $log->id, 'updated', array_intersect_key($log->getOriginal(), $log->getDirty()), $log->getDirty());
                $log->save();
            }

            return $log;
        });
    }

    /** Warnings when detailed allocations exceed the day's category total; unallocated time is fine. */
    public function allocationWarnings(int $studentId, string $date): array
    {
        $log = StudyDailyLog::query()->where(['student_id' => $studentId, 'log_date' => $date])->first();
        $warnings = [];

        $theory = (int) TheoryStudyEntry::query()->where(['student_id' => $studentId, 'study_date' => $date])->sum('minutes');
        $papers = (int) QuestionAttempt::query()->where(['student_id' => $studentId, 'attempt_date' => $date])->sum('worked_minutes');

        foreach ([['Study Theory', $theory, $log?->theory_minutes], ['Write Exam Papers', $papers, $log?->papers_minutes]] as [$label, $allocated, $total]) {
            if ($allocated === 0) {
                continue;
            }
            if ($total === null) {
                $warnings[] = __(':time of :label time is allocated on :date but no daily log has been entered for that day.', ['time' => Duration::format($allocated), 'label' => __($label), 'date' => $date]);
            } elseif ($allocated > $total) {
                $warnings[] = __(':label allocated in detail (:time) is more than the daily :label total (:total). Daily totals are not increased; check the entries.', ['label' => __($label), 'time' => Duration::format($allocated), 'total' => Duration::format($total)]);
            }
        }

        return $warnings;
    }
}
