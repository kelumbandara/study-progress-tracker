<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\StudentProfile;
use App\Models\StudySetting;
use App\Models\StudyTarget;
use App\Models\StudyTargetHistory;
use App\Models\Subject;
use App\Support\Category;
use Illuminate\Support\Facades\DB;

/** Saves study period, planned hours and subject settings; every change is audited. */
class SettingsService
{
    /**
     * @param array $data validated input: period fields, 'hours' (category => decimal hours|null),
     *                    'reason', and 'subjects' (id => priority/weak_score_pct/slow_minutes_per_mark)
     */
    public function save(StudentProfile $student, array $data): void
    {
        DB::transaction(function () use ($student, $data) {
            $this->savePeriod($student, $data);
            $this->saveTargets($student, $data['hours'] ?? [], $data['reason'] ?? null);
            $this->saveSubjects($data['subjects'] ?? []);
        });
    }

    private function savePeriod(StudentProfile $student, array $data): void
    {
        $settings = StudySetting::query()->where('student_id', $student->id)->firstOrFail();
        $settings->fill([
            'study_start' => $data['study_start'],
            'study_end' => $data['study_end'],
            'exam_date' => $data['exam_date'] ?? null,
            'timezone' => $data['timezone'],
            'paper_year_from' => $data['paper_year_from'],
            'paper_year_to' => $data['paper_year_to'],
        ]);

        if ($settings->isDirty()) {
            AuditLog::record('study_settings', $settings->id, 'updated', $settings->getOriginal(), $settings->getDirty());
            $settings->save();
        }
    }

    private function saveTargets(StudentProfile $student, array $hours, ?string $reason): void
    {
        foreach (Category::keys() as $key) {
            if (! array_key_exists($key, $hours)) {
                continue;
            }
            $new = \App\Support\Duration::fromHours($hours[$key]);
            $target = StudyTarget::query()->firstOrCreate(['student_id' => $student->id, 'category' => $key]);
            $old = $target->planned_minutes;

            if ($old === $new) {
                continue;
            }
            $target->update(['planned_minutes' => $new]);
            StudyTargetHistory::create([
                'student_id' => $student->id, 'category' => $key,
                'old_minutes' => $old, 'new_minutes' => $new, 'reason' => $reason,
            ]);
            AuditLog::record('study_targets', $target->id, 'updated', ['planned_minutes' => $old], ['planned_minutes' => $new]);
        }
    }

    private function saveSubjects(array $subjects): void
    {
        foreach ($subjects as $id => $values) {
            $subject = Subject::query()->findOrFail($id);
            $subject->fill([
                'priority' => $values['priority'],
                'weak_score_pct' => $values['weak_score_pct'],
                'slow_minutes_per_mark' => ($values['slow_minutes_per_mark'] ?? '') === '' ? null : $values['slow_minutes_per_mark'],
            ]);
            if ($subject->isDirty()) {
                AuditLog::record('subjects', $subject->id, 'updated', $subject->getOriginal(), $subject->getDirty());
                $subject->save();
            }
        }
    }
}
