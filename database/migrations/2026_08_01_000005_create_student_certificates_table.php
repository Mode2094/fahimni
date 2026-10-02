<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('course_id');
            $table->uuid('enrollment_id')->nullable();
            $table->string('certificate_number', 40)->unique();
            $table->timestampTz('issued_at')->nullable();
            $table->string('status', 20)->default('issued');
            $table->string('template', 40)->default('classic');
            $table->string('accent_color', 20)->default('#2563eb');
            $table->string('signature_name', 255)->nullable();
            $table->string('signature_title', 255)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
            $table->foreign('enrollment_id')->references('id')->on('enrollments')->nullOnDelete();

            $table->index(['user_id', 'course_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_certificates');
    }
};
