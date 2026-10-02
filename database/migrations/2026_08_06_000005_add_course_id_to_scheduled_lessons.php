<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_lessons', function (Blueprint $table) {
            $table->uuid('course_id')->nullable()->after('tenant_id');
            $table->foreign('course_id')->references('id')->on('courses')->nullOnDelete();
            $table->index(['course_id', 'status', 'lesson_date'], 'idx_scheduled_lessons_course_status');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_lessons', function (Blueprint $table) {
            $table->dropIndex('idx_scheduled_lessons_course_status');
            $table->dropForeign(['course_id']);
            $table->dropColumn('course_id');
        });
    }
};
