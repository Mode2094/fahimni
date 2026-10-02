<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->uuid('course_id')->nullable()->after('lesson_content_id');
            $table->foreign('course_id')->references('id')->on('courses')->nullOnDelete();
            $table->index(['tenant_id', 'course_id', 'status'], 'idx_live_sessions_tenant_course_status');
        });
    }

    public function down(): void
    {
        Schema::table('live_sessions', function (Blueprint $table) {
            $table->dropIndex('idx_live_sessions_tenant_course_status');
            $table->dropForeign(['course_id']);
            $table->dropColumn('course_id');
        });
    }
};
