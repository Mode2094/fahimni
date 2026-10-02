<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('exam_id');
            $table->uuid('exam_section_id')->nullable();
            $table->uuid('question_id');
            $table->integer('sort_order')->default(0);
            $table->decimal('points_override', 5, 2)->nullable();
            $table->timestampsTz();

            $table->unique(['exam_id', 'question_id']);
            $table->index(['exam_id', 'sort_order']);
            $table->foreign('exam_id')->references('id')->on('exams')->cascadeOnDelete();
            $table->foreign('exam_section_id')->references('id')->on('exam_sections')->nullOnDelete();
            $table->foreign('question_id')->references('id')->on('questions')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_questions');
    }
};
