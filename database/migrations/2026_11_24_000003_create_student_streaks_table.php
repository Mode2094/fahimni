<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily streak per student per tenant: the number of consecutive calendar days
 * on which the student did something (logged in, completed a lesson, answered
 * an exam…). Stored rather than derived so an expensive "distinct activity
 * dates" scan is never needed on the hot path.
 *
 * `last_activity_date` is a plain date (tenant timezone); the streak is
 * considered "alive" when that date is today or yesterday, and broken
 * otherwise, so no nightly job is required to expire it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_streaks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->unsignedInteger('current_streak')->default(0);
            $table->unsignedInteger('longest_streak')->default(0);
            $table->date('last_activity_date')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'current_streak']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_streaks');
    }
};
