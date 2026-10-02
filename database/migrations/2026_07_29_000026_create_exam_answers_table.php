<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('exam_attempt_id');
            $table->uuid('exam_question_id');
            $table->uuid('question_id');
            $table->jsonb('given_answer');
            $table->boolean('is_correct')->nullable();
            $table->decimal('points_earned', 5, 2)->default(0);
            $table->decimal('points_max', 5, 2);
            $table->integer('time_spent_seconds')->default(0);
            $table->timestampsTz();

            $table->unique(['exam_attempt_id', 'exam_question_id']);
            $table->index('exam_attempt_id');
            $table->foreign('exam_attempt_id')->references('id')->on('exam_attempts')->cascadeOnDelete();
            $table->foreign('exam_question_id')->references('id')->on('exam_questions')->cascadeOnDelete();
            $table->foreign('question_id')->references('id')->on('questions')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_answers');
    }
};
