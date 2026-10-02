<?php

namespace App\Services;

use App\Contracts\AutoChargeGateway;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Charges a saved payment method (any gateway implementing
 * AutoChargeGateway) when a subscription reaches its renewal date.
 *
 * Guarantees:
 *  - one charge attempt per tenant per day (metadata dedup),
 *  - no duplicate pending Paddle renewals inside a 7-day window,
 *  - completion side effects always flow through PaymentCompletionService.
 */
class AutoRenewalService
{
    /** @var array<string, class-string<AutoChargeGateway>> */
    public const GATEWAYS = [
        'paytabs' => PayTabsGateway::class,
        'paypal' => PayPalGateway::class,
        'paddle' => PaddleGateway::class,
    ];

    private const PENDING_PADDLE_GRACE_DAYS = 7;

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Attempt to charge the tenant's saved method for the next cycle.
     * Returns true when the subscription should be considered renewed
     * (synchronous success, or a Paddle renewal was initiated).
     */
    public function attemptAutoCharge(TenantSubscription $subscription): bool
    {
        $tenant = Tenant::find($subscription->tenant_id);
        $plan = SubscriptionPlan::find($subscription->plan_id);

        if (! $tenant || ! $plan || ! $subscription->auto_renew) {
            return false;
        }

        $gateway = (string) ($tenant->payment_gateway ?? '');
        $token = (string) ($tenant->payment_token ?? '');

        if ($gateway === '' || $token === '' || ! isset(self::GATEWAYS[$gateway])) {
            return false;
        }

        $gatewayClass = self::GATEWAYS[$gateway];

        if (! class_exists($gatewayClass) || ! is_subclass_of($gatewayClass, AutoChargeGateway::class)) {
            return false;
        }

        // One charge attempt per day — never hammer the gateway on retries.
        $meta = $subscription->metadata ?? [];
        if (($meta['last_auto_charge_date'] ?? null) === now()->toDateString()) {
            return false;
        }

        // A Paddle renewal may still be awaiting webhook confirmation; do not
        // stack duplicate transactions while one is in flight.
        if ($gateway === 'paddle' && $this->hasPendingPaddleRenewal($subscription)) {
            return false;
        }

        [$amount, $currency] = $this->amountFor($plan, $subscription->billing_cycle, $gateway);

        $payment = Payment::withoutTenant()->create([
            'tenant_id' => $tenant->id,
            'tenant_subscription_id' => $subscription->id,
            'transaction_id' => 'auto-'.(string) Str::uuid(),
            'gateway' => $gateway,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'pending',
            'payment_method' => 'saved_card',
            'gateway_data' => [
                'auto_renewal' => true,
                'card_last_four' => $tenant->card_last_four,
                ...($gateway === 'paddle' ? [
                    'expected_subtotal_minor' => (int) round($amount * 100),
                ] : []),
            ],
        ]);

        $result = $gatewayClass::chargeSaved($token, [
            'cart_id' => 'renewal-'.$payment->id,
            'amount' => $amount,
            'currency' => $currency,
            'description' => 'Tadreesly Subscription Renewal - '.$plan->name,
            'custom_data' => [
                'payment_id' => (string) $payment->id,
                'tenant_id' => (string) $tenant->id,
            ],
        ]);

        $meta['last_auto_charge_date'] = now()->toDateString();
        $subscription->update(['metadata' => $meta]);

        Log::info('Auto-renewal charge attempted', [
            'subscription_id' => $subscription->id,
            'gateway' => $gateway,
            'success' => $result['success'],
            'message' => $result['message'],
        ]);

        if (! $result['success']) {
            $payment->update(['status' => 'failed']);
            $this->notifyOwners($tenant, false, $plan, $result['message']);

            return false;
        }

        if ($gateway === 'paddle') {
            // Async: transaction.completed will complete this payment and
            // extend the subscription via PaymentCompletionService.
            $payment->update([
                'transaction_id' => $result['tran_ref'],
                'gateway_data' => array_merge($payment->gateway_data ?? [], [
                    'paddle_transaction_id' => $result['tran_ref'],
                ]),
            ]);

            return true;
        }

        $affected = Payment::withoutTenant()
            ->where('id', $payment->id)
            ->where('status', '!=', 'completed')
            ->update([
                'transaction_id' => $result['tran_ref'],
                'status' => 'completed',
                'paid_at' => now(),
            ]);

        if ($affected === 1) {
            app(PaymentCompletionService::class)->complete($payment->fresh());
            $this->notifyOwners($tenant, true, $plan);
        }

        return true;
    }

    /**
     * Persist a saved payment method captured at checkout/callback/webhook.
     */
    public function storeSavedMethod(Tenant $tenant, string $gateway, array $method): void
    {
        if (($method['token'] ?? '') === '') {
            return;
        }

        Tenant::where('id', $tenant->id)
            ->update([
                'payment_token' => $method['token'],
                'payment_gateway' => $gateway,
                'card_brand' => $method['brand'] ?? null,
                'card_last_four' => $method['last4'] ?? null,
            ]);
    }

    private function hasPendingPaddleRenewal(TenantSubscription $subscription): bool
    {
        return Payment::withoutTenant()
            ->where('tenant_subscription_id', $subscription->id)
            ->where('gateway', 'paddle')
            ->where('payment_method', 'saved_card')
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subDays(self::PENDING_PADDLE_GRACE_DAYS))
            ->exists();
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function amountFor(SubscriptionPlan $plan, string $billingCycle, string $gateway): array
    {
        $base = $billingCycle === 'yearly'
            ? (float) ($plan->yearly_price ?? $plan->monthly_price)
            : (float) $plan->monthly_price;
        $currency = strtoupper((string) ($plan->currency ?? 'JOD'));

        if ($gateway === 'paytabs') {
            return [$base, $currency];
        }

        // PayPal & Paddle only accept their supported currencies — convert
        // through the platform rate exactly like checkout does.
        [$convertedCurrency, $convertedAmount] = PayPalGateway::normalizeCurrency($currency, $base);

        return [$convertedAmount, $convertedCurrency];
    }

    private function notifyOwners(Tenant $tenant, bool $success, SubscriptionPlan $plan, ?string $failureReason = null): void
    {
        $userIds = TenantUser::where('tenant_id', $tenant->id)
            ->whereIn('role', ['owner', 'admin'])
            ->where('status', 'active')
            ->pluck('user_id');

        $owners = User::whereIn('id', $userIds)->where('is_active', true)->get();

        if ($owners->isEmpty()) {
            return;
        }

        if ($success) {
            $this->notifications->dispatch(
                $owners,
                'subscription.auto_renewed',
                'تم تجديد اشتراكك تلقائياً',
                "تم خصم قيمة تجديد خطة \"{$plan->name}\" من بطاقتك المحفوظة بنجاح.",
                null,
                $tenant->id,
                ['plan_name' => $plan->name],
                ['database', 'mail'],
            );

            return;
        }

        $this->notifications->dispatch(
            $owners,
            'subscription.auto_renewal_failed',
            'فشل التجديد التلقائي',
            'تعذر خصم قيمة تجديد الاشتراك من بطاقتك المحفوظة'
                .($failureReason ? ": {$failureReason}" : '.')
                .' يرجى تحديث بيانات الدفع أو التجديد يدوياً قبل انتهاء الصلاحية.',
            null,
            $tenant->id,
            ['plan_name' => $plan->name],
            ['database', 'mail'],
        );
    }
}
