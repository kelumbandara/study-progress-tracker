<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lets demo logs be told apart from real ones so they can be removed safely.
        Schema::table('study_daily_logs', function (Blueprint $t) {
            $t->boolean('is_sample')->default(false)->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('study_daily_logs', function (Blueprint $t) {
            $t->dropColumn('is_sample');
        });
    }
};
