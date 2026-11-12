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
        $this->recordIntent($tenant, $plan, $cycle, (string) $email);

        $url = FreemiusGateway::hostedCheckoutLink($fsPlanId, (string) $email);

        AuditLogger::log($request, 'subscription.freemius_checkout', 'plan', $plan->id, ['cycle' => $cycle]);

        return response()->json([
            'mode' => 'checkout',
            'checkout_url' => $url,
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
     * email the checkout was created with. This lets the webhook bind the
     * resulting license to the correct tenant even if the email later changes
     * to match another tenant. Never grants access on its own.
     */
    private function recordIntent(Tenant $tenant, SubscriptionPlan $plan, string $cycle, string $email): void
    {
        DB::transaction(function () use ($tenant, $plan, $cycle, $email) {
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
                    'freemius_intent_email' => strtolower(trim($email)),
                ],
            ]);
        });
    }
}
