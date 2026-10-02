<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->index('course_id', 'idx_announcements_course_id');
            $table->index('sender_id', 'idx_announcements_sender_id');
        });

        Schema::table('assignment_comments', function (Blueprint $table) {
            $table->index('user_id', 'idx_assignment_comments_user_id');
            $table->index('tenant_id', 'idx_assignment_comments_tenant_id');
        });

        Schema::table('assignment_submissions', function (Blueprint $table) {
            $table->index('graded_by', 'idx_assignment_submissions_graded_by');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->index('section_id', 'idx_assignments_section_id');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->index('service_id', 'idx_bookings_service_id');
        });

        Schema::table('community_comments', function (Blueprint $table) {
            $table->index('tenant_id', 'idx_community_comments_tenant_id');
        });

        Schema::table('community_groups', function (Blueprint $table) {
            $table->index('created_by', 'idx_community_groups_created_by');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->index('course_id', 'idx_conversations_course_id');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->index('teacher_id', 'idx_courses_teacher_id');
        });

        Schema::table('exam_answers', function (Blueprint $table) {
            $table->index('question_id', 'idx_exam_answers_question_id');
        });

        Schema::table('exams', function (Blueprint $table) {
            $table->index('section_id', 'idx_exams_section_id');
        });

        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->index('user_id', 'idx_lesson_progress_user_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index('booking_id', 'idx_payments_booking_id');
        });

        Schema::table('student_certificates', function (Blueprint $table) {
            $table->index('enrollment_id', 'idx_student_certificates_enrollment_id');
        });

        Schema::table('teacher_invitations', function (Blueprint $table) {
            $table->index('invited_by', 'idx_teacher_invitations_invited_by');
        });

        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->index('user_id', 'idx_ticket_messages_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table) {
            $table->dropIndex('idx_ticket_messages_user_id');
        });

        Schema::table('teacher_invitations', function (Blueprint $table) {
            $table->dropIndex('idx_teacher_invitations_invited_by');
        });

        Schema::table('student_certificates', function (Blueprint $table) {
            $table->dropIndex('idx_student_certificates_enrollment_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_payments_booking_id');
        });

        Schema::table('lesson_progress', function (Blueprint $table) {
            $table->dropIndex('idx_lesson_progress_user_id');
        });

        Schema::table('exams', function (Blueprint $table) {
            $table->dropIndex('idx_exams_section_id');
        });

        Schema::table('exam_answers', function (Blueprint $table) {
            $table->dropIndex('idx_exam_answers_question_id');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex('idx_courses_teacher_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('idx_conversations_course_id');
        });

        Schema::table('community_groups', function (Blueprint $table) {
            $table->dropIndex('idx_community_groups_created_by');
        });

        Schema::table('community_comments', function (Blueprint $table) {
            $table->dropIndex('idx_community_comments_tenant_id');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('idx_bookings_service_id');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->dropIndex('idx_assignments_section_id');
        });

        Schema::table('assignment_submissions', function (Blueprint $table) {
            $table->dropIndex('idx_assignment_submissions_graded_by');
        });

        Schema::table('assignment_comments', function (Blueprint $table) {
            $table->dropIndex('idx_assignment_comments_tenant_id');
            $table->dropIndex('idx_assignment_comments_user_id');
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex('idx_announcements_sender_id');
            $table->dropIndex('idx_announcements_course_id');
        });
    }
};
