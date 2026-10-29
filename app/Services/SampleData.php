<?php

namespace App\Services;

use App\Models\ExamPaper;
use App\Models\ExamQuestion;
use App\Models\PaperAttempt;
use App\Models\QuestionAttempt;
use App\Models\StudentProfile;
use App\Models\StudyDailyLog;
use App\Models\StudySetting;
use App\Models\Subject;
use App\Models\SyllabusTopic;
use App\Models\SyllabusUnit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Demo data so the dashboard and reports can be seen working. Everything is flagged is_sample,
 * hangs off sample units/papers (so removal cascades cleanly) and is never mixed with real records.
 * These are NOT real syllabus topics, papers or marks.
 */
class SampleData
{
    public function __construct(private AttemptService $attempts, private TheoryService $theory)
    {
    }

    public function exists(): bool
    {
        return SyllabusUnit::where('is_sample', true)->exists() || ExamPaper::where('is_sample', true)->exists() || StudyDailyLog::where('is_sample', true)->exists();
    }

    /** What removal would delete, for the confirmation message. */
    public function counts(): array
    {
        $papers = ExamPaper::where('is_sample', true)->pluck('id');
        $questions = ExamQuestion::whereIn('paper_id', $papers)->pluck('id');

        return [
            'units' => SyllabusUnit::where('is_sample', true)->count(),
            'papers' => $papers->count(),
            'questions' => $questions->count(),
            'attempts' => QuestionAttempt::whereIn('question_id', $questions)->count() + PaperAttempt::whereIn('paper_id', $papers)->count(),
            'logs' => StudyDailyLog::where('is_sample', true)->count(),
        ];
    }

    public function seed(): bool
    {
        if ($this->exists()) {
            return false;
        }
        $student = StudentProfile::current();
        $settings = StudySetting::where('student_id', $student->id)->firstOrFail();
        $today = CarbonImmutable::now($settings->timezone)->startOfDay();

        DB::transaction(function () use ($student, $settings, $today) {
            $this->logs($student->id, $today);
            foreach (Subject::orderBy('sort_order')->get() as $subject) {
                $this->subject($student->id, $subject, $settings->paper_year_to, $today);
            }
        });

        return true;
    }

    public function remove(): array
    {
        $counts = $this->counts();
        DB::transaction(function () {
            StudyDailyLog::where('is_sample', true)->delete();
            ExamPaper::where('is_sample', true)->delete();      // cascades to questions, links, question and paper attempts
            SyllabusUnit::where('is_sample', true)->delete();   // cascades to topics, theory entries, status history
        });

        return $counts;
    }

    /** Last 14 days; a date that already has a real log is left alone. */
    private function logs(int $studentId, CarbonImmutable $today): void
    {
        for ($i = 14; $i >= 1; $i--) {
            $date = $today->subDays($i);
            if (StudyDailyLog::where(['student_id' => $studentId, 'log_date' => $date->toDateString()])->exists()) {
                continue;
            }
            $weekday = $date->dayOfWeekIso <= 5;
            StudyDailyLog::create([
                'student_id' => $studentId, 'log_date' => $date->toDateString(), 'is_sample' => true,
                'school_minutes' => $weekday ? 330 : 0, 'tuition_minutes' => in_array($date->dayOfWeekIso, [2, 4, 6], true) ? 120 : 0,
                'theory_minutes' => 45 + ($i % 4) * 15, 'papers_minutes' => 30 + ($i % 3) * 30, 'is_rest_day' => $date->dayOfWeekIso === 7,
                'notes' => 'SAMPLE DATA',
            ]);
        }
    }

    private function subject(int $studentId, Subject $subject, int $year, CarbonImmutable $today): void
    {
        $unit = SyllabusUnit::create(['subject_id' => $subject->id, 'name' => "SAMPLE unit ({$subject->name})", 'reference' => 'SAMPLE', 'is_sample' => true, 'sort_order' => 9000]);
        $topics = [];
        foreach (['A', 'B', 'C'] as $i => $letter) {
            $topics[] = SyllabusTopic::create(['unit_id' => $unit->id, 'name' => "SAMPLE topic {$letter}", 'reference' => 'SAMPLE', 'sort_order' => $i, 'is_sample' => true]);
        }
        $paper = ExamPaper::create(['subject_id' => $subject->id, 'exam_year' => $year, 'paper_name' => 'SAMPLE Paper', 'max_marks' => 100, 'duration_minutes' => 180, 'availability' => 'unknown', 'is_sample' => true]);

        $questions = [];
        foreach ([[1, 10, [0]], [2, 10, [0, 1]], [3, 15, [1]], [4, 15, [2]]] as [$no, $max, $links]) {
            $q = ExamQuestion::create(['paper_id' => $paper->id, 'question_no' => (string) $no, 'max_marks' => $max, 'notes' => 'SAMPLE DATA', 'is_sample' => true]);
            $q->topics()->sync(array_map(fn ($l) => $topics[$l]->id, $links));
            $questions[$no] = $q;
        }

        // [question, days ago, marks, independent, minutes] - maths is weak, physics middling, IT strong (matches the brief's starting position)
        $plans = [
            'high' => [[1, 12, 3, 1, 35], [1, 8, 5, 1, 30], [1, 3, 8, 1, 22], [2, 11, 4, 1, 28], [2, 6, 4, 0, 26], [3, 10, 5, 1, 40], [4, 9, 6, 1, 38], [4, 4, 7, 1, 33]],
            'medium' => [[1, 12, 6, 1, 25], [1, 7, 8, 1, 20], [2, 11, 6, 1, 24], [3, 9, 9, 1, 35], [3, 5, 11, 1, 30], [4, 8, 8, 0, 36]],
            'maintain' => [[1, 12, 9, 1, 15], [2, 11, 9, 1, 14], [3, 10, 13, 1, 25], [3, 5, 14, 1, 22], [4, 9, 13, 1, 24], [4, 3, 15, 1, 20]],
        ][$subject->priority] ?? [];

        foreach ($plans as [$no, $ago, $marks, $independent, $minutes]) {
            $q = $questions[$no];
            $this->attempts->recordQuestion($studentId, [
                'question_id' => $q->id, 'attempt_date' => $today->subDays($ago)->toDateString(), 'marks_earned' => $marks,
                'result' => $marks >= $q->max_marks ? 'correct' : ($marks >= $q->max_marks * 0.4 ? 'partial' : 'wrong'),
                'independent' => (bool) $independent, 'worked_minutes' => $minutes, 'notes' => 'SAMPLE DATA',
            ]);
        }

        $score = ['high' => 42, 'medium' => 58, 'maintain' => 78][$subject->priority] ?? 50;
        $this->attempts->recordPaper($studentId, ['paper_id' => $paper->id, 'attempt_date' => $today->subDays(2)->toDateString(), 'marks_earned' => $score, 'max_marks' => 100,
            'worked_minutes' => 175, 'independent' => true, 'timed' => true, 'notes' => 'SAMPLE DATA']);

        foreach ([[$topics[0], 13, 45, true, 4], [$topics[1], 10, 60, true, 3], [$topics[2], 6, 30, false, 2]] as [$topic, $ago, $minutes, $complete, $conf]) {
            $this->theory->record($studentId, ['study_date' => $today->subDays($ago)->toDateString(), 'topic_id' => $topic->id, 'minutes' => $minutes,
                'marked_complete' => $complete, 'confidence' => $conf, 'notes' => 'SAMPLE DATA']);
        }
    }
}
