<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscription\SubscribeRequest;
use App\Http\Resources\TenantSubscriptionResource;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Services\PaddleGateway;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

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

        // The legacy subscribe endpoint never cuts service before payment:
        // the active subscription keeps running until a payment completes
        // through the checkout flow (payments/initiate -> markPaid), which
        // supersedes it atomically.
        $active = TenantSubscription::where('tenant_id', $tenantId)
            ->whereIn('status', ['active', 'trialing'])
            ->latest('starts_at')
            ->first();

        if ($active) {
            return response()->json([
                'message' => 'You already have an active subscription. Renew or upgrade it through the checkout flow.',
                'subscription' => new TenantSubscriptionResource($active->load('plan')),
            ], 422);
        }

        $billingCycle = $request->billing_cycle ?? 'monthly';
        $price = $billingCycle === 'yearly' ? $plan->yearly_price : $plan->monthly_price;

        // Trial is granted once per tenant: no history = trial, otherwise must pay.
        $hasHistory = TenantSubscription::where('tenant_id', $tenantId)->exists();

        $subscription = TenantSubscription::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'plan_id' => $plan->id,
            'status' => $hasHistory ? 'pending' : 'trialing',
            'billing_cycle' => $billingCycle,
            'starts_at' => now(),
            'trial_ends_at' => $hasHistory ? null : now()->addDays($plan->trial_days),
            'auto_renew' => true,
        ]);

        AuditLogger::log($request, 'subscription.subscribed', 'plan', $plan->id, ['cycle' => $billingCycle]);

        return response()->json([
            'subscription' => new TenantSubscriptionResource($subscription->load('plan')),
        ], 201);
    }

    public function cancel(string $id): JsonResponse
    {
        $subscription = TenantSubscription::findOrFail($id);

        $this->authorize('cancel', $subscription);

        // Paddle-managed subscription: cancel in Paddle FIRST so local state
        // never claims a cancellation Paddle does not know about. A Paddle
        // API failure aborts the local cancel instead of desyncing the two.
        $paddleSubscriptionId = ($subscription->metadata ?? [])['paddle_subscription_id'] ?? null;

        if (is_string($paddleSubscriptionId) && $paddleSubscriptionId !== '') {
            try {
                PaddleGateway::cancelSubscription($paddleSubscriptionId);
            } catch (\Throwable $e) {
                return response()->json([
                    'message' => 'تعذّر إلغاء الاشتراك لدى Paddle — حاول مجددًا لاحقًا. (Could not cancel the subscription in Paddle — please try again.)',
                ], 502);
            }
        }

        $subscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'auto_renew' => false,
        ]);

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
