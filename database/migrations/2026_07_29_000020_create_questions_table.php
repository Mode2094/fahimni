<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('course_id')->nullable();
            $table->uuid('section_id')->nullable();
            $table->string('question_type', 20);
            $table->text('question_text');
            $table->string('difficulty', 10)->default('medium');
            $table->string('category', 100)->nullable();
            $table->decimal('points', 5, 2)->default(1.00);
            $table->text('explanation')->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'course_id']);
            $table->index(['tenant_id', 'question_type']);
            $table->index(['tenant_id', 'difficulty']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('course_id')->references('id')->on('courses')->nullOnDelete();
            $table->foreign('section_id')->references('id')->on('sections')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
