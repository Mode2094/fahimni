<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->index(['tenant_id', 'user_id', 'status'], 'idx_enrollments_tenant_user_status');
            $table->index(['tenant_id', 'course_id', 'status', 'enrolled_at'], 'idx_enrollments_tenant_course_status_enrolled');
        });

        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->index(['tenant_id', 'user_id', 'status'], 'idx_lesson_progress_tenant_user_status');
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->index(['tenant_id', 'user_id', 'status'], 'idx_exam_attempts_tenant_user_status');
        });

        Schema::table('student_points', function (Blueprint $table) {
            $table->index(['user_id', 'reference_type', 'reference_id'], 'idx_student_points_user_ref');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_id', 'tenant_id', 'read_at'], 'idx_notifications_notifiable_tenant_read');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['tenant_id', 'status'], 'idx_payments_tenant_status');
        });

        Schema::table('teacher_invitations', function (Blueprint $table) {
            $table->index('email', 'idx_teacher_invitations_email');
        });

        Schema::table('student_device_limits', function (Blueprint $table) {
            $table->index('student_id', 'idx_student_device_limits_student_id');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->index(['tenant_id', 'section_id'], 'idx_questions_tenant_section');
        });

        Schema::table('tenant_user', function (Blueprint $table) {
            $table->index(['tenant_id', 'role', 'status'], 'idx_tenant_user_tenant_role_status');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX idx_community_threads_tags_gin ON community_threads USING GIN (tags)');
        }
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropIndex('idx_enrollments_tenant_user_status');
            $table->dropIndex('idx_enrollments_tenant_course_status_enrolled');
        });

        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->dropIndex('idx_lesson_progress_tenant_user_status');
        });

        Schema::table('exam_attempts', function (Blueprint $table) {
            $table->dropIndex('idx_exam_attempts_tenant_user_status');
        });

        Schema::table('student_points', function (Blueprint $table) {
            $table->dropIndex('idx_student_points_user_ref');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('idx_notifications_notifiable_tenant_read');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payments_tenant_status');
        });

        Schema::table('tenant_user', function (Blueprint $table) {
            $table->dropIndex('idx_tenant_user_tenant_role_status');
        });

        Schema::table('teacher_invitations', function (Blueprint $table) {
            $table->dropIndex('idx_teacher_invitations_email');
        });

        Schema::table('student_device_limits', function (Blueprint $table) {
            $table->dropIndex('idx_student_device_limits_student_id');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->dropIndex('idx_questions_tenant_section');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_community_threads_tags_gin');
        }
    }
};
