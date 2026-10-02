<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->unsignedInteger('random_questions_count')->nullable()->after('max_attempts');
            $table->boolean('anti_cheat_enabled')->default(true)->after('random_questions_count');
        });
    }

    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn(['random_questions_count', 'anti_cheat_enabled']);
        });
    }
};
