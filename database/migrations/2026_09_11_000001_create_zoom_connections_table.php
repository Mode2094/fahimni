<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zoom_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('zoom_user_id', 190)->nullable();
            $table->string('zoom_email', 190)->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->jsonb('scopes')->default('[]');
            $table->timestampTz('last_use_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'user_id'], 'zoom_connections_tenant_user_unique');
            $table->index(['tenant_id', 'last_use_at']);
            $table->index(['user_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX zoom_connections_tenant_last_use_idx ON zoom_connections (tenant_id, last_use_at)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zoom_connections');
    }
};