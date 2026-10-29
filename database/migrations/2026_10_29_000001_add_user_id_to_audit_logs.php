<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who made the change. Deliberately no foreign key: deleting a user must never erase history.
        Schema::table('audit_logs', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->nullable()->after('student_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $t) {
            $t->dropColumn('user_id');
        });
    }
};
