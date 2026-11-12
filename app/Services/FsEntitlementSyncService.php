<?php

namespace App\Services;

use App\Models\FsEntitlement;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Illuminate\Support\Facades\DB;
/**
 * Writes the canonical Freemius entitlement for a tenant.
 *
 * This is the ONLY place that turns Freemius data into a local entitlement.
 * Both the webhook and the post-checkout reconciliation call it, so there is
 * exactly one code path deciding "is this tenant entitled, and to what".
 *
 * Safety guarantees:
 *  - Never trusts browser input: all fields come from the Freemius API
 *    response (re-fetched server-side by the caller).
 *  - Idempotent: re-syncing the same license upserts by fs_license_id.
 *  - Tenant is resolved from the license's own customer email, never from a
 *    request parameter.
 *  - Local plan is reconciled through FreemiusPlanMapper.
 */
class FsEntitlementSyncService
{
    /**
     * Sync one license (by id) from the Freemius API into `fs_entitlements`.
     *
     * Returns a structured outcome so the caller can tell apart:
     *   - 'synced'    : entitlement applied (or removed)
     *   - 'not_found' : Freemius definitively has no such license (404)
     *   - 'no_tenant' : license exists but no local tenant matches (ack ok)
     *
     * THROWS FreemiusApiException on a transient API failure — the caller must
     * NOT mark the webhook processed in that case (so it can be retried).
     *
     * @return array{status: string, entitlement: FsEntitlement|null}
     */
    public function syncByLicenseId(string $licenseId): array
    {
        // Strict fetch: throws on transient failure, null only on 404.
        $license = FreemiusGateway::getLicenseOrFail($licenseId);

        if (! $license) {
            return ['status' => 'not_found', 'entitlement' => null];
        }

        // The license payload nests the user under `user` (email/id).
        $email = $license['user']['email'] ?? $license['email'] ?? null;

        if (! $email) {
            // License has no resolvable customer — nothing to map. Ack.
            return ['status' => 'no_tenant', 'entitlement' => null];
        }

        // Resolve the tenant through the trustworthy mapping first, then fall
        // back to email (see resolveTenant).
        $tenant = $this->resolveTenant($license, (string) $email);

        if (! $tenant) {
            return ['status' => 'no_tenant', 'entitlement' => null];
        }

        $subscription = FreemiusGateway::getLatestSubscriptionOrFail($licenseId);

        $entitlement = $this->applyLicense($tenant, $license, $subscription);

        return ['status' => 'synced', 'entitlement' => $entitlement];
    }

    /**
     * Apply an already-fetched license payload (used when the webhook carries
     * the full objects and the caller re-fetched the license).
     *
     * @param  array<string, mixed>  $license
     * @param  array<string, mixed>|null  $subscription
     */
    public function applyLicense(Tenant $tenant, array $license, ?array $subscription): FsEntitlement
    {
        return DB::transaction(function () use ($tenant, $license, $subscription) {
            $licenseId = (string) ($license['id'] ?? '');
            $fsPlanId = $this->stringOrNull($license['plan_id'] ?? $subscription['plan_id'] ?? null);
            $fsPricingId = $this->stringOrNull($license['pricing_id'] ?? $subscription['pricing_id'] ?? null) ;
            $fsUserId = $this->stringOrNull($license['user_id'] ?? $license['user']['id'] ?? null);
            $fsSubId = $this->stringOrNull($subscription['id'] ?? null);

            $localPlan = $fsPlanId ? FreemiusPlanMapper::localPlanForFreemiusPlanId($fsPlanId) : null;

            // ── Canonical license state (per Freemius documented fields) ──
            // `expiration`  : string or null (null = lifetime). AUTHORITATIVE.
            // `is_cancelled` (API, double-L): license hard-cancelled from the
            //                Developer Dashboard → INVALID (revoked).
            // `is_canceled`  (SaaS webhook, single-L): recurring billing
            //                stopped; current paid period may still run.
            // `source`/`environment` etc. are ignored for access.
            $licenseStatus = $this->stringOrNull($license['status'] ?? null);
            $subscriptionStatus = $this->stringOrNull($subscription['status'] ?? null);

            $hardCancelled = (bool) (
                $license['is_cancelled']      // API hard-cancel (revoked)
                ?? $license['is_canceled']    // SaaS guide field
                ?? false
            );
            $renewalCancelled = (bool) ($license['is_canceled'] ?? false);

            $expiration = $this->parseDate($license['expiration'] ?? $subscription['next_payment'] ?? null);

            // Revoked statuses stop access immediately; otherwise access is
            // governed by expiration alone (a "cancel at period end" keeps a
            // future expiration and therefore keeps access).
            $revokedStatuses = ['revoked', 'deactivated', 'deleted', 'cancelled', 'canceled', 'expired'];

            $status = 'active';
            if ($hardCancelled && $licenseStatus === null && $subscriptionStatus === null) {
                $status = 'revoked';
            } elseif (in_array($licenseStatus, $revokedStatuses, true) && $licenseStatus !== 'cancelled') {
                $status = $licenseStatus;
            } elseif ($licenseStatus !== null) {
                $status = $licenseStatus;
            } elseif ($subscriptionStatus !== null) {
                $status = $subscriptionStatus;
            }

            // Derive the billing cycle from the pricing/plan when reported.
            $cycle = $this->stringOrNull($subscription['billing_cycle'] ?? null);

            $existing = FsEntitlement::where('fs_license_id', $licenseId)->first();

            // A license already bound to a tenant is NEVER rebound to another
            // tenant (e.g. because the customer email changed). The caller's
            // resolveTenant() already honors this, but we enforce it here as a
            // defensive invariant.
            if ($existing && $existing->tenant_id && $existing->tenant_id !== $tenant->id) {
                $tenant = Tenant::findOrFail($existing->tenant_id);
            }

            $attributes = [
                'tenant_id' => $tenant->id,
                'fs_user_id' => $fsUserId,
                'fs_plan_id' => $fsPlanId,
                'fs_pricing_id' => $fsPricingId,
                'fs_subscription_id' => $fsSubId,
                'plan_id' => $localPlan?->id,
                'type' => $subscription ? 'subscription' : 'lifetime',
                'status' => $status,
                'expiration' => $expiration,
                'is_canceled' => $renewalCancelled,
                'synced_at' => now(),
                'metadata' => [
                    'billing_cycle' => $cycle,
                    'raw_status' => $status,
                    'license_status' => $licenseStatus,
                    'subscription_status' => $subscriptionStatus,
                    'hard_cancelled' => $hardCancelled,
                ],
            ];

            if ($existing) {
                $existing->update($attributes);

                $entitlement = $existing;
            } else {
                $entitlement = FsEntitlement::create(array_merge($attributes, [
                    'fs_license_id' => $licenseId,
                ]));
            }

            // Mirror onto a local subscription row (archival + PlanEngine's
            // legacy path + invoice FK) without ever conflicting with the
            // partial unique index (active/trialing).
            $this->mirrorSubscription($tenant, $entitlement, $localPlan, $cycle, $expiration, $renewalCancelled, $status);

            // Invalidate the plan cache so the entitlement change is visible
            // immediately (no 5-minute staleness window).
            \App\Plans\PlanEngine::bumpVersion();

            return $entitlement;
        });
    }

    /**
     * Remove an entitlement (license.deleted). Never touches financial rows.
     * Also cancels the local mirror so a stale active row can never revive
     * access, and invalidates the plan cache immediately.
     */
    public function deleteByLicenseId(string $licenseId): void
    {
        DB::transaction(function () use ($licenseId) {
            FsEntitlement::where('fs_license_id', $licenseId)->delete();

            // Revoke the local mirror: mark it expired (never active) so the
            // legacy fallback cannot re-grant access after the license is gone.
            TenantSubscription::withoutTenant()->where('fs_license_id', $licenseId)
                ->update([
                    'status' => 'expired',
                    'auto_renew' => false,
                    'cancelled_at' => now(),
                ]);

            \App\Plans\PlanEngine::bumpVersion();
        });
    }

    /**
     * Resolve the tenant a license belongs to using trustworthy, non-forgeable
     * signals only. Returns null when the binding is ambiguous (which sends the
     * event to manual review) rather than guessing.
     *
     * Order of strength:
     *  1. An EXISTING entitlement binding (license or Freemius customer id
     *     already mapped) — never re-bound to another tenant.
     *  2. A UNIQUE pending checkout intent for the licence's Freemius customer
     *     id (stored server-side; not forgeable from the browser).
     *  3. A UNIQUE pending checkout intent for the licence's customer email —
     *     only when exactly ONE tenant has a pending intent for that email.
     *     Multiple matches (a user owning several platforms) → null → review.
     *
     * We deliberately do NOT fall back to "any tenant whose owner happens to
     * share the email": that is how a license gets bound to the wrong tenant.
     *
     * @param  array<string, mixed>  $license
     */
    private function resolveTenant(array $license, string $email): ?Tenant
    {
        $licenseId = (string) ($license['id'] ?? '');
        $fsUserId = $this->stringOrNull($license['user_id'] ?? ($license['user']['id'] ?? null));

        // 1) Existing binding wins — a bound license never moves tenants.
        if ($licenseId !== '') {
            $existing = FsEntitlement::where('fs_license_id', $licenseId)->first();

            if ($existing && $existing->tenant_id) {
                return Tenant::find($existing->tenant_id);
            }
        }

        // 1b) A Freemius customer id already bound to a tenant is authoritative
        //     for that customer (supports a customer with several licenses on
        //     the SAME tenant; cross-tenant reuse is never allowed).
        if ($fsUserId !== null) {
            $boundByUser = FsEntitlement::where('fs_user_id', $fsUserId)
                ->whereNotNull('tenant_id')
                ->latest('synced_at')
                ->first();

            if ($boundByUser && $boundByUser->tenant_id) {
                return Tenant::find($boundByUser->tenant_id);
            }
        }

        // 2) Unique pending intent for this Freemius customer id.
        if ($fsUserId !== null) {
            $byUser = TenantSubscription::withoutTenant()->where('billing_source', 'freemius')
                ->where('metadata->freemius_intent_user_id', $fsUserId)
                ->pluck('tenant_id')
                ->unique();

            if ($byUser->count() === 1) {
                return Tenant::find($byUser->first());
            }
        }

        // 3) Unique pending intent for this customer email.
        $byEmail = TenantSubscription::withoutTenant()->where('billing_source', 'freemius')
            ->where('metadata->freemius_intent_email', strtolower(trim($email)))
            ->pluck('tenant_id')
            ->unique();

        if ($byEmail->count() === 1) {
            return Tenant::find($byEmail->first());
        }

        // Ambiguous or unknown customer → manual review (never guess).
        return null;
    }

    /**
     * Keep a local archival subscription row in sync with the entitlement
     * so billing history/reports still have a row to point at. Cancelled /
     * prior rows are preserved. This row is NOT consulted for access (the
     * legacy path excludes Freemius-owned rows) — it is archival only.
     */
    private function mirrorSubscription(
        Tenant $tenant,
        FsEntitlement $entitlement,
        ?\App\Models\SubscriptionPlan $localPlan,
        ?string $cycle,
        ?\DateTimeInterface $expiration,
        bool $renewalCancelled,
        string $licenseStatus
    ): void {
        if (! $localPlan) {
            return;
        }

        // Derive the archival status from the entitlement's effective access.
        if (! $entitlement->isActive()) {
            $status = 'expired';
        } elseif ($renewalCancelled) {
            $status = 'cancelled';
        } else {
            $status = 'active';
        }

        // Keep switch statements exhaustive for future statuses (no-op now).
        unset($licenseStatus);

        if ($status === 'active') {
            // Supersede other active local rows first (partial unique index).
            TenantSubscription::withoutTenant()->where('tenant_id', $tenant->id)
                ->whereIn('status', ['active', 'trialing'])
                ->where(function ($q) use ($entitlement) {
                    $q->whereNull('fs_license_id')
                        ->orWhere('fs_license_id', '!=', $entitlement->fs_license_id);
                })
                ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'auto_renew' => false]);
        }

        TenantSubscription::withoutTenant()->updateOrCreate(
            ['fs_license_id' => $entitlement->fs_license_id],
            [
                'tenant_id' => $tenant->id,
                'plan_id' => $localPlan->id,
                'status' => $status,
                'billing_source' => 'freemius',
                'billing_cycle' => $cycle === 'annual' || $cycle === 'yearly' ? 'yearly' : 'monthly',
                'starts_at' => $entitlement->created_at ?? now(),
                'ends_at' => $expiration,
                'cancelled_at' => $renewalCancelled ? now() : null,
                'auto_renew' => ! $renewalCancelled && $status === 'active',
                'fs_subscription_id' => $entitlement->fs_subscription_id,
                'fs_pricing_id' => $entitlement->fs_pricing_id,
            ]
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function parseDate(mixed $value): ?\DateTimeInterface
    {
        if (empty($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
