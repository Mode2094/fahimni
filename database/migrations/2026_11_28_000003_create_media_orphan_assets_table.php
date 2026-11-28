<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quarantine for provider videos that have no local record and cannot be
 * attributed to a tenant.
 *
 * The media reconcile job used to insert these into `media_assets` with a
 * zero-UUID tenant, which violated the `media_assets_tenant_id_foreign`
 * constraint and made the whole Bunny sync fail. Provider assets whose owner is
 * unknown must never be given a fake tenant; they are parked here (no tenant
 * FK) until an admin claims them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_orphan_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider', 50);
            $table->string('provider_asset_id', 500);
            $table->string('title')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->string('thumbnail_url', 500)->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'provider_asset_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_orphan_assets');
    }
};
