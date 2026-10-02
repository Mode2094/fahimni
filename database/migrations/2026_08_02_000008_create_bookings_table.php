<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('teacher_id');
            $table->uuid('student_id');
            $table->uuid('service_id')->nullable();
            $table->string('kind', 20)->default('appointment');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 20)->default('pending');
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 3)->default('JOD');
            $table->uuid('payment_id')->nullable();
            $table->text('notes')->nullable();
            $table->string('meeting_url', 500)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'teacher_id', 'status']);
            $table->index(['tenant_id', 'student_id']);
            $table->index(['teacher_id', 'starts_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('teacher_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('service_id')->references('id')->on('paid_services')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
