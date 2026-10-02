<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_stats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->date('stat_date');
            $table->string('stat_type', 50);
            $table->string('statable_type')->nullable();
            $table->uuid('statable_id')->nullable();
            $table->jsonb('data')->default('{}');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'stat_date', 'stat_type', 'statable_type', 'statable_id'], 'daily_stats_unique');
            $table->index('stat_date');
            $table->index(['statable_type', 'statable_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_stats');
    }
};
