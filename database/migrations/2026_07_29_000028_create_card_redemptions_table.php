<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('card_id');
            $table->uuid('course_id');
            $table->uuid('student_id');
            $table->uuid('enrollment_id')->nullable();
            $table->timestampTz('redeemed_at');
            $table->string('ip_address', 45)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();

            $table->index('card_id');
            $table->index(['student_id', 'redeemed_at']);
            $table->foreign('card_id')->references('id')->on('activation_cards')->cascadeOnDelete();
            $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
            $table->foreign('student_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('enrollment_id')->references('id')->on('enrollments')->nullOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_redemptions');
    }
};
