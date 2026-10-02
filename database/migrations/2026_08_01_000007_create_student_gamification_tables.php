<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Points ledger — every award is recorded
        Schema::create('student_points', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->integer('points')->default(0);
            $table->string('reason', 100);
            $table->string('reference_type', 50)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Achievements catalog (tenant_id null = platform default)
        Schema::create('achievements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('key', 100)->unique();
            $table->string('title', 191);
            $table->string('title_ar', 191)->nullable();
            $table->text('description')->nullable();
            $table->text('description_ar')->nullable();
            $table->string('icon', 100)->default('star');
            $table->integer('points')->default(0);
            $table->jsonb('criteria')->default('{}');
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['tenant_id', 'sort_order']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        // Earned achievements per student
        Schema::create('student_achievements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('achievement_id');
            $table->timestampTz('unlocked_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'user_id', 'achievement_id']);
            $table->index(['tenant_id', 'user_id', 'unlocked_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('achievement_id')->references('id')->on('achievements')->cascadeOnDelete();
        });

        // Activity log
        Schema::create('student_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('type', 50);
            $table->string('description', 500);
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at');

            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Favorites
        Schema::create('student_favorites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->uuid('course_id');
            $table->timestampsTz();

            $table->unique(['user_id', 'course_id']);
            $table->index(['tenant_id', 'user_id', 'created_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_favorites');
        Schema::dropIfExists('student_activities');
        Schema::dropIfExists('student_achievements');
        Schema::dropIfExists('achievements');
        Schema::dropIfExists('student_points');
    }
};
