<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Authoritative source of all time totals. One row per student per day.
        Schema::create('study_daily_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('student_profiles')->cascadeOnDelete();
            $t->date('log_date');
            $t->unsignedSmallInteger('school_minutes')->default(0);
            $t->unsignedSmallInteger('tuition_minutes')->default(0);
            $t->unsignedSmallInteger('theory_minutes')->default(0);
            $t->unsignedSmallInteger('papers_minutes')->default(0);
            $t->boolean('is_rest_day')->default(false);
            $t->unsignedTinyInteger('rating')->nullable(); // 1..5 difficulty/motivation
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['student_id', 'log_date']);
        });

        Schema::create('theory_study_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('student_profiles')->cascadeOnDelete();
            $t->date('study_date');
            $t->foreignId('topic_id')->constrained('syllabus_topics')->cascadeOnDelete();
            $t->unsignedSmallInteger('minutes');          // allocation of the day's theory time
            $t->boolean('marked_complete')->default(false);
            $t->unsignedTinyInteger('confidence')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['student_id', 'study_date']);
        });

        Schema::create('exam_papers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('exam_year');
            $t->string('paper_name', 32);                 // e.g. "Paper I"
            $t->string('exam_type', 64)->nullable();
            $t->unsignedSmallInteger('max_marks')->nullable();
            $t->unsignedSmallInteger('duration_minutes')->nullable();
            $t->string('paper_url', 512)->nullable();
            $t->string('marking_scheme_url', 512)->nullable();
            $t->enum('availability', ['available', 'unavailable', 'unknown'])->default('unknown');
            $t->boolean('is_sample')->default(false);
            $t->timestamps();
            $t->unique(['subject_id', 'exam_year', 'paper_name']);
        });

        Schema::create('exam_questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('paper_id')->constrained('exam_papers')->cascadeOnDelete();
            $t->string('question_no', 16);
            $t->decimal('max_marks', 6, 2);
            $t->string('reference', 64)->nullable();      // PDF page etc.
            $t->text('notes')->nullable();
            $t->boolean('is_sample')->default(false);
            $t->timestamps();
            $t->unique(['paper_id', 'question_no']);
        });

        Schema::create('question_topic_links', function (Blueprint $t) {
            $t->foreignId('question_id')->constrained('exam_questions')->cascadeOnDelete();
            $t->foreignId('topic_id')->constrained('syllabus_topics')->cascadeOnDelete();
            $t->primary(['question_id', 'topic_id']);
        });

        Schema::create('paper_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('student_profiles')->cascadeOnDelete();
            $t->foreignId('paper_id')->constrained('exam_papers')->cascadeOnDelete();
            $t->date('attempt_date');
            $t->unsignedTinyInteger('attempt_no')->default(1);
            $t->decimal('marks_earned', 7, 2)->nullable();   // manual total, ignored when question rows exist
            $t->decimal('max_marks', 7, 2)->nullable();
            $t->unsignedSmallInteger('worked_minutes')->nullable();
            $t->boolean('independent')->default(true);
            $t->boolean('timed')->default(false);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['paper_id', 'attempt_date']);
        });

        // Append-only history; a retry never overwrites an earlier row.
        Schema::create('question_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('student_profiles')->cascadeOnDelete();
            $t->foreignId('question_id')->constrained('exam_questions')->cascadeOnDelete();
            $t->foreignId('paper_attempt_id')->nullable()->constrained('paper_attempts')->nullOnDelete();
            $t->date('attempt_date');
            $t->unsignedSmallInteger('attempt_no');
            $t->decimal('marks_earned', 6, 2);
            $t->decimal('max_marks', 6, 2);               // snapshot at attempt time
            $t->unsignedSmallInteger('worked_minutes')->nullable(); // allocation of papers time
            $t->enum('result', ['correct', 'partial', 'wrong', 'not_completed']);
            $t->boolean('independent')->default(true);
            $t->boolean('checked_with_scheme')->default(false);
            $t->enum('mistake_category', ['conceptual', 'formula_method', 'calculation', 'interpretation', 'time_management', 'incomplete', 'other'])->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['student_id', 'question_id', 'attempt_no']);
            $t->index(['question_id', 'attempt_date']);
        });

        Schema::create('mistake_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('student_profiles')->cascadeOnDelete();
            $t->foreignId('question_id')->constrained('exam_questions')->cascadeOnDelete();
            $t->foreignId('attempt_id')->nullable()->constrained('question_attempts')->nullOnDelete();
            $t->date('reviewed_on');
            $t->boolean('theory_reviewed')->default(false);
            $t->boolean('solution_understood')->default(false);
            $t->boolean('reattempted')->default(false);
            $t->boolean('mastered_independently')->default(false);
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['question_id', 'reviewed_on']);
        });

        Schema::create('imports', function (Blueprint $t) {
            $t->id();
            $t->string('type', 32);        // syllabus | papers | questions
            $t->string('filename');
            $t->unsignedInteger('rows_ok')->default(0);
            $t->unsignedInteger('rows_failed')->default(0);
            $t->json('errors')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imports');
        Schema::dropIfExists('mistake_reviews');
        Schema::dropIfExists('question_attempts');
        Schema::dropIfExists('paper_attempts');
        Schema::dropIfExists('question_topic_links');
        Schema::dropIfExists('exam_questions');
        Schema::dropIfExists('exam_papers');
        Schema::dropIfExists('theory_study_entries');
        Schema::dropIfExists('study_daily_logs');
    }
};
