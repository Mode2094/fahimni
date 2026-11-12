<?php

use App\Models\SubscriptionPlan;
use Illuminate\Database\Migrations\Migration;

/**
 * Platform-subscription prices are always USD.
 *
 * Freemius is the ONLY gateway for platform subscriptions and does not
 * support JOD, so the local display currency must be a supported currency
 * (USD). This converts any existing plan still stored in JOD, preserving the
 * numeric amounts as USD (they are display-only; the chargeable amount is
 * configured on the Freemius plan).
 *
 * Non-destructive: touches only `subscription_plans.currency`. No financial
 * history (payments / invoices / transactions) is modified — those keep the
 * currency they were actually charged in.
 */
return new class extends Migration
{
    public function up(): void
    {
        SubscriptionPlan::query()
            ->where('currency', '!=', 'USD')
            ->update(['currency' => 'USD']);
    }

    public function down(): void
    {
        // No-op: currency conversion is not reversible without data loss.
    }
};
