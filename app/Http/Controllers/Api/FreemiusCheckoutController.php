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

        // Fresh hosted checkout. The URL already carries product + plan; we
        // only add the prefilled/read-only email. No price is ever passed
        // (Freemius uses the plan's configured price).
        $reference = $this->recordIntent($tenant, $plan, $cycle, (string) $email, $request->user()?->id);

        // The redirect back to us carries the checkout reference so `complete`
        // can reconcile ONLY the intent created by this very checkout.
        $redirect = rtrim(config('app.frontend_url') ?: config('app.url'), '/')
            .'/checkout/success?ref='.urlencode($reference);

        $url = FreemiusGateway::hostedCheckoutLink($fsPlanId, (string) $email, [
            'redirect' => $redirect,
        ]);

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
     * Post-checkout return. The browser may land here after payment.
     *
     * We NEVER trust the redirect: we re-fetch the license from Freemius by
     * its id (if present) and re-derive the entitlement server-side. If no
     * licence id is available we fall back to reconciling by the tenant's
     * email via the API — still fully server-side.
     */
    public function complete(Request $request): JsonResponse
    {
        $tenant = $request->current_tenant;

        if (! $tenant) {
            return response()->json(['message' => 'Sales session expired.'], 401);
        }

        $licenseId = (string) $request->query('license_id', '');
        $reference = (string) $request->query('ref', '');

        // If a checkout reference is supplied it MUST belong to this tenant —
        // a license_id alone is never trusted from the browser.
        if ($reference !== '') {
            $intent = TenantSubscription::where('billing_source', 'freemius')
                ->where('metadata->freemius_checkout_reference', $reference)
                ->first();

            if (! $intent || $intent->tenant_id !== $tenant->id) {
                return response()->json(['message' => 'Unknown checkout reference.'], 422);
            }
        }

        if ($licenseId !== '') {
            try {
                $result = $this->sync->syncByLicenseId($licenseId);
            } catch (\App\Exceptions\FreemiusApiException) {
                // Transient: report pending; the webhook will confirm.
                return response()->json(['status' => 'pending']);
            }

            $entitlement = $result['entitlement'] ?? null;

            if ($entitlement && $entitlement->tenant_id === $tenant->id && $entitlement->isActive()) {
                return response()->json([
                    'status' => 'active',
                    'entitlement' => [
                        'license_id' => $entitlement->fs_license_id,
                        'plan_id' => $entitlement->plan_id,
                        'expiration' => $entitlement->expiration?->toIso8601String(),
                    ],
                ]);
            }
        }

        // No license id (or it is not ours yet): report "pending". The
        // verified webhook will land shortly and activate access. We do NOT
        // activate anything from the browser.
        return response()->json(['status' => 'pending']);
    }

    /**
     * Trusted purchase→tenant binding (overlay/integration callback path).
     *
     * Called by our own BFF from the authenticated session AFTER the Freemius
     * overlay checkout reports success. This is the officially-supported
     * reliable link: the Freemius overlay `success` callback yields
     * `purchase.license_id` in the logged-in user's session, and the server
     * ties that license to the CURRENT tenant permanently.
     *
     * Safety:
     *  - Requires an authenticated tenant session (never trusts the license id
     *    alone from an anonymous caller).
     *  - Re-fetches the license from the Freemius API server-side.
     *  - If the license is already bound to a DIFFERENT tenant, the bind is
     *    REFUSED (a license never moves tenants).
     *  - Records license_id -> tenant_id permanently (on the entitlement; the
     *    webhook's resolveTenant() honors it first).
     */
    public function bind(Request $request): JsonResponse
    {
        $tenant = $request->current_tenant;

        if (! $tenant) {
            return response()->json(['message' => 'Your session is no longer valid — please sign in again.'], 401);
        }

        $validated = $request->validate([
            'license_id' => 'required|string|max:64',
        ]);

        $licenseId = $validated['license_id'];

        $existing = FsEntitlement::where('fs_license_id', $licenseId)->first();

        // A license already bound to another tenant must never be moved.
        if ($existing && $existing->tenant_id && $existing->tenant_id !== $tenant->id) {
            return response()->json([
                'message' => 'This license is already linked to another platform.',
            ], 409);
        }

        // Verify the license really exists in Freemius before binding.
        try {
            $result = $this->sync->syncByLicenseId($licenseId);
        } catch (\App\Exceptions\FreemiusApiException) {
            return response()->json(['message' => 'Could not verify the license right now — try again shortly.'], 502);
        }

        if (($result['status'] ?? null) === 'not_found') {
            return response()->json(['message' => 'Unknown license.'], 404);
        }

        // If sync could not auto-resolve (ambiguous/unknown customer), bind the
        // (verified) license to the authenticated tenant explicitly.
        $entitlement = $result['entitlement'] ?? null;

        if (! $entitlement || $entitlement->tenant_id !== $tenant->id) {
            $license = FreemiusGateway::getLicenseOrFail($licenseId);

            if (! $license) {
                return response()->json(['message' => 'Unknown license.'], 404);
            }

            $subscription = FreemiusGateway::getLatestSubscriptionOrFail($licenseId);
            $entitlement = $this->sync->applyLicense($tenant, $license, $subscription);
        }

        AuditLogger::log($request, 'subscription.freemius_bound', 'entitlement', $entitlement->id, [
            'license_id' => $licenseId,
        ]);

        return response()->json([
            'status' => $entitlement->isActive() ? 'active' : 'inactive',
            'license_id' => $entitlement->fs_license_id,
        ]);
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
