<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Freemius billing support — additive, non-destructive.
 *
 * This migration deliberately does NOT touch any existing financial data.
 * Historic payments / invoices / transactions / subscriptions are preserved
 * untouched so accounting and archives stay intact.
 *
 * What it adds:
 *  1. `fs_entitlements` — the canonical Freemius license → tenant mapping
 *     recommended by Freemius' own SaaS integration docs. One row per
 *     Freemius license; the active (non-canceled, non-expired) row is what
 *     grants a tenant access (entitlement), decoupled from the subscription
 *     billing record so access can outlive a cancelled subscription.
 *  2. Nullable bridge columns on `tenant_subscriptions` and `payments` so a
 *     local subscription/payment can carry its originating Freemius ids
 *     without breaking rows created by the legacy gateways.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fs_entitlements')) {
            Schema::create('fs_entitlements', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();

                // Freemius identifiers (stored as strings — FS uses large
                // numeric ids and prices; never assume int width).
                $table->string('fs_license_id', 64)->unique();
                $table->string('fs_user_id', 64)->nullable()->index();
                $table->string('fs_plan_id', 64)->nullable();
                $table->string('fs_pricing_id', 64)->nullable();
                $table->string('fs_subscription_id', 64)->nullable()->index();

                // Local plan this entitlement maps onto (drives PlanEngine).
                $table->uuid('plan_id')->nullable()->index();

                // 'subscription' | 'lifetime' (Freemius entitlement types).
                $table->string('type', 16)->default('subscription')->index();

                $table->string('status', 32)->nullable();       // raw FS status
                $table->timestamp('expiration', 0)->nullable()->index();
                $table->boolean('is_canceled')->default(false);

                // Last time we synced this row from the Freemius API.
                $table->timestamp('synced_at', 0)->nullable();

                // Raw latest API payload for audit/archival (never secrets).
                $table->jsonb('metadata')->default('{}');

                $table->timestamps();

                $table->index(['tenant_id', 'is_canceled'], 'fs_ent_tenant_cancel_idx');
            });
        }

        if (! Schema::hasColumn('tenant_subscriptions', 'fs_license_id')) {
            Schema::table('tenant_subscriptions', function (Blueprint $table) {
                $table->string('fs_license_id', 64)->nullable()->after('plan_id');
                $table->string('fs_subscription_id', 64)->nullable()->after('fs_license_id');
                $table->string('fs_pricing_id', 64)->nullable()->after('fs_subscription_id');
                $table->string('billing_source', 24)->nullable()->after('billing_cycle');
            });
        }

        if (! Schema::hasColumn('payments', 'fs_license_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('fs_license_id', 64)->nullable()->after('transaction_id');
            });
        }
    }

    public function down(): void
    {
        // Reverse only what this migration added. Never drops legacy data.
        if (Schema::hasColumn('payments', 'fs_license_id')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('fs_license_id');
            });
        }

        if (Schema::hasColumn('tenant_subscriptions', 'fs_license_id')) {
            Schema::table('tenant_subscriptions', function (Blueprint $table) {
                $table->dropColumn([
                    'fs_license_id', 'fs_subscription_id', 'fs_pricing_id', 'billing_source',
                ]);
            });
        }

        Schema::dropIfExists('fs_entitlements');
    }
};
