<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Minutes in the foreground per app per day, sent by the phone app. A day with no rows is MISSING, not zero.
        Schema::create('phone_app_usage', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('student_profiles')->cascadeOnDelete();
            $t->date('usage_date');
            $t->string('package', 190);
            $t->string('label', 120);
            $t->unsignedSmallInteger('minutes');
            $t->string('category', 20);
            $t->timestamps();
            $t->unique(['student_id', 'usage_date', 'package']);
            $t->index(['student_id', 'usage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_app_usage');
    }
};
