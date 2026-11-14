<?php

namespace App\Plans;

use App\Models\FsEntitlement;
use App\Models\TenantSubscription;

/**
 * Single source of truth for a tenant's *effective* subscription state.
 *
 * Before Freemius, an active `tenant_subscriptions` row was the only source.
 * Now there are two possible sources and they must never contradict:
 *
 *   - Freemius  : the active `fs_entitlements` row (license governs access).
 *   - Legacy    : an active/trialing `tenant_subscriptions` row.
 *
 * `resolve()` returns exactly one effective record, favoring Freemius when a
 * tenant has an active entitlement (the platform is Freemius-only for new
 * purchases). Everything downstream (PlanEngine, access gates) reads through
 * here so there is no duplicated/divergent "is this tenant active" logic.
 *
 * @phpstan-type EffectiveState array{
 *     source: 'freemius'|'legacy'|'none',
 *     plan_id: string|null,
 *     status: string|null,
 *     billing_cycle: string|null,
 *     ends_at: \DateTimeInterface|null,
 *     trial_ends_at: \DateTimeInterface|null,
 *     subscription_id: string|null,
 *     entitlement_id: string|null,
 *     cancel_at_period_end: bool,
 * }
 */
class SubscriptionState
{
    public const SOURCE_FREEMIUS = 'freemius';

    public const SOURCE_LEGACY = 'legacy';

    public const SOURCE_NONE = 'none';

    public static function make(): self
    {
        return new self;
    }

    /**
     * @return array{
     *     source: string,
     *     plan_id: string|null,
     *     status: string|null,
     *     billing_cycle: string|null,
     *     ends_at: \DateTimeInterface|null,
     *     trial_ends_at: \DateTimeInterface|null,
     *     subscription_id: string|null,
     *     entitlement_id: string|null,
     * }
     */
    public function resolve(string $tenantId): array
    {
        // 1) Freemius entitlement wins when it still grants access.
        //
        // IMPORTANT: a canceled entitlement (recurring billing stopped at
        // period end) STILL grants access until `expiration`. A HARD
        // cancellation/refund/revocation stops access immediately, regardless
        // of expiration. We reuse the model's single access rule so there is
        // no duplicated logic. A tenant may legitimately hold more than one
        // license, so we pick the most recent one that grants access.
        $entitlement = FsEntitlement::where('tenant_id', $tenantId)
            ->whereNotNull('plan_id')
            ->latest('synced_at')
            ->get()
            ->first(fn (FsEntitlement $e) => $e->isActive());

        if ($entitlement) {
            return [
                'source' => self::SOURCE_FREEMIUS,
                'plan_id' => $entitlement->plan_id,
                'status' => $entitlement->status ?: 'active',
                'billing_cycle' => $entitlement->metadata['billing_cycle'] ?? null,
                'ends_at' => $entitlement->expiration,
                'trial_ends_at' => null,
                'subscription_id' => $entitlement->fs_subscription_id,
                'entitlement_id' => $entitlement->id,
                'cancel_at_period_end' => (bool) $entitlement->is_canceled,
            ];
        }

        // 2) Legacy local subscription (historic rows, pre-Freemius).
        //
        // EXCLUDE Freemius-owned rows: a Freemius subscription's local mirror
        // must never re-grant access on its own (that would "revive" access
        // after the license expired or was deleted). Only genuinely legacy
        // rows (no billing_source / non-freemius) are consulted here.
        $subscription = TenantSubscription::where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trialing'])
            ->where(function ($q) {
                $q->whereNull('billing_source')->orWhere('billing_source', '!=', 'freemius');
            })
            ->whereNull('fs_license_id')
            ->latest('starts_at')
            ->first();

        if (! $subscription) {
            return $this->empty();
        }

        if ($subscription->status === 'trialing'
            && $subscription->trial_ends_at
            && now()->gt($subscription->trial_ends_at)) {
            return $this->empty();
        }

        if ($subscription->status === 'active'
            && $subscription->ends_at
            && now()->gt($subscription->ends_at)) {
            return $this->empty();
        }

        return [
            'source' => self::SOURCE_LEGACY,
            'plan_id' => $subscription->plan_id,
            'status' => $subscription->status,
            'billing_cycle' => $subscription->billing_cycle,
            'ends_at' => $subscription->ends_at,
            'trial_ends_at' => $subscription->trial_ends_at,
            'subscription_id' => $subscription->id,
            'entitlement_id' => null,
            'cancel_at_period_end' => false,
        ];
    }

    public function isActive(string $tenantId): bool
    {
        return $this->resolve($tenantId)['source'] !== self::SOURCE_NONE;
    }

    /**
     * @return array{source: string, plan_id: string|null, status: string|null, billing_cycle: string|null, ends_at: \DateTimeInterface|null, trial_ends_at: \DateTimeInterface|null, subscription_id: string|null, entitlement_id: string|null}
     */
    private function empty(): array
    {
        return [
            'source' => self::SOURCE_NONE,
            'plan_id' => null,
            'status' => null,
            'billing_cycle' => null,
            'ends_at' => null,
            'trial_ends_at' => null,
            'subscription_id' => null,
            'entitlement_id' => null,
            'cancel_at_period_end' => false,
        ];
    }

}
