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

            $expiration = $this->parseDate($license['expiration'] ?? $subscription['next_payment'] ?? null);
            $isCanceled = (bool) ($license['is_canceled'] ?? false);

            // Prefer the subscription's own status; fall back to license.
            $status = $this->stringOrNull($subscription['status'] ?? null)
                ?? ($isCanceled ? 'cancelled' : 'active');

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
                'is_canceled' => $isCanceled,
                'synced_at' => now(),
                'metadata' => [
                    'billing_cycle' => $cycle,
                    'raw_status' => $status,
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
            $this->mirrorSubscription($tenant, $entitlement, $localPlan, $cycle, $expiration, $isCanceled);

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
            TenantSubscription::where('fs_license_id', $licenseId)
                ->update([
                    'status' => 'expired',
                    'auto_renew' => false,
                    'cancelled_at' => now(),
                ]);

            \App\Plans\PlanEngine::bumpVersion();
        });
    }

    /**
     * Resolve the tenant a license belongs to, using trustworthy signals in
     * order of strength:
     *
     *  1. An existing entitlement binding (a license already mapped to a
     *     tenant) — NEVER re-bound to another tenant by email change.
     *  2. A checkout intent recorded server-side (tenant <-> license) via
     *     metadata — established only by the server before checkout.
     *  3. The licence's Freemius customer email, matched against the tenant
     *     owner's email or the tenant billing email (last resort).
     *
     * @param  array<string, mixed>  $license
     */
    private function resolveTenant(array $license, string $email): ?Tenant
    {
        $licenseId = (string) ($license['id'] ?? '');

        // 1) Existing binding wins — a bound license never moves tenants.
        if ($licenseId !== '') {
            $existing = FsEntitlement::where('fs_license_id', $licenseId)->first();

            if ($existing && $existing->tenant_id) {
                return Tenant::find($existing->tenant_id);
            }
        }

        // 2) Server-side checkout intent (non-forgeable: written only by the
        //    server at checkout initiation, keyed by the customer email the
        //    checkout was created with).
        $fsUserId = $license['user_id'] ?? ($license['user']['id'] ?? null);
        $intent = TenantSubscription::where('billing_source', 'freemius')
            ->where(function ($q) use ($licenseId, $fsUserId, $email) {
                if ($licenseId !== '') {
                    $q->orWhere('fs_license_id', $licenseId);
                }
                // metadata.freemius_intent_email is set server-side at checkout.
                $q->orWhere('metadata->freemius_intent_email', $email);
            })
            ->latest('created_at')
            ->first();

        if ($intent && $intent->tenant_id) {
            return Tenant::find($intent->tenant_id);
        }

        // 3) Last resort: match the licence customer email to a tenant owner.
        return Tenant::whereHas('owner', fn ($q) => $q->where('email', $email))->first()
            ?? Tenant::where('email', $email)->first();
    }

    /**
     * Keep a local archival subscription row in sync with the entitlement
     * so billing history/reports still have a row to point at. Cancelled /
     * prior rows are preserved.
     */
    private function mirrorSubscription(
        Tenant $tenant,
        FsEntitlement $entitlement,
        ?\App\Models\SubscriptionPlan $localPlan,
        ?string $cycle,
        ?\DateTimeInterface $expiration,
        bool $isCanceled
    ): void {
        if (! $localPlan) {
            return;
        }

        $status = $isCanceled ? 'cancelled' : 'active';

        // An expired license must not grant access even if not cancelled.
        if ($status === 'active' && $expiration !== null && $expiration <= new \DateTimeImmutable) {
            $status = 'expired';
        }

        if ($status === 'active') {
            // Supersede other active local rows first (partial unique index).
            TenantSubscription::where('tenant_id', $tenant->id)
                ->whereIn('status', ['active', 'trialing'])
                ->where(function ($q) use ($entitlement) {
                    $q->whereNull('fs_license_id')
                        ->orWhere('fs_license_id', '!=', $entitlement->fs_license_id);
                })
                ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'auto_renew' => false]);
        }

        TenantSubscription::updateOrCreate(
            ['fs_license_id' => $entitlement->fs_license_id],
            [
                'tenant_id' => $tenant->id,
                'plan_id' => $localPlan->id,
                'status' => $status,
                'billing_source' => 'freemius',
                'billing_cycle' => $cycle === 'annual' || $cycle === 'yearly' ? 'yearly' : 'monthly',
                'starts_at' => $entitlement->created_at ?? now(),
                'ends_at' => $expiration,
                'cancelled_at' => $isCanceled ? now() : null,
                'auto_renew' => ! $isCanceled,
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
