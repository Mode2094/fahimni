<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('provider', 50);
            $table->string('label', 190)->nullable();
            $table->boolean('enabled')->default(false);
            $table->text('credentials')->nullable();
            $table->jsonb('config')->default('{}');
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('connected_at')->nullable();
            $table->timestampTz('last_tested_at')->nullable();
            $table->jsonb('last_test_result')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'provider']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX integrations_platform_provider_unique ON integrations (provider) WHERE tenant_id IS NULL');
            DB::statement('CREATE UNIQUE INDEX integrations_tenant_provider_unique ON integrations (tenant_id, provider) WHERE tenant_id IS NOT NULL');
        }

        Schema::create('integration_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('integration_id')->nullable();
            $table->string('provider', 50);
            $table->string('direction', 10)->default('out');
            $table->string('event', 100);
            $table->jsonb('payload')->default('{}');
            $table->string('status', 20)->default('success');
            $table->text('error')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'provider', 'event']);
            $table->index(['integration_id']);
            $table->foreign('integration_id')->references('id')->on('integrations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
        Schema::dropIfExists('integrations');
    }
};
