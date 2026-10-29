<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('study_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->unique()->constrained('student_profiles')->cascadeOnDelete();
            $t->date('study_start');
            $t->date('study_end');
            $t->date('exam_date')->nullable();
            $t->string('timezone', 64)->default('Asia/Colombo');
            // Past-paper year range (configurable, see spec 7)
            $t->unsignedSmallInteger('paper_year_from')->default(2016);
            $t->unsignedSmallInteger('paper_year_to')->default(2025);
            $t->timestamps();
        });

        // Current planned minutes per category; NULL = "Not Configured".
        Schema::create('study_targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('student_profiles')->cascadeOnDelete();
            $t->enum('category', ['school', 'tuition', 'theory', 'papers']);
            $t->unsignedInteger('planned_minutes')->nullable();
            $t->timestamps();
            $t->unique(['student_id', 'category']);
        });

        // Every change is kept so original and revised targets can both be shown.
        Schema::create('study_targets_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('student_profiles')->cascadeOnDelete();
            $t->enum('category', ['school', 'tuition', 'theory', 'papers']);
            $t->unsignedInteger('old_minutes')->nullable();
            $t->unsignedInteger('new_minutes')->nullable();
            $t->string('reason')->nullable();
            $t->timestamp('changed_at')->useCurrent();
            $t->index(['student_id', 'category', 'changed_at']);
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->nullable()->constrained('student_profiles')->nullOnDelete();
            $t->string('entity_type', 64);
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->string('action', 32); // created | updated | deleted
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('study_targets_history');
        Schema::dropIfExists('study_targets');
        Schema::dropIfExists('study_settings');
        Schema::dropIfExists('student_profiles');
    }
};
