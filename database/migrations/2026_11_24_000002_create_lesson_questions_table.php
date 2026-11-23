<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-lesson Q&A: a student asks on a lesson, the teacher answers.
 *
 * The question is owned by the asking student; the tenant's staff can read it
 * and answer it. Deliberately separate from `lesson_notes` (student's private
 * notes) and from exam questions (a different model with an answer key) so the
 * three can never be confused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('lesson_id');
            $table->uuid('course_id');
            $table->text('body');
            $table->text('answer')->nullable();
            $table->uuid('answered_by')->nullable();
            $table->timestampTz('answered_at')->nullable();
            $table->boolean('is_public')->default(true);
            $table->timestampsTz();

            $table->index(['tenant_id', 'course_id', 'created_at']);
            $table->index(['tenant_id', 'lesson_id']);
            $table->index(['tenant_id', 'answered_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('lesson_id')->references('id')->on('lessons')->cascadeOnDelete();
            $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
            $table->foreign('answered_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_questions');
    }
};
