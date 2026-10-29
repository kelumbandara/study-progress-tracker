<?php

namespace Tests\Feature;

use App\Models\ExamPaper;
use App\Models\ExamQuestion;
use App\Models\SyllabusTopic;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoadLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_server_gets_the_syllabus_papers_and_2024_questions_and_a_repeat_adds_nothing(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->artisan('tracker:load-library')->assertSuccessful();
        $counts = [SyllabusTopic::count(), ExamPaper::count(), ExamQuestion::count()];
        $this->assertSame(60, $counts[1]);              // 3 subjects x 10 years x Paper I and II
        $this->assertSame(154, $counts[2]);             // the verified 2024 questions
        $this->assertGreaterThan(100, $counts[0]);

        $this->artisan('tracker:load-library')->assertSuccessful();
        $this->assertSame($counts, [SyllabusTopic::count(), ExamPaper::count(), ExamQuestion::count()]);

        // Only the 2024 papers carry verified durations and links; nothing is invented for other years.
        $this->assertSame(6, ExamPaper::whereNotNull('paper_url')->count());
        $this->assertSame(0, ExamPaper::where('exam_year', 2023)->whereNotNull('duration_minutes')->count());
    }
}
