<?php

namespace App\Plans;

use App\Models\FsEntitlement;
use App\Models\TenantSubscription;

/**
 * Single source of truth for a tenant's *effective* subscription state.
 *
 * Before Freemius, an active `tenant_subscriptions` row was the only source.
 * Now there are three possible sources and they must never contradict:
 *
 *   - Freemius  : the active `fs_entitlements` row (license governs access).
 *   - Legacy    : an active/trialing `tenant_subscriptions` row.
 *   - Admin     : an additive Super Admin grant (`tenant_subscriptions.
 *                 admin_ends_at`) that is independent of payment state.
 *
 * `resolve()` returns exactly one effective record chosen as the UNION of the
 * sources (the one whose granted period ends last), favoring Freemius on a tie
 * (the platform is Freemius-only for new purchases). Everything downstream
 * (PlanEngine, access gates) reads through here so there is no
 * duplicated/divergent "is this tenant active" logic.
 *
 * @phpstan-type EffectiveState array{
 *     source: 'freemius'|'legacy'|'admin'|'none',
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

    /**
     * Administratively granted access (Super Admin subscription extension).
     * Independent of any payment/billing state and never revoked by a
     * Freemius webhook — it is an additive access grant on top of the paid
     * sources, honored until `admin_ends_at`.
     */
    public const SOURCE_ADMIN = 'admin';

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
        return $this->resolveMany([$tenantId])[$tenantId] ?? $this->empty();
    }

    /**
     * Batch resolve avoids N+1 tenant queries in platform-wide Super Admin
     * statistics. Every subscription query explicitly bypasses the currently
     * selected tenant scope.
     *
     * @param  list<string>  $tenantIds
     * @return array<string, array{
     *     source: string,
     *     plan_id: string|null,
     *     status: string|null,
     *     billing_cycle: string|null,
     *     ends_at: \DateTimeInterface|null,
     *     trial_ends_at: \DateTimeInterface|null,
     *     subscription_id: string|null,
     *     entitlement_id: string|null,
     *     cancel_at_period_end: bool
     * }>
     */
    public function resolveMany(array $tenantIds): array
    {
        $tenantIds = array_values(array_unique(array_filter(
            array_map('strval', $tenantIds),
            fn (string $tenantId) => $tenantId !== '',
        )));

        if ($tenantIds === []) {
            return [];
        }

        $now = now();
        $entitlementsByTenant = FsEntitlement::query()
            ->whereIn('tenant_id', $tenantIds)
            ->whereNotNull('plan_id')
            ->orderByDesc('synced_at')
            ->get()
            ->groupBy(fn (FsEntitlement $entitlement) => (string) $entitlement->tenant_id);

        $legacyByTenant = TenantSubscription::withoutTenant()
            ->whereIn('tenant_id', $tenantIds)
            ->whereIn('status', ['active', 'trialing'])
            ->where(function ($query) {
                $query->whereNull('billing_source')->orWhere('billing_source', '!=', self::SOURCE_FREEMIUS);
            })
            ->whereNull('fs_license_id')
            ->orderByDesc('starts_at')
            ->get()
            ->groupBy(fn (TenantSubscription $subscription) => (string) $subscription->tenant_id);

        $adminGrantsByTenant = TenantSubscription::withoutTenant()
            ->whereIn('tenant_id', $tenantIds)
            ->whereNotNull('admin_ends_at')
            ->where('admin_ends_at', '>', $now)
            ->orderByDesc('admin_ends_at')
            ->get()
            ->groupBy(fn (TenantSubscription $subscription) => (string) $subscription->tenant_id);

        $states = [];

        foreach ($tenantIds as $tenantId) {
            /** @var list<array<string, mixed>> $candidates */
            $candidates = [];

            // A canceled license continues to grant access until expiration;
            // a hard revocation/refund is rejected by FsEntitlement::isActive.
            $entitlement = $entitlementsByTenant->get($tenantId, collect())
                ->first(fn (FsEntitlement $candidate) => $candidate->isActive());

            if ($entitlement) {
                $candidates[] = [
                    'source' => self::SOURCE_FREEMIUS,
                    'plan_id' => $entitlement->plan_id,
                    'status' => $entitlement->status ?: 'active',
                    'billing_cycle' => $entitlement->metadata['billing_cycle'] ?? null,
                    'ends_at' => $entitlement->expiration,
                    'trial_ends_at' => null,
                    'subscription_id' => $entitlement->fs_subscription_id,
                    'entitlement_id' => $entitlement->id,
                    'cancel_at_period_end' => (bool) $entitlement->is_canceled,
                    'priority' => 3,
                ];
            }

            // A trial is effective only inside its explicit trial window. An
            // active subscription may have no end (lifetime), but a future
            // start date never grants access early.
            $subscription = $legacyByTenant->get($tenantId, collect())
                ->first(function (TenantSubscription $candidate) use ($now): bool {
                    if ($candidate->starts_at && $candidate->starts_at->gt($now)) {
                        return false;
                    }

                    if ($candidate->status === 'trialing') {
                        return $candidate->trial_ends_at !== null
                            && $candidate->trial_ends_at->gt($now);
                    }

                    return $candidate->status === 'active'
                        && ($candidate->ends_at === null || $candidate->ends_at->gt($now));
                });

            if ($subscription) {
                $legacyEnd = $subscription->status === 'trialing'
                    ? $subscription->trial_ends_at
                    : $subscription->ends_at;

                $candidates[] = [
                    'source' => self::SOURCE_LEGACY,
                    'plan_id' => $subscription->plan_id,
                    'status' => $subscription->status,
                    'billing_cycle' => $subscription->billing_cycle,
                    'ends_at' => $legacyEnd,
                    'trial_ends_at' => $subscription->trial_ends_at,
                    'subscription_id' => $subscription->id,
                    'entitlement_id' => null,
                    'cancel_at_period_end' => false,
                    'priority' => 2,
                ];
            }

            // Admin access is independent of the row's billing status.
            $adminGrant = $adminGrantsByTenant->get($tenantId, collect())->first();

            if ($adminGrant) {
                $candidates[] = [
                    'source' => self::SOURCE_ADMIN,
                    'plan_id' => $adminGrant->plan_id,
                    'status' => $adminGrant->status ?: 'active',
                    'billing_cycle' => $adminGrant->billing_cycle,
                    'ends_at' => $adminGrant->admin_ends_at,
                    'trial_ends_at' => null,
                    'subscription_id' => $adminGrant->id,
                    'entitlement_id' => null,
                    'cancel_at_period_end' => false,
                    'priority' => 1,
                ];
            }

            $states[$tenantId] = $this->selectEffectiveCandidate($candidates) ?? $this->empty();
        }

        return $states;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    private function selectEffectiveCandidate(array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (array $a, array $b): int {
            $aEnd = $a['ends_at'];
            $bEnd = $b['ends_at'];

            // A null end = lifetime → always wins.
            if ($aEnd === null && $bEnd === null) {
                return $b['priority'] <=> $a['priority'];
            }
            if ($aEnd === null) {
                return -1;
            }
            if ($bEnd === null) {
                return 1;
            }

            $comparison = $bEnd <=> $aEnd;

            return $comparison !== 0 ? $comparison : ($b['priority'] <=> $a['priority']);
        });

        $best = $candidates[0];
        unset($best['priority']);

        return $best;
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
