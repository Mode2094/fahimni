<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenant_subscriptions', 'admin_ends_at')) {
            Schema::table('tenant_subscriptions', function (Blueprint $table) {
                // Administratively granted access end. Deliberately SEPARATE
                // from the payment/billing state: a Super Admin extension keeps
                // the workspace usable until this date even when Freemius (or a
                // legacy provider, or a stale webhook) reports the paid period
                // as ended. It is never written by the Freemius webhook path.
                $table->timestampTz('admin_ends_at')->nullable()->after('ends_at');
                $table->index('admin_ends_at');
            });
        }

        // Repair subscriptions already extended through the OLD admin action.
        // That action flipped a Freemius checkout-intent row to `active` + a
        // future `ends_at`, but the access resolver never consults
        // Freemius-owned rows, so the extension granted nothing. Any
        // Freemius-sourced row with no bound license that is `active` with a
        // future end can only be such an extension: checkout intents are always
        // `pending`, and real Freemius mirrors always carry an `fs_license_id`.
        // Promote exactly that existing end to `admin_ends_at` WITHOUT adding
        // any days — repairing access, not re-extending.
        DB::table('tenant_subscriptions')
            ->where('billing_source', 'freemius')
            ->whereNull('fs_license_id')
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->update(['admin_ends_at' => DB::raw('ends_at')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_subscriptions', 'admin_ends_at')) {
            Schema::table('tenant_subscriptions', function (Blueprint $table) {
                $table->dropIndex(['admin_ends_at']);
                $table->dropColumn('admin_ends_at');
            });
        }
    }
};
