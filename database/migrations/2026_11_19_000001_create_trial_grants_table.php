<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit + idempotency record for teacher free-trial grants.
 *
 * One row per user that ever received a trial. `user_id` is UNIQUE so the same
 * account can never be granted a second trial, and duplicate/abusive retries
 * fail at the database level. Admin/test-provisioned trials may be recorded
 * with `counted = false` so they never consume a limited-offer seat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trial_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();
            $table->uuid('tenant_id')->nullable();
            $table->string('source', 20)->default('register'); // register | google | admin
            $table->boolean('counted')->default(true);
            $table->timestampTz('granted_at');
            $table->timestampsTz();

            $table->index('tenant_id');
            $table->index('counted');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_grants');
    }
};
