<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Academic terms (فصول دراسية) let a teacher scope enrollments to a
 * time-boxed period. Ending a term archives the enrollments it produced so
 * the seats they held are released — without ever deleting the historical
 * rows (grades, progress, attempts, certificates all keep pointing at them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            // upcoming | active | completed | archived
            $table->string('status', 20)->default('upcoming');
            $table->uuid('created_by')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'ends_at']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_terms');
    }
};
