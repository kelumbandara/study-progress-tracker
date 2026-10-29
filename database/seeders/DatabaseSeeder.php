<?php

namespace Database\Seeders;

use App\Models\StudentProfile;
use App\Models\StudySetting;
use App\Models\StudyTarget;
use App\Models\Subject;
use App\Support\Category;
use Illuminate\Database\Seeder;

/**
 * Foundation data only: one student, the default study period, three subjects
 * and unset ("Not Configured") planned hours. No syllabus or paper data is invented.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $student = StudentProfile::firstOrCreate(['id' => 1], ['name' => 'Student']);

        StudySetting::firstOrCreate(['student_id' => $student->id], [
            'study_start' => '2026-10-28',
            'study_end' => '2027-07-31',
            'exam_date' => null,
            'timezone' => 'Asia/Colombo',
            'paper_year_from' => 2016,
            'paper_year_to' => 2025,
        ]);

        foreach (Category::keys() as $key) {
            StudyTarget::firstOrCreate(['student_id' => $student->id, 'category' => $key], ['planned_minutes' => null]);
        }

        foreach ([
            ['Combined Mathematics', 'high', 1],
            ['Physics', 'medium', 2],
            ['IT', 'maintain', 3],
        ] as [$name, $priority, $order]) {
            Subject::firstOrCreate(['name' => $name], ['priority' => $priority, 'sort_order' => $order]);
        }
    }
}
