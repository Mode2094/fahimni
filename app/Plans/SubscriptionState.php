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
        // 1) Freemius entitlement wins when active.
        $entitlement = FsEntitlement::where('tenant_id', $tenantId)
            ->where('is_canceled', false)
            ->where(function ($q) {
                $q->whereNull('expiration')->orWhere('expiration', '>', now());
            })
            ->latest('synced_at')
            ->first();

        if ($entitlement && $entitlement->plan_id) {
            return [
                'source' => self::SOURCE_FREEMIUS,
                'plan_id' => $entitlement->plan_id,
                'status' => $entitlement->status ?: 'active',
                'billing_cycle' => $entitlement->metadata['billing_cycle'] ?? null,
                'ends_at' => $entitlement->expiration,
                'trial_ends_at' => null,
                'subscription_id' => $entitlement->fs_subscription_id,
                'entitlement_id' => $entitlement->id,
            ];
        }

        // 2) Legacy local subscription (historic rows, pre-Freemius).
        $subscription = TenantSubscription::where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trialing'])
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
        ];
    }

}
