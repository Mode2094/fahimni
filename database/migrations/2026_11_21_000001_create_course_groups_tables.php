<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course groups: named class groups INSIDE one course ("Grade 10 — A/B/C").
 *
 * Deliberately separate from `community_groups` (the forum) and from academic
 * terms (the enrolment cycles). Membership here never creates an enrolment, so
 * a student in several groups still occupies a single seat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('course_id');
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'course_id', 'sort_order']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
        });

        // A student may belong to more than one group (rules permitting), and
        // membership never touches enrolments/seats.
        Schema::create('course_group_members', function (Blueprint $table) {
            $table->uuid('course_group_id');
            $table->uuid('user_id');
            $table->timestampsTz();

            $table->primary(['course_group_id', 'user_id']);
            $table->index('user_id');
            $table->foreign('course_group_id')->references('id')->on('course_groups')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Lesson restricted to a group. A lesson with no rows is visible to
        // everyone enrolled; once it has rows it is visible only to members of
        // those groups.
        Schema::create('course_group_lesson', function (Blueprint $table) {
            $table->uuid('course_group_id');
            $table->uuid('lesson_id');
            $table->timestampsTz();

            $table->primary(['course_group_id', 'lesson_id']);
            $table->index('lesson_id');
            $table->foreign('course_group_id')->references('id')->on('course_groups')->cascadeOnDelete();
            $table->foreign('lesson_id')->references('id')->on('lessons')->cascadeOnDelete();
        });

        // Exam restricted to a group (same "no rows = everyone" rule).
        Schema::create('course_group_exam', function (Blueprint $table) {
            $table->uuid('course_group_id');
            $table->uuid('exam_id');
            $table->timestampsTz();

            $table->primary(['course_group_id', 'exam_id']);
            $table->index('exam_id');
            $table->foreign('course_group_id')->references('id')->on('course_groups')->cascadeOnDelete();
            $table->foreign('exam_id')->references('id')->on('exams')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_group_exam');
        Schema::dropIfExists('course_group_lesson');
        Schema::dropIfExists('course_group_members');
        Schema::dropIfExists('course_groups');
    }
};
