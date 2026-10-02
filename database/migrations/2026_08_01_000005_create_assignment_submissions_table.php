<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('assignment_id');
            $table->uuid('user_id');
            $table->text('submission_text')->nullable();
            $table->string('file_url', 500)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->bigInteger('file_size_bytes')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->string('status', 20)->default('submitted');
            $table->decimal('grade', 8, 2)->nullable();
            $table->string('grade_comment', 1000)->nullable();
            $table->timestampTz('graded_at')->nullable();
            $table->uuid('graded_by')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['assignment_id', 'user_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['user_id', 'submitted_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('assignment_id')->references('id')->on('assignments')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('graded_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_submissions');
    }
};
