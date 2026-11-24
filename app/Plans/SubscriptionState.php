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
        // Gather EVERY source that currently grants access, then keep the one
        // whose granted period ends LAST (a null end = lifetime wins). This
        // makes access a UNION: an admin extension never shortens a still-paid
        // period, and a paid period never hides an admin grant. Tie-break by
        // source priority: Freemius (paid) > legacy > admin.

        /** @var list<array<string, mixed>> $candidates */
        $candidates = [];

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
            ->get()
            ->first(function (TenantSubscription $s): bool {
                if ($s->status === 'trialing' && $s->trial_ends_at && now()->gt($s->trial_ends_at)) {
                    return false;
                }

                if ($s->status === 'active' && $s->ends_at && now()->gt($s->ends_at)) {
                    return false;
                }

                return true;
            });

        if ($subscription) {
            // A trialing row runs out at `trial_ends_at` (its `ends_at` is
            // normally null until it becomes a paid period). Reporting the
            // trial end here keeps the admin UI, the plan engine and the
            // teacher dashboard honest about when access actually lapses —
            // and stops a trial from being mistaken for a lifetime grant.
            $legacyEnd = $subscription->status === 'trialing' && $subscription->trial_ends_at
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

        // 3) Administrative grant — read straight from the dedicated
        //    `admin_ends_at` field, regardless of the row's status,
        //    billing_source or fs_license_id. It therefore survives a
        //    Freemius mirror re-sync and a license deletion (both only
        //    rewrite those other columns) as long as the date is in the future.
        $adminGrant = TenantSubscription::where('tenant_id', $tenantId)
            ->whereNotNull('admin_ends_at')
            ->where('admin_ends_at', '>', now())
            ->orderByDesc('admin_ends_at')
            ->first();

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

        if ($candidates === []) {
            return $this->empty();
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

            $cmp = $bEnd <=> $aEnd; // later end first

            return $cmp !== 0 ? $cmp : ($b['priority'] <=> $a['priority']);
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
