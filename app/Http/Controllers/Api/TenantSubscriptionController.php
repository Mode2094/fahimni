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

        $tenantId = $request->header('X-Tenant-Id');

        $subscription = TenantSubscription::where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan.features')
            ->latest('starts_at')
            ->first();

        if (! $subscription) {
            return response()->json(['subscription' => null]);
        }

        return response()->json([
            'subscription' => new TenantSubscriptionResource($subscription),
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
