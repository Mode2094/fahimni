<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Normalize legacy rows written with the misspelled status before the
        // CHECK constraint is added, otherwise the ALTER fails on live data.
        DB::table('tenant_subscriptions')->where('status', 'canceled')->update(['status' => 'cancelled']);

        DB::statement("ALTER TABLE tenant_subscriptions ADD CONSTRAINT chk_tenant_subs_status CHECK (status IN ('trialing', 'active', 'pending', 'cancelled', 'expired', 'past_due'))");
        DB::statement("CREATE UNIQUE INDEX ux_tenant_subs_single_active ON tenant_subscriptions(tenant_id) WHERE status IN ('active', 'trialing')");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS ux_tenant_subs_single_active');
        DB::statement('ALTER TABLE tenant_subscriptions DROP CONSTRAINT chk_tenant_subs_status');
    }
};
