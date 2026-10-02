<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('short_description', 500)->nullable();
            $table->string('thumbnail_url', 500)->nullable();
            $table->string('cover_url', 500)->nullable();
            $table->string('level', 20)->default('all');
            $table->string('category', 100)->nullable();
            $table->text('tags')->nullable();
            $table->decimal('duration_hours', 5, 1)->default(0);
            $table->boolean('is_published')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->decimal('price', 10, 2)->nullable();
            $table->integer('max_students')->default(0);
            $table->string('completion_criteria', 20)->default('all_lessons');
            $table->boolean('certificate_enabled')->default(false);
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'is_published', 'sort_order']);
            $table->index(['tenant_id', 'category']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
