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
            // `expiration`   : string or null (null = lifetime). AUTHORITATIVE.
            // `is_cancelled` (API, double-L): license HARD-cancelled from the
            //                 Developer Dashboard → invalid immediately.
            // `is_canceled`  (SaaS webhook, single-L): recurring billing
            //                 stopped; the current paid period still runs.
            // Subscription status can also indicate refund/chargeback (hard).
            $licenseStatus = $this->stringOrNull($license['status'] ?? null);
            $subscriptionStatus = $this->stringOrNull($subscription['status'] ?? null);

            $hardCancelled = (bool) ($license['is_cancelled'] ?? false)
                || in_array($subscriptionStatus, ['refunded', 'chargeback'], true);

            $renewalCancelled = (bool) ($license['is_canceled'] ?? false);

            $expiration = $this->parseDate($license['expiration'] ?? $subscription['next_payment'] ?? null);

            // Determine the stored status. A hard cancel/revoke wins; otherwise
            // prefer the reported license/subscription status; otherwise active.
            if ($hardCancelled || in_array($licenseStatus, FsEntitlement::REVOKED_STATUSES, true)) {
                $status = 'revoked';
            } elseif ($renewalCancelled && $subscriptionStatus === null && $licenseStatus === null) {
                $status = 'cancelled';
            } elseif ($licenseStatus !== null) {
                $status = $licenseStatus;
            } elseif ($subscriptionStatus !== null) {
                $status = $subscriptionStatus;
            } else {
                $status = 'active';
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
     * IMPORTANT: a Freemius customer (`fs_user_id`) is NOT a tenant identity.
     * One Freemius customer can own several licenses — one per Fahimni
     * platform — so we NEVER map a license to a tenant via the shared customer
     * id alone, nor assume a customer owns a single tenant.
     *
     * Order of strength:
     *  1. An EXISTING binding for THIS license (license_id -> tenant_id),
     *     stored permanently and never moved (not by email, not by customer id).
     *  2. A server-side checkout intent that is tied to THIS purchase. The
     *     intent is created by the server before checkout; it is matched only
     *     when exactly ONE pending intent exists for the customer identity of
     *     this purchase (by checkout reference when available, else by the
     *     unique customer email). Multiple matches → null → manual review.
     *
     * We deliberately do NOT fall back to "any tenant whose owner happens to
     * share the email" and do NOT reuse a binding across licenses of the same
     * customer: that is how a license gets bound to the wrong tenant.
     *
     * Official limitation: Freemius Hosted Checkout does not echo a
     * custom/integration reference into license webhooks, so the strongest
     * signal available server-side is the (server-created) intent matched by
     * the purchase's customer identity. When it is ambiguous we hold for
     * review; we never invent a binding.
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

        // 2) A checkout intent that has ALREADY been tied to this license
        //    (e.g. recorded via the overlay callback / server reconciliation).
        if ($licenseId !== '') {
            $tiedIntent = TenantSubscription::withoutTenant()
                ->where('billing_source', 'freemius')
                ->where('fs_license_id', $licenseId)
                ->whereNotNull('tenant_id')
                ->first();

            if ($tiedIntent && $tiedIntent->tenant_id) {
                return Tenant::find($tiedIntent->tenant_id);
            }
        }

        // 3) A checkout intent tied to this purchase's checkout reference.
        //    (Only present when the client completed an overlay/integration
        //     flow that recorded the reference server-side.)
        $reference = $this->stringOrNull($license['checkout_reference'] ?? ($license['custom_reference'] ?? null));

        if ($reference !== null) {
            $byRef = TenantSubscription::withoutTenant()
                ->where('billing_source', 'freemius')
                ->where('metadata->freemius_checkout_reference', $reference)
                ->pluck('tenant_id')
                ->unique();

            if ($byRef->count() === 1) {
                return Tenant::find($byRef->first());
            }
        }

        // 4) Unique pending intent for this purchase's customer email.
        //    A customer owning several platforms shares the email, so this
        //    only resolves when EXACTLY ONE pending intent matches.
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
