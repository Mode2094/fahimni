<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentCompletionService;
use App\Services\PayPalGateway;
use App\Services\PayTabsGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Booking / course payments — student ↔ teacher settlement ONLY.
 *
 * IMPORTANT SCOPE BOUNDARY
 * -----------------------------------------------------------------------
 * This controller handles paid *bookings* (and any future student-side
 * purchase) between a student and a teacher. It is deliberately NOT used
 * for platform (teacher) subscriptions: those are collected exclusively by
 * Freemius via FreemiusCheckoutController + FreemiusWebhookController.
 *
 * Booking money flows directly between student and teacher through the
 * teacher's own PayTabs / PayPal configuration and must NEVER be routed
 * through Freemius. The two billing worlds share the `payments` table but
 * are separated by `booking_id` (booking) vs `tenant_subscription_id`
 * (subscription) — subscription rows are no longer created here.
 *
 * Activation is idempotent and derived server-side (amount/currency and
 * ownership are always re-verified against the gateway).
 */
class PaymentInitiateController extends Controller
{
    /**
     * PayTabs booking callback.
     */
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
                $payment->update(['status' => 'failed']);

                return response()->json(['message' => 'Amount mismatch.'], 422);
            }

            if ($gatewayCurrency !== '' && strcasecmp($gatewayCurrency, $payment->currency) !== 0) {
                $payment->update(['status' => 'failed']);

                return response()->json(['message' => 'Currency mismatch.'], 422);
            }

            // Idempotent completion: concurrent gateway retries can never
            // double-apply a payment (duplicate ledger rows).
            $affected = Payment::withoutTenant()
                ->where('id', $payment->id)
                ->where('status', '!=', 'completed')
                ->update(['status' => 'completed', 'paid_at' => now()]);

            if ($affected === 1) {
                $this->markPaid($payment);
            }
        } elseif (in_array($status, ['H', 'P'], true)) {
            $payment->update(['status' => 'pending']);
        } else {
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
                $payment->update(['status' => 'failed']);

                return [false, 'amount_mismatch'];
            }

            if ($capturedCurrency !== '' && strcasecmp($capturedCurrency, $payment->currency) !== 0) {
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
            }

            return [true, 'completed'];
        } catch (\Throwable $e) {
            // Transient gateway error: keep the payment pending (the money
            // may or may not have been captured) — never cancel anything on
            // a network blip.
            $payment->update([
                'status' => 'pending',
                'gateway_data' => array_merge($payment->gateway_data ?? [], ['capture_error' => $e->getMessage()]),
            ]);

            return [false, 'gateway_error'];
        }
    }

    /**
     * Redirect fallback: PayPal account checkout.
     */
    public function paypalCapture(Request $request): RedirectResponse
    {
        $orderId = $request->input('token');
        $paymentId = $request->input('payment_id');

        $payment = Payment::withoutTenant()->where('id', $paymentId)->where('gateway', 'paypal')->first();
        $frontend = rtrim(config('app.frontend_url') ?: config('app.url'), '/');
        $successUrl = $frontend.'/book/success?gateway=paypal&status=paid';
        $failUrl = $frontend.'/book/success?gateway=paypal&status=failed';

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
            return response()->json(['message' => 'Capture failed.', 'reason' => $reason], 422);
        }

        return response()->json(['message' => 'OK']);
    }

    /**
     * Admin-only connection test for the BOOKING gateways (PayTabs / PayPal).
     * Platform subscriptions are tested through Freemius and are never
     * reported here.
     */
    public function testConnection(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->is_super_admin) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        $gateway = (string) $request->input('gateway');

        $result = match ($gateway) {
            'paytabs' => [
                'ok' => PayTabsGateway::configured(),
                'message' => PayTabsGateway::configured()
                    ? 'PayTabs credentials present.'
                    : 'PayTabs is not fully configured.',
            ],
            'paypal' => [
                'ok' => PayPalGateway::configured(),
                'message' => PayPalGateway::configured()
                    ? 'PayPal credentials present.'
                    : 'PayPal is not fully configured.',
            ],
            default => [
                'ok' => false,
                'message' => 'Unknown gateway. Platform subscriptions use Freemius.',
            ],
        };

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    /**
     * Idempotent application of a completed booking payment. Only ever called
     * after the payment was transitioned to 'completed' by a guarded
     * conditional update, so concurrent callbacks cannot double-apply.
     */
    private function markPaid(Payment $payment): void
    {
        app(PaymentCompletionService::class)->complete($payment);
    }
}