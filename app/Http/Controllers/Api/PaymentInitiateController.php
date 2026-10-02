<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\PaymentCompletionService;
use App\Services\PaddleGateway;
use App\Services\PayPalGateway;
use App\Services\PayTabsGateway;
use App\Services\LemonSqueezyGateway;
use App\Services\RefundSyncService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Uid\UuidV7;

class PaymentInitiateController extends Controller
{
    public function gateways(): JsonResponse
    {
        $manualEnabled = (bool) PlatformSetting::get('manual_payment_enabled', false);
        $manualAccount = (string) PlatformSetting::get('manual_payment_account', '');

        return response()->json([
            'paytabs' => [
                'enabled' => PayTabsGateway::enabled(),
                'configured' => PayTabsGateway::configured(),
            ],
            'paypal' => [
                'enabled' => PayPalGateway::enabled(),
                'configured' => PayPalGateway::configured(),
            ],
            'paddle' => [
                'enabled' => PaddleGateway::enabled(),
                'configured' => PaddleGateway::configured(),
                'environment' => PaddleGateway::environment(),
            ],
            'lemonsqueezy' => [
                'enabled' => LemonSqueezyGateway::enabled(),
                'configured' => LemonSqueezyGateway::configured(),
                // Secrets live in the environment — never in the database.
                'secrets_from_env' => true,
            ],
            'manual' => [
                'enabled' => $manualEnabled,
                'configured' => $manualEnabled && $manualAccount !== '',
                'label' => (string) PlatformSetting::get('manual_payment_label', ''),
                'account' => $manualAccount,
                'instructions' => (string) PlatformSetting::get('manual_payment_instructions', ''),
            ],
        ]);
    }

    public function initiate(Request $request): JsonResponse
    {
        $tenant = $request->current_tenant;

        if (! $tenant) {
            return response()->json([
                'message' => 'Your session is no longer valid — please sign out and sign in again.',
            ], 401);
        }

        $tenantId = $tenant->id;

        $validated = $request->validate([
            'plan_id' => 'required|string|exists:subscription_plans,id',
            'billing_cycle' => 'nullable|in:monthly,yearly',
            'gateway' => 'required|string|in:paytabs,paypal,paddle,lemonsqueezy,manual',
            'coupon_code' => 'nullable|string|max:50',
        ]);

        $plan = SubscriptionPlan::findOrFail($validated['plan_id']);
        $user = $request->user();

        if (! $plan->is_active) {
            return response()->json(['message' => 'Plan is not active.'], 422);
        }

        if (! $plan->is_public && ! $user->is_super_admin) {
            return response()->json(['message' => 'Plan is not available for purchase.'], 422);
        }

        $billingCycle = $validated['billing_cycle'] ?? 'monthly';
        $price = $billingCycle === 'yearly' ? $plan->yearly_price : $plan->monthly_price;

        // PayPal/Paddle: convert the plan currency to a gateway-supported
        // currency (Paddle does not support JOD) using the platform-configured
        // exchange rate (currency_rate).
        $fxRate = null;
        $currency = $plan->currency ?? 'JOD';
        if (in_array($validated['gateway'], ['paypal', 'paddle', 'lemonsqueezy'], true)) {
            $targetCurrency = 'USD';

            if ($currency !== $targetCurrency) {
                $fxRate = (float) PlatformSetting::get('currency_rate', 1);
                $fxRate = $fxRate > 0 ? $fxRate : 1;

                $price = round($price * $fxRate, 2);
                $currency = $targetCurrency;
            }
        }

        $couponCode = ! empty($validated['coupon_code']) ? strtoupper(trim($validated['coupon_code'])) : null;

        $payment = null;
        $coupon = null;
        $subscription = null;

        try {
            $result = DB::transaction(function () use (
                $tenantId, $plan, $user, $billingCycle, &$price, $currency,
                $validated, $couponCode, $fxRate, &$payment, &$coupon, &$subscription
            ) {
                // Serialize per-tenant so trial eligibility and supersede can
                // never race: two concurrent checkouts = one trial, never two.
                Tenant::whereKey($tenantId)->lockForUpdate()->first();

                $active = TenantSubscription::where('tenant_id', $tenantId)
                    ->whereIn('status', ['active', 'trialing'])
                    ->latest('starts_at')
                    ->first();

                // An existing *active* subscription stays active until the new
                // payment completes (see markPaid) to avoid service gaps.
                $wasActive = $active && $active->status === 'active';
                $hasHistory = TenantSubscription::where('tenant_id', $tenantId)->exists();

                // ─── Proration ───────────────────────────────────────────
                // Upgrading/downgrading mid-cycle credits the unused days of
                // the current plan against the new price. Trials and lapsed
                // subscriptions are never prorated.
                $prorationCredit = 0.0;
                if ($wasActive && $active->ends_at && $active->ends_at->gt(now())) {
                    $oldPlan = $active->plan;

                    if ($oldPlan) {
                        $oldCycleDays = $active->billing_cycle === 'yearly' ? 365 : 30;
                        $oldPrice = $active->billing_cycle === 'yearly'
                            ? (float) $oldPlan->yearly_price
                            : (float) $oldPlan->monthly_price;
                        $remainingDays = max(0.0, (float) $active->ends_at->diffInSeconds(now(), false) / 86400);

                        $prorationCredit = round($oldPrice * ($remainingDays / $oldCycleDays), 2);

                        // Convert the credit with the same FX rate used for
                        // the charge currency.
                        if ($fxRate !== null && $currency !== ($oldPlan->currency ?? 'JOD')) {
                            $prorationCredit = round($prorationCredit * $fxRate, 2);
                        }

                        $price = max(0.0, round($price - $prorationCredit, 2));
                    }
                }

                $subscription = TenantSubscription::create([
                    'id' => UuidV7::generate(),
                    'tenant_id' => $tenantId,
                    'plan_id' => $plan->id,
                    'status' => ($wasActive || $hasHistory) ? 'pending' : 'trialing',
                    'billing_cycle' => $billingCycle,
                    'starts_at' => now(),
                    'trial_ends_at' => ($wasActive || $hasHistory) ? null : now()->addDays(max(0, (int) ($plan->trial_days ?: 14))),
                    'auto_renew' => true,
                ]);

                // Authoritative coupon claim: row-lock the coupon and RESERVE
                // the usage inside the same transaction so concurrent
                // checkouts can never oversell max_uses. The reservation is
                // released if the payment never completes.
                $discount = 0.0;
                if ($couponCode) {
                    $coupon = Coupon::withoutTenant()
                        ->where('code', $couponCode)
                        ->where(function ($q) use ($tenantId) {
                            $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
                        })
                        ->lockForUpdate()
                        ->first();

                    if (! $coupon || ! $coupon->isValid()) {
                        throw new \RuntimeException('Coupon is invalid or expired.');
                    }

                    $coupon->increment('used_count');

                    $discount = $coupon->applyValue($price);
                    if ($coupon->type === 'fixed' && $fxRate !== null && $coupon->currency !== $currency) {
                        $discount = $coupon->value * $fxRate;
                    }

                    $price = max(0, round($price - $discount, 2));
                }

                // ─── Service fee (+$5 USD on every online sale) ────────────
                $serviceFee = 0.0;
                if ($validated['gateway'] !== 'manual') {
                    $serviceFeeUsd = (float) config('payments.service_fee_usd', 5);

                    if ($currency === 'USD') {
                        $serviceFee = $serviceFeeUsd;
                    } else {
                        $fxRateForFee = (float) PlatformSetting::get('currency_rate', 1);
                        $serviceFee = $fxRateForFee > 0 ? round($serviceFeeUsd / $fxRateForFee, 2) : $serviceFeeUsd;
                    }

                    $price = max(0, round($price + $serviceFee, 2));
                }

                $payment = Payment::create([
                    'id' => UuidV7::generate(),
                    'tenant_id' => $tenantId,
                    'tenant_subscription_id' => $subscription->id,
                    'transaction_id' => 'pending-'.str()->uuid(),
                    'gateway' => $validated['gateway'],
                    'amount' => $price,
                    'currency' => $currency,
                    'status' => 'pending',
                    'payment_method' => $validated['gateway'],
                    'gateway_data' => array_merge(
                        $coupon ? [
                            'coupon_id' => $coupon->id,
                            'coupon_code' => $coupon->code,
                            'coupon_reserved' => true,
                        ] : [],
                        $prorationCredit > 0 ? ['proration_credit' => $prorationCredit] : [],
                    ),
                ]);

                $apiBase = rtrim(config('app.url'), '/');
                $frontend = rtrim(config('app.frontend_url') ?: config('app.url'), '/');
                $customer = ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone ?? '0500000000'];

                // $0 checkout (e.g. 100% coupon): no gateway call — complete now.
                if ($price <= 0) {
                    $payment->update([
                        'status' => 'completed',
                        'paid_at' => now(),
                        'gateway_data' => array_merge($payment->gateway_data ?? [], ['free' => true]),
                    ]);

                    $this->markPaid($payment);

                    return ['free' => true, 'payment_id' => $payment->id, 'frontend' => $frontend];
                }

                // ─── Manual payment (Click / wallet) ─────────────────────
                // No gateway call: the teacher transfers manually, submits
                // the proof, and the admin approves the payment from the
                // admin panel (same completion pipeline as callbacks).
                if ($validated['gateway'] === 'manual') {
                    if (! (bool) PlatformSetting::get('manual_payment_enabled', false)) {
                        throw new \RuntimeException('Manual payment is disabled.');
                    }

                    $payment->update([
                        'payment_method' => 'manual',
                        'gateway_data' => array_merge($payment->gateway_data ?? [], ['manual' => true]),
                    ]);

                    return ['payment_id' => $payment->id, 'status' => 'awaiting_approval'];
                }

                if ($validated['gateway'] === 'paytabs') {
                    if (! PayTabsGateway::enabled() || ! PayTabsGateway::configured()) {
                        throw new \RuntimeException('PayTabs gateway is not configured.');
                    }

                    $result = PayTabsGateway::createPayment([
                        'cart_id' => $payment->id,
                        'amount' => $price,
                        'currency' => $currency,
                        'description' => 'Tadreesly Subscription - '.$plan->name,
                        'customer' => $customer,
                        'callback_url' => $apiBase.'/api/v1/payments/paytabs-callback',
                        'return_url' => $frontend.'/checkout/success?gateway=paytabs&status=paid',
                    ], ['tokenise' => true]);

                    $payment->update([
                        'gateway_data' => array_merge($payment->gateway_data ?? [], $result),
                    ]);

                    return ['redirect_url' => $result['redirect_url'], 'payment_id' => $payment->id];
                }

                // PayPal
                if ($validated['gateway'] === 'paypal') {
                    if (! PayPalGateway::enabled() || ! PayPalGateway::configured()) {
                        throw new \RuntimeException('PayPal gateway is not configured.');
                    }

                    $result = PayPalGateway::createOrder([
                        'cart_id' => $payment->id,
                        'amount' => $price,
                        'currency' => $currency,
                        'description' => 'Tadreesly Subscription - '.$plan->name,
                        'brand_name' => 'Tadreesly',
                        'return_url' => $apiBase.'/api/v1/payments/paypal-capture?payment_id='.$payment->id,
                        'cancel_url' => $frontend.'/checkout/success?gateway=paypal&status=cancelled',
                    ], ['tokenise' => true]);

                    $payment->update([
                        'gateway_data' => array_merge($payment->gateway_data ?? [], ['order_id' => $result['id']]),
                    ]);

                    // Server-side card eligibility (the browser-side eligibility
                    // call can be rejected for sandbox apps). Only needed for the
                    // PayPal CardFields flow.
                    $cardMode = 'none';
                    try {
                        $cardMethods = PayPalGateway::cardMethods();
                        $cardMode = $cardMethods['advanced'] ? 'advanced' : ($cardMethods['basic'] ? 'basic' : 'none');
                    } catch (\Throwable) {
                        $cardMode = 'none';
                    }
                } else {
                    $cardMode = 'none';
                }

            // ─── Paddle ────────────────────────────────────────────
            // Paddle handles the payment method (cards / wallet / local
            // methods) on its own checkout. The backend creates the
            // transaction with the server-computed price; the frontend
            // only opens that transaction in Paddle Checkout.
            if ($validated['gateway'] === 'paddle') {
                if (! PaddleGateway::enabled() || ! PaddleGateway::configured()) {
                    throw new \RuntimeException('Paddle gateway is not configured.');
                }

                $priceKey = $billingCycle === 'yearly' ? 'paddle_price_id_yearly' : 'paddle_price_id_monthly';
                $priceId = $plan->metadata[$priceKey] ?? null;

                if (! is_string($priceId) || $priceId === '') {
                    throw new \RuntimeException("Paddle price ID is not configured for this plan ({$priceKey}).");
                }

                // Enforce the final server-side amount. An inline,
                // non-recurring discount is attached ONLY when an LMS
                // coupon applies — no Paddle discount entities are
                // created or stored.
                $paddleDiscount = null;
                if ($coupon && $discount > 0) {
                    // Always flat: avoids percentage-base mismatch when the
                    // catalog price includes a service fee but the server
                    // discount is computed on the plan price alone.
                    $paddleDiscount = [
                        'description' => 'Tadreesly coupon '.$coupon->code,
                        'type' => 'flat',
                        'amount' => (string) max(1, (int) round($discount * 100)),
                        'recur' => false,
                    ];
                }

                $paddle = PaddleGateway::createTransaction([
                    'price_id' => $priceId,
                    'custom_data' => [
                        'payment_id' => $payment->id,
                        'tenant_id' => $tenantId,
                        'plan_id' => $plan->id,
                        'billing_cycle' => $billingCycle,
                    ],
                    'discount' => $paddleDiscount,
                ]);

                $payment->update([
                    'transaction_id' => 'paddle-'.$paddle['id'],
                    'gateway_data' => array_merge($payment->gateway_data ?? [], [
                        'paddle_transaction_id' => $paddle['id'],
                        'paddle_price_id' => $priceId,
                        'expected_subtotal_minor' => (string) round($price * 100),
                        'paddle_discount' => $paddleDiscount,
                    ]),
                ]);

                return [
                    'payment_id' => $payment->id,
                    'redirect_url' => $paddle['checkout_url'],
                    'paddle_transaction_id' => $paddle['id'],
                    'paddle_client_token' => PaddleGateway::clientToken(),
                    'paddle_env' => PaddleGateway::environment(),
                ];
            }

            // ─── Lemon Squeezy ───────────────────────────────────────
            // Merchant of Record: LS runs its own hosted checkout. The
            // variant comes from the plan mapping (admin-configured), the
            // amount is pinned server-side via custom_price — the frontend
            // never sends a price or a plan. Subscription activation only
            // happens after a verified webhook, never on redirect.
            if ($validated['gateway'] === 'lemonsqueezy') {
                if (! LemonSqueezyGateway::enabled() || ! LemonSqueezyGateway::configured()) {
                    throw new \RuntimeException('Lemon Squeezy gateway is not configured.');
                }

                $variantKey = $billingCycle === 'yearly' ? 'lemon_variant_id_yearly' : 'lemon_variant_id_monthly';
                $variantId = $plan->metadata[$variantKey] ?? null;

                if (! is_numeric($variantId) || (int) $variantId <= 0) {
                    throw new \RuntimeException("Lemon Squeezy variant ID is not configured for this plan ({$variantKey}).");
                }

                $checkout = LemonSqueezyGateway::createCheckout([
                    'variant_id' => (int) $variantId,
                    'price_minor' => max(1, (int) round($price * 100)),
                    'redirect_url' => $frontend.'/checkout/success?gateway=lemonsqueezy&status=paid',
                    'custom_data' => [
                        'payment_id' => $payment->id,
                        'tenant_id' => $tenantId,
                        'plan_id' => $plan->id,
                        'billing_cycle' => $billingCycle,
                        'coupon_code' => (string) ($coupon?->code ?? ''),
                    ],
                ]);

                $payment->update([
                    'transaction_id' => 'lemon-'.$checkout['id'],
                    'gateway_data' => array_merge($payment->gateway_data ?? [], [
                        'lemon_checkout_id' => $checkout['id'],
                        'lemon_variant_id' => (string) $variantId,
                        'expected_subtotal_minor' => (string) max(1, (int) round($price * 100)),
                    ]),
                ]);

                return [
                    'payment_id' => $payment->id,
                    'redirect_url' => $checkout['url'],
                    'lemon_checkout_id' => $checkout['id'],
                ];
            }

            return [
                'redirect_url' => $result['approval_url'],
                'payment_id' => $payment->id,
                // Inline CardFields data (public client id + order id).
                // The redirect URL stays as the PayPal-account fallback.
                'paypal_order_id' => $result['id'],
                'paypal_client_id' => PayPalGateway::clientId(),
                'paypal_env' => PayPalGateway::config()['test_mode'] ? 'sandbox' : 'live',
                'paypal_card_mode' => $cardMode,
            ];
        });

            if (! empty($result['free'])) {
                return response()->json([
                    'payment_id' => $result['payment_id'],
                    'status' => 'completed',
                    'free' => true,
                    'redirect_url' => $result['frontend'].'/checkout/success?gateway=free&status=paid',
                ]);
            }

            if (($result['status'] ?? null) === 'awaiting_approval') {
                // Manual payment (Click / wallet): teacher submits the proof
                // separately, then the admin approves from the admin panel.
                return response()->json([
                    'payment_id' => $result['payment_id'],
                    'status' => 'awaiting_approval',
                ]);
            }

            return response()->json([
                'redirect_url' => $result['redirect_url'] ?? null,
                'payment_id' => $result['payment_id'],
                'paypal_order_id' => $result['paypal_order_id'] ?? null,
                'paypal_client_id' => $result['paypal_client_id'] ?? null,
                'paypal_env' => $result['paypal_env'] ?? null,
                'paypal_card_mode' => $result['paypal_card_mode'] ?? 'none',
                'paddle_transaction_id' => $result['paddle_transaction_id'] ?? null,
                'paddle_client_token' => $result['paddle_client_token'] ?? null,
                'paddle_env' => $result['paddle_env'] ?? null,
                'lemon_checkout_id' => $result['lemon_checkout_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function submitManualProof(Request $request, string $paymentId): JsonResponse
    {
        $tenantId = $request->current_tenant->id;

        $payment = Payment::withoutTenant()
            ->where('id', $paymentId)
            ->where('tenant_id', $tenantId)
            ->where('gateway', 'manual')
            ->where('status', 'pending')
            ->first();

        if (! $payment) {
            return response()->json(['message' => 'Pending manual payment not found.'], 404);
        }

        $validated = $request->validate([
            'payer_name' => 'required|string|max:190',
            'reference' => 'required|string|max:190',
            'notes' => 'nullable|string|max:1000',
            'proof' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        $path = $request->file('proof')->store('manual-proofs/'.$payment->id, 'local');

        $data = array_merge($payment->gateway_data ?? [], [
            'manual' => true,
            'payer_name' => $validated['payer_name'],
            'reference' => $validated['reference'],
            'notes' => $validated['notes'] ?? '',
            'proof_path' => $path,
        ]);

        $payment->update(['gateway_data' => $data]);

        // Notify the platform admins that a manual payment awaits approval.
        AuditLogger::log($request, 'payment.manual_proof', 'payment', $payment->id, [
            'amount' => $payment->amount,
            'payer' => $validated['payer_name'],
        ]);

        return response()->json([
            'message' => 'Payment proof submitted. The platform admin will review and activate your plan.',
            'payment_id' => $payment->id,
        ]);
    }

    public function paymentProof(Request $request, string $paymentId)
    {
        $payment = Payment::withoutTenant()
            ->where('id', $paymentId)
            ->where('gateway', 'manual')
            ->first();

        if (! $payment) {
            abort(404, 'Payment not found.');
        }

        $proofPath = ($payment->gateway_data ?? [])['proof_path'] ?? null;

        if (! $proofPath || ! Storage::disk('local')->exists($proofPath)) {
            abort(404, 'Proof not found.');
        }

        // Tenant owner may view their own proof; admins may view any.
        $user = $request->user();
        if (! $user->is_super_admin) {
            abort_unless(
                (string) $payment->tenant_id === (string) ($request->current_tenant->id ?? $request->header('X-Tenant-Id')),
                403,
                'This action is unauthorized.'
            );
        }

        return Storage::disk('local')->response(
            $proofPath,
            'proof-'.$payment->id.'.'.pathinfo($proofPath, PATHINFO_EXTENSION)
        );
    }

    public function testConnection(Request $request): JsonResponse
    {
        if (! $request->user()->is_super_admin) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $gateway = $request->input('gateway');

        if ($gateway === 'paytabs') {
            if (! PayTabsGateway::configured()) {
                return response()->json(['message' => 'PayTabs credentials are missing.'], 422);
            }
            try {
                $result = PayTabsGateway::queryPayment('TEST_REF');
                $isAuthError = isset($result['message']) && str_contains(strtolower((string) $result['message']), 'auth');

                return response()->json([
                    'ok' => ! $isAuthError,
                    'message' => $isAuthError
                        ? 'Connection failed: invalid profile ID or server key.'
                        : 'Connection OK — PayTabs server responded.',
                ]);
            } catch (\Throwable $e) {
                return response()->json(['ok' => false, 'message' => 'Connection failed: '.$e->getMessage()], 502);
            }
        }

        if ($gateway === 'paypal') {
            if (! PayPalGateway::configured()) {
                return response()->json(['message' => 'PayPal credentials are missing.'], 422);
            }
            try {
                PayPalGateway::accessToken();

                return response()->json(['ok' => true, 'message' => 'Connection OK — PayPal token received.']);
            } catch (\Throwable $e) {
                return response()->json(['ok' => false, 'message' => 'Connection failed: '.$e->getMessage()], 502);
            }
        }

        if ($gateway === 'paddle') {
            if (! PaddleGateway::configured()) {
                return response()->json(['message' => 'Paddle credentials are missing.'], 422);
            }

            $result = PaddleGateway::testConnection();

            return response()->json([
                'ok' => $result['ok'],
                'message' => $result['message'],
            ], $result['ok'] ? 200 : 502);
        }

        if ($gateway === 'lemonsqueezy') {
            if (! LemonSqueezyGateway::configured()) {
                return response()->json(['message' => 'Lemon Squeezy credentials are missing (LEMON_SQUEEZY_API_KEY / LEMON_SQUEEZY_SIGNING_SECRET / LEMON_SQUEEZY_STORE_ID).'], 422);
            }

            $result = LemonSqueezyGateway::testConnection();

            return response()->json([
                'ok' => $result['ok'],
                'message' => $result['message'],
            ], $result['ok'] ? 200 : 502);
        }

        return response()->json(['message' => 'Unknown gateway.'], 422);
    }

    /**
     * Refund webhook — استرجاع نقدي نُفذ داخل لوحة البوابة (Click/wallet،
     * PayTabs، PayPal...) يتم مزامنته هنا. المنصة لا تبدأ الاسترجاعات أبداً.
     *
     * المصادقة: توقيع HMAC-SHA256 للـ payload كاملاً في الترويسة
     * X-Webhook-Signature بالصيغة "sha256=…" باستخدام REFUND_WEBHOOK_SECRET.
     */
    public function refundWebhook(Request $request): JsonResponse
    {
        $secret = (string) config('payments.refund_webhook_secret', '');

        if ($secret === '') {
            return response()->json(['message' => 'Refund webhook is not configured (REFUND_WEBHOOK_SECRET).'], 503);
        }

        $signature = (string) $request->header('X-Webhook-Signature', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if ($signature === '' || ! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $validated = $request->validate([
            'payment_id' => 'sometimes|required|string',
            'transaction_id' => 'sometimes|required|string',
            'gateway' => 'sometimes|string|max:30',
            'refund_reference' => 'nullable|string|max:190',
            'amount' => 'nullable|numeric|min:0.01',
            'currency' => 'nullable|string|max:10',
        ]);

        if (empty($validated['payment_id']) && empty($validated['transaction_id'])) {
            return response()->json(['message' => 'payment_id or transaction_id is required.'], 422);
        }

        $query = Payment::withoutTenant();
        $payment = ! empty($validated['payment_id'])
            ? $query->where('id', $validated['payment_id'])->first()
            : $query->where('transaction_id', $validated['transaction_id'])->first();

        if (! $payment) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        // The reported gateway must be the one that actually collected the money.
        if (! empty($validated['gateway']) && strcasecmp($validated['gateway'], $payment->gateway) !== 0) {
            return response()->json(['message' => 'Gateway mismatch.'], 422);
        }

        // Amount/currency sanity: never record a refund for a different charge.
        if (isset($validated['amount']) && abs((float) $validated['amount'] - (float) $payment->amount) > 0.01) {
            return response()->json(['message' => 'Amount mismatch.'], 422);
        }

        if (! empty($validated['currency']) && strcasecmp($validated['currency'], $payment->currency) !== 0) {
            return response()->json(['message' => 'Currency mismatch.'], 422);
        }

        $result = app(RefundSyncService::class)->sync($payment, [
            'refund_reference' => $validated['refund_reference'] ?? null,
            'synced_at' => now()->toIso8601String(),
        ]);

        AuditLogger::log($request, 'payment.refund_webhook', 'payment', $payment->id, [
            'status' => $result['status'],
            'already_refunded' => $result['already_refunded'],
        ]);

        return response()->json([
            'message' => 'OK',
            'payment_id' => $payment->id,
            'status' => $result['status'],
            'already_refunded' => $result['already_refunded'],
        ]);
    }

    public function paytabsCallback(Request $request): JsonResponse
    {
        $tranRef = $request->input('tran_ref');
        $cartId = $request->input('cart_id');

        if (! $tranRef || ! $cartId) {
            return response()->json(['message' => 'Invalid callback.'], 400);
        }

        $payment = Payment::withoutTenant()->where('id', $cartId)->where('gateway', 'paytabs')->first();

        if (! $payment) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        $result = PayTabsGateway::queryPayment($tranRef);

        // Gateway outages must not burn the payment: stay pending and let the
        // merchant retry. Only definitive statuses move the payment forward.
        if (! is_array($result) || empty($result)) {
            return response()->json(['message' => 'Gateway unreachable, payment kept pending.'], 502);
        }

        $status = $result['tran_status'] ?? null;

        // The queried transaction must actually belong to this payment —
        // otherwise an attacker could pair their own tran_ref with any
        // pending payment_id and have it marked as paid.
        if (($result['cart_id'] ?? null) !== $payment->id) {
            $this->releaseCouponReservation($payment);
            $payment->update(['status' => 'failed']);

            return response()->json(['message' => 'Transaction does not match this payment.'], 422);
        }

        $payment->update([
            'transaction_id' => $tranRef,
            'gateway_data' => array_merge($payment->gateway_data ?? [], ['callback' => $result]),
        ]);

        // Only 'A' (authorized) means the transaction is actually complete.
        // 'H' (hold) and 'P' (pending) must remain pending.
        if ($status === 'A') {
            $gatewayAmount = (float) ($result['cart_amount'] ?? 0);
            $gatewayCurrency = (string) ($result['cart_currency'] ?? '');

            if ($gatewayAmount <= 0 || abs($gatewayAmount - (float) $payment->amount) > 0.01) {
                $this->releaseCouponReservation($payment);
                $payment->update(['status' => 'failed']);

                return response()->json(['message' => 'Amount mismatch.'], 422);
            }

            if ($gatewayCurrency !== '' && strcasecmp($gatewayCurrency, $payment->currency) !== 0) {
                $this->releaseCouponReservation($payment);
                $payment->update(['status' => 'failed']);

                return response()->json(['message' => 'Currency mismatch.'], 422);
            }

            // Idempotent completion: concurrent gateway retries can never
            // double-apply a payment (duplicate ledger rows, double renewals).
            $affected = Payment::withoutTenant()
                ->where('id', $payment->id)
                ->where('status', '!=', 'completed')
                ->update(['status' => 'completed', 'paid_at' => now()]);

            if ($affected === 1) {
                $this->markPaid($payment);
                $this->captureSavedMethod($payment, 'paytabs', $result);
            }
        } elseif (in_array($status, ['H', 'P'], true)) {
            $payment->update(['status' => 'pending']);
        } else {
            $this->releaseCouponReservation($payment);
            $payment->update(['status' => 'failed']);
        }

        return response()->json(['message' => 'OK']);
    }

    /**
     * Shared, idempotent PayPal completion used by the redirect fallback,
     * the inline CardFields capture endpoint and the webhook. Returns
     * [success, reason] so each caller formats its own response.
     */
    private function captureAndComplete(Payment $payment, string $orderId): array
    {
        // Only capture orders we actually created for this payment. Accepting
        // an arbitrary PayPal order token would let an attacker complete a
        // different (cheaper) order against someone else's payment.
        if (($payment->gateway_data['order_id'] ?? null) !== $orderId) {
            $this->releaseCouponReservation($payment);
            $payment->update(['status' => 'failed']);

            return [false, 'order_mismatch'];
        }

        try {
            $result = PayPalGateway::captureOrder($orderId);

            // The captured order must be for the same amount and currency
            // that we charged.
            $capture = $result['purchase_units'][0]['payments']['captures'][0] ?? [];
            $capturedAmount = (float) ($capture['amount']['value'] ?? 0);
            $capturedCurrency = (string) ($capture['amount']['currency_code'] ?? '');

            if ($capturedAmount <= 0 || abs($capturedAmount - (float) $payment->amount) > 0.01) {
                $this->releaseCouponReservation($payment);
                $payment->update(['status' => 'failed']);

                return [false, 'amount_mismatch'];
            }

            if ($capturedCurrency !== '' && strcasecmp($capturedCurrency, $payment->currency) !== 0) {
                $this->releaseCouponReservation($payment);
                $payment->update(['status' => 'failed']);

                return [false, 'currency_mismatch'];
            }

            // Idempotent completion — concurrent/duplicate captures can never
            // double-apply.
            $affected = Payment::withoutTenant()
                ->where('id', $payment->id)
                ->where('status', '!=', 'completed')
                ->update([
                    'transaction_id' => $orderId,
                    'status' => 'completed',
                    'paid_at' => now(),
                    'gateway_data' => array_merge($payment->gateway_data ?? [], ['capture' => $result]),
                ]);

            if ($affected === 1) {
                $this->markPaid($payment);
                $this->captureSavedMethod($payment, 'paypal', $result);
            }

            return [true, 'completed'];
        } catch (\Throwable $e) {
            // Transient gateway error: keep the payment pending (the money
            // may or may not have been captured) — never cancel the
            // subscription on a network blip.
            $payment->update([
                'status' => 'pending',
                'gateway_data' => array_merge($payment->gateway_data ?? [], ['capture_error' => $e->getMessage()]),
            ]);

            return [false, 'gateway_error'];
        }
    }

    /**
     * Redirect fallback (existing flow): PayPal account checkout. Kept
     * fully intact — CardFields never replaces it.
     */
    public function paypalCapture(Request $request): RedirectResponse
    {
        $orderId = $request->input('token');
        $paymentId = $request->input('payment_id');

        $payment = Payment::withoutTenant()->where('id', $paymentId)->where('gateway', 'paypal')->first();
        $frontend = rtrim(config('app.frontend_url') ?: config('app.url'), '/');
        $successUrl = $frontend.'/checkout/success?gateway=paypal&status=paid';
        $failUrl = $frontend.'/checkout/success?gateway=paypal&status=failed';

        if (! $payment || ! $orderId) {
            return redirect()->away($failUrl);
        }

        [$success, $reason] = $this->captureAndComplete($payment, $orderId);

        return redirect()->away($success ? $successUrl : $failUrl.($reason === 'gateway_error' ? '&reason='.urlencode('gateway_error') : ''));
    }

    /**
     * Inline CardFields capture: the frontend never decides success on its
     * own — this endpoint re-runs the PayPal capture server-side, verifies
     * amount/currency and applies the payment idempotently.
     */
    public function paypalCaptureJson(Request $request, string $paymentId): JsonResponse
    {
        $tenantId = (string) ($request->current_tenant->id ?? '');

        $payment = Payment::withoutTenant()
            ->where('id', $paymentId)
            ->where('tenant_id', $tenantId)
            ->where('gateway', 'paypal')
            ->first();

        if (! $payment) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        $orderId = (string) ($request->input('order_id') ?? '');

        if ($orderId === '' || ($payment->gateway_data['order_id'] ?? null) !== $orderId) {
            return response()->json(['message' => 'Invalid PayPal order.'], 422);
        }

        [$success, $reason] = $this->captureAndComplete($payment, $orderId);

        if (! $success) {
            return response()->json(['message' => 'Capture failed.', 'reason' => $reason], 422);
        }

        return response()->json([
            'message' => 'Payment completed.',
            'payment_id' => $payment->id,
            'status' => 'completed',
        ]);
    }

    /**
     * PayPal webhook — PAYMENT.CAPTURE.COMPLETED. Authenticated by PayPal's
     * documented self-verification (signature over the raw body against the
     * certificate in paypal-cert-url). Processing is idempotent per event id.
     */
    public function paypalWebhook(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();

        try {
            $signatureOk = PayPalGateway::verifyWebhookSignature($request->headers->all(), $rawBody);
        } catch (\Throwable) {
            $signatureOk = false;
        }

        if (! $signatureOk) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = json_decode($rawBody, true) ?? [];

        if (($event['event_type'] ?? '') !== 'PAYMENT.CAPTURE.COMPLETED') {
            // Known-valid webhook for an event we do not act on: acknowledge.
            return response()->json(['message' => 'Ignored event.']);
        }

        $eventId = (string) ($event['id'] ?? '');

        if ($eventId === '') {
            return response()->json(['message' => 'Missing event id.'], 422);
        }

        $resource = $event['resource'] ?? [];
        $orderId = (string) ($resource['supplementary_data']['related_ids']['order_id'] ?? '');

        if ($orderId === '') {
            return response()->json(['message' => 'Missing order id.'], 422);
        }

        $payment = Payment::withoutTenant()
            ->where('gateway', 'paypal')
            ->where(function ($q) use ($orderId) {
                $q->where('transaction_id', $orderId)
                    ->orWhereJsonContains('gateway_data->order_id', $orderId);
            })
            ->first();

        if (! $payment) {
            return response()->json(['message' => 'Payment not found.'], 404);
        }

        $data = $payment->gateway_data ?? [];
        $processed = $data['webhook_events'] ?? [];

        // Idempotent: the same event id is never processed twice.
        if (in_array($eventId, $processed, true)) {
            return response()->json(['message' => 'Duplicate event ignored.']);
        }

        $processed[] = $eventId;

        $payment->update([
            'gateway_data' => array_merge($data, [
                'webhook_events' => $processed,
                'last_webhook' => [
                    'event_id' => $eventId,
                    'event_type' => (string) ($event['event_type'] ?? ''),
                    'received_at' => now()->toIso8601String(),
                ],
            ]),
        ]);

        [$success, $reason] = $this->captureAndComplete($payment, $orderId);

        if (! $success && $reason !== 'gateway_error') {
            // Definitive failures (mismatch/order mismatch) are final.
            return response()->json(['message' => 'Capture failed.', 'reason' => $reason], 422);
        }

        return response()->json(['message' => 'OK']);
    }

    /**
     * Idempotent application of a completed payment to its target
     * (booking or subscription). Only ever called after the payment was
     * transitioned to 'completed' by a guarded conditional update, so
     * concurrent callbacks cannot double-apply.
     */
    private function markPaid(Payment $payment): void
    {
        app(PaymentCompletionService::class)->complete($payment);
    }

    /**
     * Persist a reusable payment method (saved card / vaulted method) after
     * a successful checkout so future renewals can be charged without the
     * customer. Best effort — never blocks completion.
     */
    private function captureSavedMethod(Payment $payment, string $gateway, array $result): void
    {
        try {
            if ($payment->tenant_subscription_id === null) {
                return; // only subscriptions are auto-renewable.
            }

            $method = match ($gateway) {
                'paytabs' => PayTabsGateway::extractSavedMethod($result),
                'paypal' => PayPalGateway::extractSavedMethod($result),
                default => null,
            };

            if ($method === null) {
                return;
            }

            $tenant = \App\Models\Tenant::find($payment->tenant_id);

            if ($tenant) {
                app(\App\Services\AutoRenewalService::class)
                    ->storeSavedMethod($tenant, $gateway, $method);
            }
        } catch (\Throwable) {
            // Saving the card must never fail an already-completed payment.
        }
    }

    /**
     * Release the coupon usage reserved at initiate for a payment that
     * failed or was abandoned. Idempotent via the coupon_reserved flag.
     */
    private function releaseCouponReservation(Payment $payment): void
    {
        $data = $payment->gateway_data ?? [];

        if (empty($data['coupon_id']) || empty($data['coupon_reserved'])) {
            return;
        }

        Coupon::withoutTenant()->where('id', $data['coupon_id'])->decrement('used_count', 1);

        $data['coupon_reserved'] = false;
        $payment->update(['gateway_data' => $data]);
    }
}
