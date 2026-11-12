<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscription\SubscribeRequest;
use App\Http\Resources\TenantSubscriptionResource;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantSubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TenantSubscription::class);

        $tenantId = $request->header('X-Tenant-Id');

        $subscriptions = TenantSubscription::where('tenant_id', $tenantId)
            ->with('plan')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'subscriptions' => TenantSubscriptionResource::collection($subscriptions),
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TenantSubscription::class);

        $tenantId = (string) $request->header('X-Tenant-Id');

        // Single source of truth: the effective state (Freemius entitlement OR
        // legacy local subscription). Never report a stale local row as the
        // current subscription when a Freemius license actually governs access.
        $state = \App\Plans\SubscriptionState::make()->resolve($tenantId);

        if ($state['source'] === \App\Plans\SubscriptionState::SOURCE_NONE || ! $state['plan_id']) {
            return response()->json(['subscription' => null]);
        }

        $plan = \App\Models\SubscriptionPlan::with('features')->find($state['plan_id']);

        if (! $plan) {
            return response()->json(['subscription' => null]);
        }

        return response()->json([
            'subscription' => [
                'id' => $state['subscription_id'],
                'plan_id' => $plan->id,
                'plan' => new \App\Http\Resources\SubscriptionPlanResource($plan),
                'status' => $state['status'],
                'billing_cycle' => $state['billing_cycle'],
                'billing_source' => $state['source'],
                'cancel_at_period_end' => $state['cancel_at_period_end'] ?? false,
                'starts_at' => null,
                'ends_at' => $state['ends_at']?->toIso8601String(),
                'trial_ends_at' => $state['trial_ends_at']?->toIso8601String(),
            ],
        ]);
    }

    public function subscribe(SubscribeRequest $request): JsonResponse
    {
        $this->authorize('create', TenantSubscription::class);

        $tenantId = $request->header('X-Tenant-Id');
        $plan = SubscriptionPlan::findOrFail($request->plan_id);

        if (! $plan->is_active) {
            return response()->json(['message' => 'Plan is not active.'], 422);
        }

        // Platform subscriptions are collected exclusively via Freemius. This
        // legacy endpoint no longer provisions a paid subscription locally:
        // it only reports the current state so the client can start the
        // Freemius checkout (POST subscriptions/freemius/checkout).
        $active = TenantSubscription::where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trialing'])
            ->latest('starts_at')
            ->first();

        if ($active) {
            return response()->json([
                'message' => 'You already have an active subscription. Manage or upgrade it through the billing portal.',
                'subscription' => new TenantSubscriptionResource($active->load('plan')),
            ], 422);
        }

        return response()->json([
            'message' => 'Subscriptions are billed through Freemius. Start a checkout to subscribe.',
            'checkout_endpoint' => 'subscriptions/freemius/checkout',
        ], 409);
    }

    public function cancel(string $id): JsonResponse
    {
        $subscription = TenantSubscription::findOrFail($id);

        $this->authorize('cancel', $subscription);

        // Freemius-owned subscriptions MUST be cancelled through Freemius so
        // the two systems never desync. Use the Freemius cancel endpoint
        // (POST subscriptions/freemius/cancel) instead of this legacy route.
        if ($subscription->billing_source === 'freemius') {
            return response()->json([
                'message' => 'This subscription is managed by Freemius. Cancel it from the billing portal.',
            ], 409);
        }

        $subscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'auto_renew' => false,
        ]);

        \App\Plans\PlanEngine::bumpVersion();

        return response()->json([
            'subscription' => new TenantSubscriptionResource($subscription->load('plan')),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $subscription = TenantSubscription::withoutTenant()
            ->with('plan.features')
            ->findOrFail($id);

        $this->authorize('view', $subscription);

        return response()->json([
            'subscription' => new TenantSubscriptionResource($subscription),
        ]);
    }
}
