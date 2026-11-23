<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-lesson student notes (with an optional timestamp into the video) and a
 * simple lesson Q&A: a student asks on a lesson, the teacher answers.
 *
 * Both are owned by the asking/noting student; staff of the tenant can read
 * them. They are deliberately NOT part of exam questions (a different model)
 * so the two can never be confused or share an answer key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('lesson_id');
            $table->uuid('course_id');
            $table->integer('timestamp_seconds')->nullable();
            $table->text('body');
            $table->boolean('is_shared')->default(false);
            $table->timestampsTz();

            $table->index(['tenant_id', 'user_id', 'lesson_id']);
            $table->index(['tenant_id', 'lesson_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('lesson_id')->references('id')->on('lessons')->cascadeOnDelete();
            $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
        });

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
        Schema::dropIfExists('lesson_notes');
    }
};
