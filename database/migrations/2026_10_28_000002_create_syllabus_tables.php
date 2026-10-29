<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subjects', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
            $t->enum('priority', ['high', 'medium', 'maintain'])->default('medium');
            $t->unsignedTinyInteger('weak_score_pct')->default(50);          // below this = weak
            $t->decimal('slow_minutes_per_mark', 5, 2)->nullable();          // above this = slow; NULL = not configured
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('syllabus_units', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('reference', 64)->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_sample')->default(false);
            $t->timestamps();
            $t->unique(['subject_id', 'name']);
        });

        // parent_id gives Topic -> Subtopic.
        Schema::create('syllabus_topics', function (Blueprint $t) {
            $t->id();
            $t->foreignId('unit_id')->constrained('syllabus_units')->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('syllabus_topics')->cascadeOnDelete();
            $t->string('name');
            $t->string('reference', 64)->nullable();
            $t->unsignedInteger('estimated_minutes')->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->enum('status', ['not_started', 'in_progress', 'theory_completed', 'questions_practised', 'revision_required', 'mastered'])
                ->default('not_started');
            $t->date('theory_completed_on')->nullable();
            $t->unsignedInteger('theory_minutes')->default(0);   // allocation of daily theory time, never added to totals
            $t->unsignedTinyInteger('confidence')->nullable();   // 1..5
            $t->boolean('is_sample')->default(false);
            $t->timestamps();
            $t->index(['unit_id', 'parent_id']);
        });

        Schema::create('topic_status_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('topic_id')->constrained('syllabus_topics')->cascadeOnDelete();
            $t->string('from_status', 32)->nullable();
            $t->string('to_status', 32);
            $t->string('note')->nullable();
            $t->timestamp('changed_at')->useCurrent();
            $t->index(['topic_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_status_history');
        Schema::dropIfExists('syllabus_topics');
        Schema::dropIfExists('syllabus_units');
        Schema::dropIfExists('subjects');
    }
};
