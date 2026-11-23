<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course bundles: a teacher-defined package of several courses offered (and,
 * when he chooses, sold) as one unit. A bundle never duplicates a course's
 * content — it only groups existing course ids, so a lesson, exam or file is
 * still owned by exactly one course.
 *
 * Enrolling a student in a bundle is just enrolling them in each member course
 * through the normal EnrollmentService, so seat accounting, caps and term
 * rules apply unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('title', 191);
            $table->string('slug', 191);
            $table->text('description')->nullable();
            $table->string('short_description', 500)->nullable();
            $table->string('thumbnail_url', 500)->nullable();
            // Optional: a bundle can be shown without a price (contact /
            // activation-card only). Never invented — left null by default.
            $table->decimal('price', 10, 2)->nullable();
            $table->string('currency', 8)->default('JOD');
            $table->boolean('is_published')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'is_published', 'sort_order']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::create('bundle_courses', function (Blueprint $table) {
            $table->uuid('bundle_id');
            $table->uuid('course_id');
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->primary(['bundle_id', 'course_id']);
            $table->index('course_id');
            $table->foreign('bundle_id')->references('id')->on('bundles')->cascadeOnDelete();
            $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_courses');
        Schema::dropIfExists('bundles');
    }
};
