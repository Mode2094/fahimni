<?php

namespace App\Services;

use App\Models\SubscriptionPlan;

/**
 * Maps a local SubscriptionPlan to its Freemius plan/pricing ids.
 *
 * Resolution order (admin metadata overrides env default):
 *   1. plan.metadata['freemius_plan_id'] / ['freemius_pricing_id']
 *   2. env FREEMIUS_PLAN_{SLUG}_{MONTHLY|YEARLY}_ID
 *
 * The local plan stays the source of truth for features/quota (PlanEngine);
 * Freemius only decides *billing*. Platform-subscription prices are always
 * USD locally (Freemius is the only gateway and does not support JOD); the
 * chargeable amount on the Freemius plan must match the local USD price.
 */
class FreemiusPlanMapper
{
    /**
     * Freemius plan id for a local plan + billing cycle (monthly|yearly).
     */
    public static function planId(SubscriptionPlan $plan, string $cycle = 'monthly'): ?string
    {
        $meta = $plan->metadata ?? [];

        // Admin override (per pricing/cycle stored as nested or flat).
        $metaKey = $cycle === 'yearly' ? 'freemius_yearly_plan_id' : 'freemius_monthly_plan_id';
        if (! empty($meta[$metaKey])) {
            return (string) $meta[$metaKey];
        }

        // Generic single-plan override.
        if (! empty($meta['freemius_plan_id'])) {
            return (string) $meta['freemius_plan_id'];
        }

        $slug = strtoupper(preg_replace('/[^a-z0-9]+/i', '_', (string) $plan->slug));
        $suffix = $cycle === 'yearly' ? 'YEARLY' : 'MONTHLY';

        return self::envValue("FREEMIUS_PLAN_{$slug}_{$suffix}_ID");
    }

    /**
     * Freemius pricing id (more granular than plan id when multiple prices
     * exist per plan). Optional — plan id is sufficient for checkout.
     */
    public static function pricingId(SubscriptionPlan $plan, string $cycle = 'monthly'): ?string
    {
        $meta = $plan->metadata ?? [];

        $metaKey = $cycle === 'yearly' ? 'freemius_yearly_pricing_id' : 'freemius_monthly_pricing_id';
        if (! empty($meta[$metaKey])) {
            return (string) $meta[$metaKey];
        }

        if (! empty($meta['freemius_pricing_id'])) {
            return (string) $meta['freemius_pricing_id'];
        }

        $slug = strtoupper(preg_replace('/[^a-z0-9]+/i', '_', (string) $plan->slug));
        $suffix = $cycle === 'yearly' ? 'YEARLY' : 'MONTHLY';

        return self::envValue("FREEMIUS_PLAN_{$slug}_{$suffix}_PRICING_ID");
    }

    /**
     * Read a plan-mapping value, preferring config (settable at runtime and in
     * tests) over the raw environment, so the mapping is testable without
     * process-level env mutation.
     */
    private static function envValue(string $key): ?string
    {
        $value = config($key, env($key));

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    /**
     * Reverse lookup: which local plan does a Freemius plan id belong to?
     * Used by the webhook to reconcile an incoming license to a local plan.
     */
    public static function localPlanForFreemiusPlanId(string $fsPlanId): ?SubscriptionPlan
    {
        // 1) Explicit metadata mapping.
        $plan = SubscriptionPlan::where('metadata->freemius_plan_id', $fsPlanId)
            ->orWhere('metadata->freemius_monthly_plan_id', $fsPlanId)
            ->orWhere('metadata->freemius_yearly_plan_id', $fsPlanId)
            ->first();

        if ($plan) {
            return $plan;
        }

        // 2) Env mapping — iterate plans and compare resolved ids.
        foreach (SubscriptionPlan::all() as $candidate) {
            if (self::planId($candidate, 'monthly') === $fsPlanId
                || self::planId($candidate, 'yearly') === $fsPlanId) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether a local plan is purchasable through Freemius.
     */
    public static function isMapped(SubscriptionPlan $plan): bool
    {
        return self::planId($plan, 'monthly') !== null || self::planId($plan, 'yearly') !== null;
    }
}
