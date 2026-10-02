<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('exam_id');
            $table->uuid('user_id');
            $table->integer('attempt_number');
            $table->string('status', 20)->default('in_progress');
            $table->decimal('score', 5, 2)->nullable();
            $table->decimal('total_points', 5, 2)->nullable();
            $table->decimal('percentage', 5, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('submitted_at')->nullable();
            $table->integer('time_spent_seconds')->default(0);
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->unique(['exam_id', 'user_id', 'attempt_number']);
            $table->index(['exam_id', 'user_id', 'status']);
            $table->foreign('exam_id')->references('id')->on('exams')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_attempts');
    }
};
