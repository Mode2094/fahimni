<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\WebhookEvent;
use App\Services\LemonSqueezyGateway;
use App\Services\PaymentCompletionService;
use App\Services\RefundSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Uid\UuidV7;

/**
 * Lemon Squeezy webhook endpoint.
 *
 * - Public route (Lemon Squeezy sends no Sanctum token) — the ONLY
 *   protection is the X-Signature verification over the raw request body
 *   (HMAC-SHA256, constant-time compare) performed BEFORE anything is read
 *   or mutated.
 * - Idempotency: every event is claimed in webhook_events under the unique
 *   (provider, event_id) index before processing; a duplicate delivery is
 *   acknowledged without reprocessing (Lemon Squeezy sends no timestamp
 *   header, so this ledger is also the replay protection).
 * - The backend is the single source of truth: the checkout redirect
 *   ("success" URL) never activates anything — only a verified webhook can.
 * - Prices and plans always come from server-side records: the initial
 *   purchase amount is pinned via custom_price at checkout and validated
 *   against expected_subtotal_minor; the frontend never sends a price.
 * - Tenant isolation: initial purchases are routed through the internal
 *   payment id carried in custom_data (never user-supplied); renewals and
 *   lifecycle events are routed through the lemon_subscription_id bound to
 *   a tenant_subscription. Unknown ids are acknowledged and ignored.
 */
class LemonSqueezyWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = (string) $request->header('X-Signature', '');

        // 1. Signature FIRST — before parsing or mutating anything.
        if (! LemonSqueezyGateway::verifyWebhookSignature($signature, $rawBody)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = json_decode($rawBody, true) ?? [];
        $meta = is_array($event['meta'] ?? null) ? $event['meta'] : [];
        $eventName = (string) ($meta['event_name'] ?? '');
        $webhookId = (string) ($meta['webhook_id'] ?? '');
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $customData = is_array($meta['custom_data'] ?? null) ? $meta['custom_data'] : [];

        if ($eventName === '' || $webhookId === '') {
            return response()->json(['message' => 'Malformed event.'], 422);
        }

        // Known event we do not act on — acknowledge so LS stops retrying.
        if (! in_array($eventName, LemonSqueezyGateway::SUPPORTED_EVENTS, true)) {
            return response()->json(['message' => 'Ignored event.']);
        }

        // 2. Idempotency claim (unique provider+event_id) before processing.
        $recorded = WebhookEvent::claim(
            WebhookEvent::PROVIDER_LEMON_SQUEEZY,
            $webhookId,
            $eventName,
            $rawBody
        );

        if (! $recorded) {
            // Already processed (or currently processing) — safe ack.
            return response()->json(['message' => 'Duplicate event.']);
        }

        try {
            DB::transaction(function () use ($eventName, $data, $customData) {
                match ($eventName) {
                    'order_created' => $this->orderCreated($data, $customData),
                    'order_refunded' => $this->orderRefunded($data),
                    'subscription_created',
                    'subscription_updated',
                    'subscription_cancelled',
                    'subscription_resumed',
                    'subscription_expired',
                    'subscription_paused',
                    'subscription_unpaused' => $this->subscriptionEvent($eventName, $data, $customData),
                    default => null,
                };
            });

            $recorded->markProcessed();
        } catch (\Throwable $e) {
            // Never leak gateway details. The event stays un-processed so
            // Lemon Squeezy retries — safer than silently dropping a signal.
            $recorded->markFailed($e->getMessage());

            Log::warning('lemonsqueezy.webhook.processing_error', [
                'payment.provider' => 'lemonsqueezy',
                'payment.event' => $eventName,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Processing error.'], 500);
        }

        return response()->json(['message' => 'OK']);
    }

    /**
     * order_created — the definitive "money is in" signal.
     *
     * Initial purchase: located via custom_data.payment_id (set at
     * initiate, never trusted from the frontend) and validated against the
     * server-side expected amount. Renewal: located via the
     * lemon_subscription_id bound to a tenant_subscription.
     */
    private function orderCreated(array $data, array $customData): void
    {
        $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        $orderId = (string) ($data['id'] ?? '');
        $status = (string) ($attributes['status'] ?? '');

        if ($orderId === '' || $status !== 'paid') {
            return; // failed / pending orders are not money-in signals.
        }

        $subtotalMinor = (int) ($attributes['subtotal'] ?? 0);
        $currency = strtoupper((string) ($attributes['currency'] ?? ''));
        $lsSubscriptionId = isset($attributes['subscription_id']) ? (string) $attributes['subscription_id'] : null;
        $lsCustomerId = isset($attributes['customer_id']) ? (string) $attributes['customer_id'] : null;
        $variantId = isset($attributes['variant_id']) ? (string) $attributes['variant_id'] : null;

        // ── Initial purchase (custom_data carries our internal payment id) ──
        $paymentId = is_string($customData['payment_id'] ?? null) ? $customData['payment_id'] : null;

        if ($paymentId !== null && $paymentId !== '') {
            $this->completeInitialPayment($orderId, $subtotalMinor, $currency, $lsSubscriptionId, $lsCustomerId, $variantId, $customData);

            return;
        }

        // ── Renewal (no custom_data — recurring charge by LS) ─────────────
        // Never guess: if the LS subscription id cannot be mapped to an LMS
        // tenant_subscription, acknowledge the event and do nothing.
        if ($lsSubscriptionId !== null) {
            $this->completeRenewal($orderId, $subtotalMinor, $currency, $lsSubscriptionId, $lsCustomerId, $variantId);
        }
    }

    private function completeInitialPayment(
        string $orderId,
        int $subtotalMinor,
        string $currency,
        ?string $lsSubscriptionId,
        ?string $lsCustomerId,
        ?string $variantId,
        array $customData
    ): void {
        $paymentId = (string) $customData['payment_id'];
        $expectedTenant = isset($customData['tenant_id']) ? (string) $customData['tenant_id'] : null;

        $payment = Payment::withoutTenant()
            ->where('id', $paymentId)
            ->where('gateway', 'lemonsqueezy')
            ->lockForUpdate()
            ->first();

        if (! $payment) {
            return; // Unknown internal payment — nothing to apply.
        }

        // Tenant isolation: never credit a different tenant's payment.
        if ($expectedTenant !== null && (string) $payment->tenant_id !== $expectedTenant) {
            return;
        }

        // Amount/currency are the authoritative server-side values
        // (catalog price ± coupon / proration / service fee) pinned via
        // custom_price at checkout and stored at initiate.
        $expectedMinor = (int) ($payment->gateway_data['expected_subtotal_minor'] ?? 0);

        if ($subtotalMinor <= 0 || $expectedMinor <= 0 || $subtotalMinor !== $expectedMinor) {
            $payment->update(['status' => 'failed']);

            return;
        }

        if ($currency !== '' && strtoupper($currency) !== strtoupper((string) $payment->currency)) {
            $payment->update(['status' => 'failed']);

            return;
        }

        $data = $payment->gateway_data ?? [];
        $data['lemon_order_id'] = $orderId;
        if ($lsSubscriptionId !== null) {
            $data['lemon_subscription_id'] = $lsSubscriptionId;
        }
        if ($lsCustomerId !== null) {
            $data['lemon_customer_id'] = $lsCustomerId;
        }
        if ($variantId !== null) {
            $data['lemon_variant_id'] = $variantId;
        }

        // Guarded completion — the real race protection.
        $affected = Payment::withoutTenant()
            ->where('id', $payment->id)
            ->where('status', '!=', 'completed')
            ->update([
                'transaction_id' => $orderId,
                'status' => 'completed',
                'paid_at' => now(),
                'gateway_data' => $data,
            ]);

        if ($affected === 1) {
            $this->linkLemonSubscription($payment->subscription, $lsSubscriptionId, $lsCustomerId, $variantId);
            app(PaymentCompletionService::class)->complete($payment->fresh());
        }
    }

    private function completeRenewal(
        string $orderId,
        int $subtotalMinor,
        string $currency,
        string $lsSubscriptionId,
        ?string $lsCustomerId,
        ?string $variantId
    ): void {
        $subscription = TenantSubscription::withoutTenant()
            ->whereJsonContains('metadata->lemon_subscription_id', $lsSubscriptionId)
            ->lockForUpdate()
            ->first();

        if (! $subscription) {
            return; // No local binding — do not fabricate a renewal.
        }

        // Idempotent renewal: the unique (gateway, transaction_id) index
        // plus the existence check make a replayed renewal a no-op.
        $exists = Payment::withoutTenant()
            ->where('gateway', 'lemonsqueezy')
            ->where('transaction_id', $orderId)
            ->exists();

        if ($exists) {
            return;
        }

        $amountMajor = $subtotalMinor > 0 ? round($subtotalMinor / 100, 2) : 0.0;

        $payment = Payment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $subscription->tenant_id,
            'tenant_subscription_id' => $subscription->id,
            'transaction_id' => $orderId,
            'gateway' => 'lemonsqueezy',
            'amount' => $amountMajor,
            'currency' => $currency !== '' ? $currency : 'USD',
            'status' => 'pending',
            'payment_method' => 'lemonsqueezy',
            'gateway_data' => [
                'lemon_order_id' => $orderId,
                'lemon_subscription_id' => $lsSubscriptionId,
                'lemon_customer_id' => $lsCustomerId,
                'lemon_variant_id' => $variantId,
                'renewal' => true,
            ],
        ]);

        // Guarded completion then extend the subscription period.
        $affected = Payment::withoutTenant()
            ->where('id', $payment->id)
            ->where('status', '!=', 'completed')
            ->update(['status' => 'completed', 'paid_at' => now()]);

        if ($affected === 1) {
            $this->linkLemonSubscription($subscription, $lsSubscriptionId, $lsCustomerId, $variantId);
            app(PaymentCompletionService::class)->complete($payment->fresh());
        }
    }

    /**
     * Bind the Lemon Squeezy subscription/customer/variant ids to the
     * tenant_subscription metadata so renewal / cancellation events can be
     * routed. Never overwrites an existing binding.
     */
    private function linkLemonSubscription(?TenantSubscription $subscription, ?string $subscriptionId, ?string $customerId, ?string $variantId): void
    {
        if (! $subscription) {
            return;
        }

        $meta = $subscription->metadata ?? [];

        if ($subscriptionId !== null && empty($meta['lemon_subscription_id'])) {
            $meta['lemon_subscription_id'] = $subscriptionId;
        }
        if ($customerId !== null && empty($meta['lemon_customer_id'])) {
            $meta['lemon_customer_id'] = $customerId;
        }
        if ($variantId !== null && empty($meta['lemon_variant_id'])) {
            $meta['lemon_variant_id'] = $variantId;
        }

        $subscription->update(['metadata' => $meta]);
    }

    /**
     * Subscription lifecycle events. Lemon Squeezy copies custom_data from
     * the checkout onto the subscription, so created/updated events carry
     * our payment_id — used to bind the lemon_subscription_id on first
     * contact. Statuses map to the internal model; provider-specific
     * statuses never leak into business logic.
     */
    private function subscriptionEvent(string $eventName, array $data, array $customData): void
    {
        $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        $lsSubscriptionId = (string) ($data['id'] ?? '');
        $lsStatus = (string) ($attributes['status'] ?? '');
        $customerId = isset($attributes['customer_id']) ? (string) $attributes['customer_id'] : null;
        $variantId = isset($attributes['variant_id']) ? (string) $attributes['variant_id'] : null;

        if ($lsSubscriptionId === '') {
            return;
        }

        $subscription = TenantSubscription::withoutTenant()
            ->whereJsonContains('metadata->lemon_subscription_id', $lsSubscriptionId)
            ->lockForUpdate()
            ->first();

        // First contact: custom_data copied from the checkout carries our
        // payment_id — resolve the pending payment's subscription.
        if (! $subscription) {
            $paymentId = is_string($customData['payment_id'] ?? null) ? $customData['payment_id'] : null;

            if ($paymentId !== null && $paymentId !== '') {
                $payment = Payment::withoutTenant()
                    ->where('id', $paymentId)
                    ->where('gateway', 'lemonsqueezy')
                    ->first();

                $subscription = $payment?->subscription;
            }
        }

        if (! $subscription) {
            return;
        }

        $meta = $subscription->metadata ?? [];
        $meta['lemon_subscription_id'] = $lsSubscriptionId;
        $meta['lemon_status'] = $lsStatus !== '' ? $lsStatus : $eventName;
        if ($customerId !== null) {
            $meta['lemon_customer_id'] = $customerId;
        }
        if ($variantId !== null) {
            $meta['lemon_variant_id'] = $variantId;
        }

        $internalStatus = LemonSqueezyGateway::mapStatus($lsStatus);
        $renewsAt = $this->dateOrNull($attributes['renews_at'] ?? null);
        $endsAt = $this->dateOrNull($attributes['ends_at'] ?? null);
        $trialEndsAt = $this->dateOrNull($attributes['trial_ends_at'] ?? null);

        switch ($eventName) {
            case 'subscription_cancelled':
                // LS cancels at the end of the billing period: keep service
                // until ends_at, stop future renewals locally.
                $meta['lemon_cancelled_at'] = $data['attributes']['cancelled_at'] ?? now()->toIso8601String();
                $subscription->update([
                    'auto_renew' => false,
                    'cancelled_at' => $subscription->cancelled_at ?? now(),
                    'status' => $endsAt !== null && $endsAt->isPast() ? 'expired' : $subscription->status,
                    'ends_at' => $endsAt ?? $subscription->ends_at,
                    'metadata' => $meta,
                ]);
                break;

            case 'subscription_expired':
                $subscription->update(['status' => 'expired', 'auto_renew' => false, 'metadata' => $meta]);
                break;

            case 'subscription_paused':
                // No internal 'paused' status — billing stops, service keeps
                // its current state until LS reports otherwise.
                $subscription->update(['auto_renew' => false, 'metadata' => $meta]);
                break;

            case 'subscription_unpaused':
            case 'subscription_resumed':
                $subscription->update([
                    'auto_renew' => true,
                    'status' => $internalStatus ?? 'active',
                    'ends_at' => $renewsAt ?? $endsAt ?? $subscription->ends_at,
                    'metadata' => $meta,
                ]);
                break;

            case 'subscription_created':
                $subscription->update([
                    'auto_renew' => ! in_array($lsStatus, [LemonSqueezyGateway::STATUS_CANCELLED, LemonSqueezyGateway::STATUS_PAUSED, LemonSqueezyGateway::STATUS_EXPIRED], true),
                    'status' => $internalStatus ?? $subscription->status,
                    'trial_ends_at' => $trialEndsAt ?? $subscription->trial_ends_at,
                    'ends_at' => $renewsAt ?? $endsAt ?? $subscription->ends_at,
                    'metadata' => $meta,
                ]);
                break;

            case 'subscription_updated':
            default:
                // Sync status without clobbering a paid period that is
                // still running.
                $updates = ['metadata' => $meta];

                if ($internalStatus === 'past_due') {
                    $updates['status'] = 'past_due';
                    $updates['auto_renew'] = true;
                } elseif ($internalStatus === 'active') {
                    $updates['status'] = 'active';
                    $updates['auto_renew'] = true;
                    $updates['ends_at'] = $renewsAt ?? $subscription->ends_at;
                } elseif ($internalStatus === 'cancelled') {
                    $updates['auto_renew'] = false;
                    $updates['cancelled_at'] = $subscription->cancelled_at ?? now();
                    $updates['status'] = $endsAt !== null && $endsAt->isPast() ? 'expired' : $subscription->status;
                    $updates['ends_at'] = $endsAt ?? $subscription->ends_at;
                } elseif ($internalStatus === 'expired') {
                    $updates['status'] = 'expired';
                    $updates['auto_renew'] = false;
                } elseif ($internalStatus === 'trialing') {
                    $updates['status'] = 'trialing';
                    $updates['trial_ends_at'] = $trialEndsAt ?? $subscription->trial_ends_at;
                }

                $subscription->update($updates);
                break;
        }
    }

    /**
     * order_refunded — refunds issued inside the Lemon Squeezy dashboard.
     * RefundSyncService performs the guarded completed→refunded transition
     * itself (idempotent side effects: ledger, invoice cancellation,
     * subscription cancellation) — never transition before calling it.
     */
    private function orderRefunded(array $data): void
    {
        $orderId = (string) ($data['id'] ?? '');

        if ($orderId === '') {
            return;
        }

        $payment = Payment::withoutTenant()
            ->where('gateway', 'lemonsqueezy')
            ->where('transaction_id', $orderId)
            ->lockForUpdate()
            ->first();

        if (! $payment) {
            return;
        }

        app(RefundSyncService::class)->sync($payment, [
            'lemon_order_id' => $orderId,
            'reason' => (string) ($data['attributes']['refund_reason'] ?? 'order_refunded'),
            'synced_at' => now()->toIso8601String(),
        ]);
    }

    private function dateOrNull(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
