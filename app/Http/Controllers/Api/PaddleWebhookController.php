<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\AutoRenewalService;
use App\Services\PaddleGateway;
use App\Services\PaymentCompletionService;
use App\Services\RefundSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Uid\UuidV7;

/**
 * Paddle webhook endpoint.
 *
 * - Public route (Paddle sends no Sanctum token) — the ONLY protection is
 *   the Paddle-Signature verification over the raw request body.
 * - The backend is the single source of truth: the frontend "checkout
 *   completed" callback is never trusted to activate a subscription.
 * - Idempotency: event ids are recorded per payment / subscription and
 *   every state change uses guarded conditional updates, so replayed or
 *   out-of-order webhooks can never double-apply (ledger, invoice,
 *   activation, renewal).
 */
class PaddleWebhookController extends Controller
{
    private const TOLERANCE_SECONDS = 300;

    public function handle(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $signature = (string) $request->header('Paddle-Signature', '');

        if (! PaddleGateway::verifyWebhookSignature($signature, $rawBody)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = json_decode($rawBody, true) ?? [];

        if (! is_array($event) || empty($event['event_id']) || empty($event['event_type'])) {
            return response()->json(['message' => 'Malformed event.'], 422);
        }

        $eventId = (string) $event['event_id'];
        $type = (string) $event['event_type'];
        $data = $event['data'] ?? [];

        // Known event we do not act on — acknowledge so Paddle stops retrying.
        if (! in_array($type, [
            'transaction.completed',
            'subscription.created',
            'subscription.activated',
            'subscription.updated',
            'subscription.canceled',
            'subscription.past_due',
            'subscription.paused',
            'subscription.resumed',
            'adjustment.created',
        ], true)) {
            return response()->json(['message' => 'Ignored event.']);
        }

        try {
            switch ($type) {
                case 'transaction.completed':
                    $this->transactionCompleted($eventId, $data);
                    break;

                case 'subscription.created':
                case 'subscription.activated':
                case 'subscription.updated':
                case 'subscription.paused':
                case 'subscription.resumed':
                case 'subscription.past_due':
                case 'subscription.canceled':
                    $this->subscriptionEvent($eventId, $type, $data);
                    break;

                case 'adjustment.created':
                    $this->adjustmentEvent($eventId, $data);
                    break;
            }
        } catch (\Throwable $e) {
            // Never leak gateway details. Webhook stays un-acked so Paddle
            // retries — safer than silently dropping a payment signal.
            Log::warning('paddle.webhook.processing_error', [
                'event_type' => $type,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Processing error.'], 500);
        }

        return response()->json(['message' => 'OK']);
    }

    /**
     * transaction.completed — the definitive "money is in" signal.
     *
     * Initial checkout: located via custom_data.payment_id (set at initiate,
     * never trusted from the frontend). Recurring renewal: located via the
     * tenant_subscription bound to paddle_subscription_id.
     */
    private function transactionCompleted(string $eventId, array $data): void
    {
        $txnId = (string) ($data['id'] ?? '');
        $custom = $data['custom_data'] ?? [];
        $subtotalMinor = (int) ($data['totals']['subtotal'] ?? 0);
        $currency = strtoupper((string) ($data['totals']['currency_code'] ?? ''));
        $subscriptionId = isset($data['subscription_id']) ? (string) $data['subscription_id'] : null;

        if ($txnId === '') {
            return;
        }

        // ── Initial purchase (custom_data carries our internal payment id) ──
        if (! empty($custom['payment_id']) && is_string($custom['payment_id'])) {
            $this->completeInitialPayment($eventId, $custom, $txnId, $subtotalMinor, $currency, $subscriptionId);

            return;
        }

        // ── Renewal (no custom_data; origin = subscription_recurring) ─────
        // Never guess: if we cannot map the Paddle subscription to an LMS
        // tenant_subscription, acknowledge the event and do nothing.
        if ($subscriptionId !== null) {
            $this->completeRenewal($eventId, $txnId, $subscriptionId, $subtotalMinor, $currency);
        }
    }

    private function completeInitialPayment(string $eventId, array $custom, string $txnId, int $subtotalMinor, string $currency, ?string $subscriptionId): void
    {
        DB::transaction(function () use ($eventId, $custom, $txnId, $subtotalMinor, $currency, $subscriptionId) {
            $paymentId = (string) $custom['payment_id'];
            $expectedTenant = isset($custom['tenant_id']) ? (string) $custom['tenant_id'] : null;

            $payment = Payment::withoutTenant()
                ->where('id', $paymentId)
                ->where('gateway', 'paddle')
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                return; // Unknown internal payment — nothing to apply.
            }

            // Tenant isolation: never credit a different tenant's payment.
            if ($expectedTenant !== null && (string) $payment->tenant_id !== $expectedTenant) {
                return;
            }

            if (! $this->markEventSeen($payment, $eventId)) {
                return; // Duplicate event.
            }

            // Amount/currency are the authoritative server-side values
            // (catalog price ± inline coupon discount) captured at initiate.
            $expectedMinor = (int) ($payment->gateway_data['expected_subtotal_minor'] ?? 0);

            if ($subtotalMinor <= 0 || $subtotalMinor !== $expectedMinor) {
                $payment->update(['status' => 'failed']);

                return;
            }

            if ($currency !== '' && strtoupper($currency) !== strtoupper((string) $payment->currency)) {
                $payment->update(['status' => 'failed']);

                return;
            }

            $data = $payment->gateway_data ?? [];
            $data['paddle_transaction_id'] = $txnId;
            if ($subscriptionId !== null) {
                $data['paddle_subscription_id'] = $subscriptionId;
            }

            // Guarded completion — the real race protection.
            $affected = Payment::withoutTenant()
                ->where('id', $payment->id)
                ->where('status', '!=', 'completed')
                ->update([
                    'transaction_id' => $txnId,
                    'status' => 'completed',
                    'paid_at' => now(),
                    'gateway_data' => $data,
                ]);

            if ($affected === 1) {
                $this->linkPaddleSubscription($payment->subscription, $subscriptionId, $eventId);
                app(PaymentCompletionService::class)->complete($payment->fresh());
                $this->captureSavedMethod($payment, $data);
            }
        });
    }

    private function completeRenewal(string $eventId, string $txnId, string $subscriptionId, int $subtotalMinor, string $currency): void
    {
        DB::transaction(function () use ($eventId, $txnId, $subscriptionId, $subtotalMinor, $currency) {
            $subscription = TenantSubscription::withoutTenant()
                ->whereJsonContains('metadata->paddle_subscription_id', $subscriptionId)
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                return; // No local binding — do not fabricate a renewal.
            }

            // Idempotent renewal: the unique (gateway, transaction_id) index
            // plus the existence check make a replayed renewal a no-op.
            $exists = Payment::withoutTenant()
                ->where('gateway', 'paddle')
                ->where('transaction_id', $txnId)
                ->exists();

            if ($exists) {
                return;
            }

            $amountMajor = $subtotalMinor / 100;

            $payment = Payment::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $subscription->tenant_id,
                'tenant_subscription_id' => $subscription->id,
                'transaction_id' => $txnId,
                'gateway' => 'paddle',
                'amount' => $amountMajor,
                'currency' => $currency !== '' ? $currency : 'USD',
                'status' => 'pending',
                'payment_method' => 'paddle',
                'gateway_data' => [
                    'paddle_transaction_id' => $txnId,
                    'paddle_subscription_id' => $subscriptionId,
                    'renewal' => true,
                    'expected_subtotal_minor' => (string) $subtotalMinor,
                    'paddle_events' => [$eventId],
                ],
            ]);

            // Guarded completion then extend the subscription period.
            $affected = Payment::withoutTenant()
                ->where('id', $payment->id)
                ->where('status', '!=', 'completed')
                ->update(['status' => 'completed', 'paid_at' => now()]);

            if ($affected === 1) {
                $this->linkPaddleSubscription($subscription, $subscriptionId, $eventId);
                app(PaymentCompletionService::class)->complete($payment->fresh());
            }
        });
    }

    /**
     * Bind the Paddle subscription id to the tenant_subscription and record
     * lifecycle metadata, so renewal / cancellation events can be routed.
     */
    /**
     * Persist the customer's saved payment method (customer + address +
     * price ids) after a successful checkout so auto-renewal can re-charge
     * it later. Best effort — never fails the completed payment.
     */
    private function captureSavedMethod(Payment $payment, array $data): void
    {
        try {
            if ($payment->tenant_subscription_id === null) {
                return;
            }

            $method = PaddleGateway::extractSavedMethod($data);

            if ($method === null) {
                return;
            }

            $tenant = Tenant::find($payment->tenant_id);

            if ($tenant) {
                app(AutoRenewalService::class)->storeSavedMethod($tenant, 'paddle', $method);
            }
        } catch (\Throwable) {
        }
    }

    private function linkPaddleSubscription(?TenantSubscription $subscription, ?string $subscriptionId, string $eventId): void    {
        if (! $subscription || $subscriptionId === null) {
            return;
        }

        $meta = $subscription->metadata ?? [];
        $meta['paddle_subscription_id'] = $subscriptionId;
        $meta['paddle_status'] = 'active';
        $events = $meta['paddle_events'] ?? [];
        if (! in_array($eventId, $events, true)) {
            $events[] = $eventId;
        }
        $meta['paddle_events'] = $events;

        $subscription->update(['metadata' => $meta]);
    }

    /**
     * Subscription lifecycle events. Paddle copies custom_data from the
     * initial transaction onto the subscription, so created/updated events
     * carry our payment_id — used here to bind the paddle_subscription_id.
     */
    private function subscriptionEvent(string $eventId, string $type, array $data): void
    {
        $subId = (string) ($data['id'] ?? '');
        $custom = $data['custom_data'] ?? [];

        if ($subId === '') {
            return;
        }

        DB::transaction(function () use ($eventId, $type, $subId, $custom) {
            $subscription = TenantSubscription::withoutTenant()
                ->whereJsonContains('metadata->paddle_subscription_id', $subId)
                ->lockForUpdate()
                ->first();

            // First link: custom_data copied from the transaction carries our
            // payment_id — resolve the pending payment's subscription.
            if (! $subscription && ! empty($custom['payment_id'])) {
                $payment = Payment::withoutTenant()
                    ->where('id', (string) $custom['payment_id'])
                    ->where('gateway', 'paddle')
                    ->first();

                $subscription = $payment?->subscription;
            }

            if (! $subscription) {
                return;
            }

            $meta = $subscription->metadata ?? [];
            $events = $meta['paddle_events'] ?? [];
            if (in_array($eventId, $events, true)) {
                return;
            }
            $events[] = $eventId;

            $meta['paddle_events'] = $events;
            $meta['paddle_subscription_id'] = $subId;
            $meta['paddle_status'] = (string) ($data['status'] ?? $type);

            switch ($type) {
                case 'subscription.canceled':
                    // Paddle cancels at the end of the billing period. Keep
                    // service until ends_at; stop future renewals locally.
                    $meta['paddle_canceled_at'] = $data['canceled_at'] ?? now()->toIso8601String();
                    $meta['auto_renew'] = false;
                    $subscription->update([
                        'auto_renew' => false,
                        'cancelled_at' => $subscription->cancelled_at ?? now(),
                        'metadata' => $meta,
                    ]);
                    break;

                case 'subscription.past_due':
                    $subscription->update(['status' => 'past_due', 'auto_renew' => true, 'metadata' => $meta]);
                    break;

                case 'subscription.paused':
                    $subscription->update(['auto_renew' => false, 'metadata' => $meta]);
                    break;

                case 'subscription.resumed':
                    $subscription->update(['auto_renew' => true, 'metadata' => $meta]);
                    break;

                default:
                    // created / activated / updated — keep active, record mapping.
                    if (in_array($subscription->status, ['active', 'trialing'], true)) {
                        $subscription->update(['auto_renew' => true, 'metadata' => $meta]);
                    } else {
                        $subscription->update(['metadata' => $meta]);
                    }
                    break;
            }
        });
    }

    /**
     * Refunds arrive as adjustments (action=refund). Only approved refunds
     * are applied, and RefundSyncService is idempotent (completed→refunded
     * guarded update + guarded ledger/invoice side effects).
     */
    private function adjustmentEvent(string $eventId, array $data): void
    {
        $action = (string) ($data['action'] ?? '');
        $status = (string) ($data['status'] ?? '');
        $txnId = (string) ($data['transaction_id'] ?? '');

        if ($action !== 'refund' || $status !== 'approved' || $txnId === '') {
            return; // pending_approval / rejected / credit / chargeback — ignored.
        }

        DB::transaction(function () use ($eventId, $txnId) {
            $payment = Payment::withoutTenant()
                ->where('gateway', 'paddle')
                ->where(function ($q) use ($txnId) {
                    $q->where('transaction_id', $txnId)
                        ->orWhereJsonContains('gateway_data->paddle_transaction_id', $txnId);
                })
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                return;
            }

            if (! $this->markEventSeen($payment, $eventId)) {
                return; // Duplicate refund event.
            }

            app(RefundSyncService::class)->sync($payment, [
                'paddle_adjustment_id' => (string) ($data['id'] ?? ''),
                'reason' => (string) ($data['reason'] ?? ''),
                'synced_at' => now()->toIso8601String(),
            ]);
        });
    }

    /**
     * Record a processed event id against a payment. Returns false when the
     * event was already seen (idempotent). Runs under lockForUpdate so the
     * read-then-append is atomic within the caller's transaction.
     */
    private function markEventSeen(Payment $payment, string $eventId): bool
    {
        $data = $payment->gateway_data ?? [];
        $events = $data['paddle_events'] ?? [];

        if (in_array($eventId, $events, true)) {
            return false;
        }

        $events[] = $eventId;
        $data['paddle_events'] = $events;

        $payment->update(['gateway_data' => $data]);

        return true;
    }
}
