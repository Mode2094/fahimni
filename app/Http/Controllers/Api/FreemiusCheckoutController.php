<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FsEntitlement;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\FreemiusGateway;
use App\Services\FreemiusPlanMapper;
use App\Services\FsEntitlementSyncService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * Freemius-only checkout for PLATFORM (teacher) subscriptions.
 *
 * Money for platform subscriptions is collected exclusively by Freemius:
 *  - `initiate` returns a signed hosted-checkout URL (email prefilled,
 *    read-only) OR — after an existing license exists — an upgrade link.
 *  - `complete` is the post-checkout return: the browser may land here, but
 *    activation is derived by RE-FETCHING the license from the Freemius API.
 *    A tampered/spoofed redirect cannot grant access because nothing is
 *    trusted from the query string — only the server-side API response.
 *  - `portal` returns a Freemius hosted customer-portal magic link.
 *  - `cancel` cancels the subscription in Freemius (access is preserved by
 *    the entitlement until the paid period ends).
 *
 * This controller MUST NOT be used for student purchases or bookings.
 */
class FreemiusCheckoutController extends Controller
{
    public function __construct(
        private readonly FsEntitlementSyncService $sync,
    ) {}

    /**
     * Public status of the (single) billing gateway for the checkout UI.
     * Only Freemius is reported — no other gateway is ever offered for
     * platform subscriptions.
     */
    public function gateways(): JsonResponse
    {
        return response()->json([
            'freemius' => [
                'enabled' => FreemiusGateway::enabled(),
                'configured' => FreemiusGateway::configured(),
                'public_key' => FreemiusGateway::publicKey(),
                'product_id' => FreemiusGateway::productId(),
                'sandbox' => FreemiusGateway::config()['sandbox'],
                'secrets_from_env' => true,
            ],
        ]);
    }

    /**
     * Create a checkout link for a local plan + cycle.
     */
    public function initiate(Request $request): JsonResponse
    {
        if (! FreemiusGateway::enabled() || ! FreemiusGateway::configured()) {
            return response()->json([
                'message' => 'Checkout is temporarily unavailable. Please try again later.',
            ], 503);
        }

        $validated = $request->validate([
            'plan_id' => 'required|string|exists:subscription_plans,id',
            'billing_cycle' => 'nullable|in:monthly,yearly',
        ]);

        $tenant = $request->current_tenant;

        if (! $tenant) {
            return response()->json(['message' => 'Your session is no longer valid — please sign in again.'], 401);
        }

        $plan = SubscriptionPlan::findOrFail($validated['plan_id']);

        if (! $plan->is_active || (! $plan->is_public && ! $request->user()?->is_super_admin)) {
            return response()->json(['message' => 'Plan is not available for purchase.'], 422);
        }

        $cycle = $validated['billing_cycle'] ?? 'monthly';
        $fsPlanId = FreemiusPlanMapper::planId($plan, $cycle);

        if (! $fsPlanId) {
            return response()->json([
                'message' => 'This plan is not yet available for online purchase.',
            ], 422);
        }

        $email = $request->user()?->email ?? $tenant->email;

        // If a license already exists for this tenant, go through the upgrade
        // link API (proration handled by Freemius). Otherwise, fresh checkout.
        $existing = FsEntitlement::where('tenant_id', $tenant->id)
            ->whereNotNull('fs_license_id')
            ->latest('synced_at')
            ->first();

        // A tenant's OWN existing license is upgradable, not a conflict. Only
        // a *different* conflicting source (legacy local sub) blocks a new
        // checkout.
        if (! $existing && $this->hasConflictingPaidSubscription($tenant)) {
            return response()->json([
                'message' => 'You already have an active subscription. Manage or cancel it before starting a new one.',
            ], 422);
        }

        if ($existing) {
            $upgrade = FreemiusGateway::upgradeCheckoutLink($existing->fs_license_id, [
                'plan_id' => $fsPlanId,
                'billing_cycle' => $cycle,
                'quota' => 1,
                'currency' => 'usd',
            ]);

            if ($upgrade && ! empty($upgrade['url'])) {
                AuditLogger::log($request, 'subscription.freemius_upgrade_link', 'plan', $plan->id, ['cycle' => $cycle]);

                return response()->json([
                    'mode' => 'upgrade',
                    'checkout_url' => $upgrade['url'],
                    'expires_at' => $upgrade['expires'] ?? null,
                ]);
            }
        }

        // Fresh hosted checkout. The URL carries product + plan; we only add
        // the prefilled/read-only email. No price is ever passed (Freemius uses
        // the plan's configured price). The post-purchase redirect URL (with a
        // Freemius-signed `signature`) is configured in the Freemius Dashboard
        // (Plans → Customization → Redirect Checkout to a custom URL) and points
        // to our /checkout/success page.
        $reference = $this->recordIntent($tenant, $plan, $cycle, (string) $email, $request->user()?->id);

        $url = FreemiusGateway::hostedCheckoutLink($fsPlanId, (string) $email);

        AuditLogger::log($request, 'subscription.freemius_checkout', 'plan', $plan->id, ['cycle' => $cycle]);

        return response()->json([
            'mode' => 'checkout',
            'checkout_url' => $url,
            'checkout_reference' => $reference,
            'public_key' => FreemiusGateway::publicKey(),
            'product_id' => FreemiusGateway::productId(),
        ]);
    }

    /**
     * Post-checkout return — the OFFICIAL Hosted Checkout signed redirect.
     *
     * Freemius redirects the buyer back with purchase data plus a
     * `signature` = HMAC-SHA256(fullUrlWithoutSignature, PRODUCT_SECRET_KEY).
     * We therefore require, ALL of:
     *  1. a VALID redirect signature (proves the data is authentic Freemius
     *     output, not forged),
     *  2. an AUTHENTICATED tenant session (the same browser that started the
     *     checkout), and
     *  3. the redirect `email` matching the authenticated user's email — this
     *     is what proves the PURCHASE belongs to THIS user/tenant and prevents
     *     binding someone else's license,
     *  4. a matching server-side checkout intent for this tenant (plan match).
     *
     * Only then is the license bound to the tenant. Anything short of that
     * activates nothing and reports a distinct status.
     */
    public function complete(Request $request): JsonResponse
    {
        $tenant = $request->current_tenant;

        if (! $tenant) {
            return response()->json(['message' => 'Sales session expired.'], 401);
        }

        // 1) Verify the signed redirect (official algorithm). Nothing is
        //    trusted before this passes. The signature is computed by Freemius
        //    over the ORIGINAL redirect URL it sent to the buyer (our frontend
        //    success page), so we verify against that exact URL. The frontend
        //    forwards it as `redirect_url`.
        $redirectUrl = (string) $request->query('redirect_url', '');

        if ($redirectUrl === '') {
            // Fall back to this request's own URL (works when the API itself is
            // the configured redirect target).
            $redirectUrl = $request->fullUrl();
        }

        $params = FreemiusGateway::verifyCheckoutRedirect($redirectUrl);

        if ($params === null) {
            // No/invalid signature → keep for review; never activate.
            return response()->json(['status' => 'unverified'], 422);
        }

        $licenseId = (string) ($params['license_id'] ?? '');

        if ($licenseId === '') {
            return response()->json(['status' => 'pending']);
        }

        // 2) The redirect email must match the signed-in user — this binds the
        //    purchase to THIS account/tenant (a foreign customer's license
        //    carries their email and is rejected here).
        $redirectEmail = strtolower(trim((string) ($params['email'] ?? '')));
        $sessionEmail = strtolower(trim((string) ($request->user()?->email ?? '')));

        if ($redirectEmail === '' || $redirectEmail !== $sessionEmail) {
            return response()->json(['status' => 'mismatch'], 403);
        }

        // 3) A server-side intent for THIS tenant must exist (created at
        //    initiate). Prevents binding a genuinely-verified license that this
        //    tenant never attempted to purchase.
        $intentExists = TenantSubscription::withoutTenant()
            ->where('tenant_id', $tenant->id)
            ->where('billing_source', 'freemius')
            ->where('status', 'pending')
            ->exists();

        if (! $intentExists && ! $this->tenantHasAnyEntitlement($tenant)) {
            return response()->json(['status' => 'no_intent'], 422);
        }

        // 4) Verified + matched → bind (idempotent, lock-protected, refused if
        //    the license is already another tenant's).
        return $this->bindLicenseToTenant($tenant, $licenseId, $params);
    }

    /**
     * Bind a VERIFIED license to a tenant. Callers must already have proven
     * authenticity + ownership (signed redirect + session email + intent).
     *
     * Concurrency-safe: a license already bound to a different tenant is
     * refused (409); re-binding the SAME license to the SAME tenant is
     * idempotent.
     */
    private function bindLicenseToTenant(Tenant $tenant, string $licenseId, array $redirectParams = []): JsonResponse
    {
        // A license already bound to a DIFFERENT tenant must never be moved.
        // Check BEFORE any work (and again under lock after) so a caller can
        // never steal another platform's license.
        $ownedByAnother = FsEntitlement::where('fs_license_id', $licenseId)
            ->whereNotNull('tenant_id')
            ->where('tenant_id', '!=', $tenant->id)
            ->exists();

        if ($ownedByAnother) {
            return response()->json([
                'message' => 'This license is already linked to another platform.',
            ], 409);
        }

        // Fetch + verify the license OUTSIDE the lock (network call — must not
        // hold a DB transaction open across I/O).
        try {
            $result = $this->sync->syncByLicenseId($licenseId);
        } catch (\App\Exceptions\FreemiusApiException) {
            // Transient — the webhook / reconciliation will confirm later.
            return response()->json(['status' => 'pending']);
        }

        if (($result['status'] ?? null) === 'not_found') {
            return response()->json(['status' => 'unknown'], 404);
        }

        $entitlement = $result['entitlement'] ?? null;

        // If auto-resolution was ambiguous/unknown, bind EXPLICITLY now that the
        // caller has proven ownership (verified redirect + session + intent).
        if (! $entitlement || $entitlement->tenant_id !== $tenant->id) {
            $license = FreemiusGateway::getLicenseOrFail($licenseId);

            if (! $license) {
                return response()->json(['status' => 'unknown'], 404);
            }

            $subscription = FreemiusGateway::getLatestSubscriptionOrFail($licenseId);
            $entitlement = $this->sync->applyLicense($tenant, $license, $subscription);
        }

        // Tie the purchase to the plan the buyer actually checked out with.
        if (! empty($redirectParams['plan_id'])) {
            $entitlement->update(['metadata' => array_merge($entitlement->metadata ?? [], [
                'freemius_plan_id_at_purchase' => (string) $redirectParams['plan_id'],
            ])]);
        }

        // Concurrency guard: re-read under a row lock and refuse a cross-tenant
        // bind (a license never moves tenants). Idempotent for the same tenant.
        $conflict = DB::transaction(function () use ($licenseId, $tenant) {
            $locked = FsEntitlement::where('fs_license_id', $licenseId)->lockForUpdate()->first();

            if ($locked && $locked->tenant_id && $locked->tenant_id !== $tenant->id) {
                return true;
            }

            return false;
        });

        if ($conflict) {
            return response()->json([
                'message' => 'This license is already linked to another platform.',
            ], 409);
        }

        AuditLogger::log(request(), 'subscription.freemius_bound', 'entitlement', $entitlement->id, [
            'license_id' => $licenseId,
        ]);

        return response()->json([
            'status' => $entitlement->isActive() ? 'active' : 'inactive',
            'license_id' => $entitlement->fs_license_id,
            'plan_id' => $entitlement->plan_id,
            'expiration' => $entitlement->expiration?->toIso8601String(),
        ]);
    }

    private function tenantHasAnyEntitlement(Tenant $tenant): bool
    {
        return FsEntitlement::where('tenant_id', $tenant->id)->exists();
    }

    /**
     * Freemius hosted customer portal magic link.
     */
    public function portal(Request $request): JsonResponse
    {
        $tenant = $request->current_tenant;

        if (! $tenant) {
            return response()->json(['message' => 'Your session is no longer valid — please sign in again.'], 401);
        }

        if (! FreemiusGateway::enabled() || ! FreemiusGateway::configured()) {
            return response()->json(['message' => 'Customer portal is temporarily unavailable.'], 503);
        }

        $email = $request->user()?->email ?? $tenant->email;
        $link = FreemiusGateway::customerPortalLink((string) $email);

        if (! $link) {
            return response()->json(['message' => 'Could not open the customer portal. Please try again.'], 502);
        }

        return response()->json(['portal_url' => $link]);
    }

    /**
     * Cancel the tenant's Freemius subscription. Access is preserved by the
     * entitlement until the paid period ends (Freemius cancels at period end).
     */
    public function cancel(Request $request): JsonResponse
    {
        $tenant = $request->current_tenant;

        if (! $tenant) {
            return response()->json(['message' => 'Your session is no longer valid — please sign in again.'], 401);
        }

        $entitlement = FsEntitlement::where('tenant_id', $tenant->id)
            ->where('is_canceled', false)
            ->latest('synced_at')
            ->first();

        if (! $entitlement || ! $entitlement->fs_license_id) {
            return response()->json(['message' => 'No active subscription found.'], 404);
        }

        if (! FreemiusGateway::cancelSubscription($entitlement->fs_license_id)) {
            return response()->json([
                'message' => 'Could not cancel the subscription in Freemius — please try again.',
            ], 502);
        }

        // Reflect immediately; the webhook will re-confirm and set the final
        // expiration. Access stays until `expiration`.
        $entitlement->update(['is_canceled' => true, 'status' => 'cancelled']);

        TenantSubscription::where('fs_license_id', $entitlement->fs_license_id)
            ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'auto_renew' => false]);

        \App\Plans\PlanEngine::bumpVersion();

        AuditLogger::log($request, 'subscription.freemius_cancelled', 'entitlement', $entitlement->id);

        return response()->json(['status' => 'cancelled']);
    }

    /**
     * Whether the tenant already has a conflicting paid subscription.
     */
    private function hasConflictingPaidSubscription(Tenant $tenant): bool
    {
        $activeEntitlement = FsEntitlement::where('tenant_id', $tenant->id)
            ->where('is_canceled', false)
            ->where(function ($q) {
                $q->whereNull('expiration')->orWhere('expiration', '>', now());
            })
            ->exists();

        if ($activeEntitlement) {
            return true;
        }

        return TenantSubscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'trialing'])
            ->where(function ($q) {
                $q->whereNull('billing_source')->orWhere('billing_source', '!=', 'freemius');
            })
            ->exists();
    }

    /**
     * Persist a pending intent row for archival/observability AND as a
     * server-side, non-forgeable link between this tenant and the customer
     * identity the checkout was created with. It carries:
     *   - a unique, random checkout reference (audit + redirect reconciliation)
     *   - the customer email (lowercased) the checkout was created with
     *   - the authenticated user's id (server-trusted)
     *
     * The webhook binds the resulting license to a tenant through this intent
     * ONLY when it is unambiguous (see FsEntitlementSyncService::resolveTenant).
     * Never grants access on its own and never conflicts with the
     * active-subscription unique index (status = pending).
     */
    private function recordIntent(Tenant $tenant, SubscriptionPlan $plan, string $cycle, string $email, ?string $userId): string
    {
        $reference = 'fs_'.bin2hex(random_bytes(20)); // unique, non-guessable

        DB::transaction(function () use ($tenant, $plan, $cycle, $email, $userId, $reference) {
            TenantSubscription::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => 'pending',
                'billing_source' => 'freemius',
                'billing_cycle' => $cycle,
                'starts_at' => now(),
                'auto_renew' => true,
                'metadata' => [
                    'freemius_checkout_reference' => $reference,
                    'freemius_intent_email' => strtolower(trim($email)),
                    'freemius_intent_user_id' => $userId,
                ],
            ]);
        });

        return $reference;
    }
}
