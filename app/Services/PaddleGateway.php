<?php

namespace App\Services;

use App\Contracts\AutoChargeGateway;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Http;

/**
 * Paddle Billing gateway — API-only service (Paddle.js never talks to the
 * backend API; the client-side token is safe to expose, the API key and
 * webhook secret never leave this class / the database).
 *
 * Handles only Paddle communication. Subscription activation, invoices,
 * ledger and refund side effects stay in PaymentCompletionService and
 * RefundSyncService (never duplicated here).
 */
class PaddleGateway implements AutoChargeGateway
{
    private const SANDBOX_API = 'https://sandbox-api.paddle.com';

    private const LIVE_API = 'https://api.paddle.com';

    private const REQUEST_TIMEOUT = 15;

    public static function enabled(): bool
    {
        return (bool) PlatformSetting::get('paddle_enabled', false);
    }

    public static function environment(): string
    {
        $env = (string) PlatformSetting::get('paddle_environment', 'sandbox');

        return $env === 'live' ? 'live' : 'sandbox';
    }

    public static function config(): array
    {
        return [
            'environment' => self::environment(),
            'client_token' => (string) PlatformSetting::getSecret('paddle_client_token', ''),
            'api_key' => (string) PlatformSetting::getSecret('paddle_api_key', ''),
            'webhook_secret' => (string) PlatformSetting::getSecret('paddle_webhook_secret', ''),
        ];
    }

    public static function configured(): bool
    {
        $c = self::config();

        return $c['client_token'] !== '' && $c['api_key'] !== '' && $c['webhook_secret'] !== '';
    }

    /**
     * Public, non-secret identifier needed by the frontend to initialize
     * Paddle.js. The API key and webhook secret never leave the backend.
     */
    public static function clientToken(): string
    {
        return (string) PlatformSetting::getSecret('paddle_client_token', '');
    }

    public static function baseUrl(): string
    {
        return self::environment() === 'live' ? self::LIVE_API : self::SANDBOX_API;
    }

    private static function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl(self::baseUrl())
            ->acceptJson()
            ->withToken(self::config()['api_key'], 'Bearer')
            ->timeout(self::REQUEST_TIMEOUT)
            ->retry(2, 500, throw: false);
    }

    /**
     * Create an automatically-collected transaction for a catalog price.
     *
     * The LMS backend is the single source of truth for the amount: the
     * catalog price is fixed server-side and, when an LMS coupon applies,
     * an inline non-catalog discount enforces the exact final amount.
     * No separate Paddle discount entity is created.
     *
     * @param  array{price_id: string, custom_data: array, discount: ?array}  $params
     * @return array{id: string, status: string, checkout_url: ?string}
     */
    public static function createTransaction(array $params): array
    {
        $payload = [
            'collection_mode' => 'automatic',
            'currency_code' => 'USD',
            'custom_data' => $params['custom_data'],
            'items' => [[
                'price_id' => $params['price_id'],
                'quantity' => 1,
            ]],
        ];

        if (! empty($params['discount'])) {
            $payload['discount'] = $params['discount'];
        }

        $response = self::client()->post('/transactions', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException(self::apiError('Paddle transaction failed', $response));
        }

        $data = $response->json('data') ?? [];

        if (empty($data['id'])) {
            throw new \RuntimeException('Paddle transaction failed: no transaction id returned.');
        }

        return [
            'id' => (string) $data['id'],
            'status' => (string) ($data['status'] ?? 'draft'),
            'checkout_url' => isset($data['checkout']['url']) ? (string) $data['checkout']['url'] : null,
        ];
    }

    /**
     * Cancel a Paddle subscription. Defaults to cancel at the end of the
     * current billing period (scheduled change), matching Paddle semantics.
     * Returns true on success; throws on API errors. A 404 (already
     * canceled / never existed) is treated as success so local state can
     * converge without being stuck.
     */
    public static function cancelSubscription(string $subscriptionId, string $effectiveFrom = 'next_billing_period'): bool
    {
        $response = self::client()->post("/subscriptions/{$subscriptionId}/cancel", [
            'effective_from' => $effectiveFrom,
        ]);

        if ($response->status() === 404) {
            return true; // already canceled in Paddle — nothing left to do there.
        }

        if (! $response->successful()) {
            throw new \RuntimeException(self::apiError('Paddle cancel failed', $response));
        }

        return true;
    }

    /**
     * Verify a Paddle webhook using the official scheme:
     *   Paddle-Signature: ts=...;h1=...
     *   h1 = HMAC-SHA256(secret, "ts:rawBody")
     *
     * The raw request body must be used exactly as received — never a
     * re-encoded JSON. A timestamp skew beyond the tolerance is rejected.
     */
    public static function verifyWebhookSignature(string $header, string $rawBody): bool
    {
        $secret = self::config()['webhook_secret'];

        if ($secret === '' || $header === '') {
            return false;
        }

        $parts = [];
        foreach (explode(';', $header) as $pair) {
            $kv = explode('=', $pair, 2);
            if (count($kv) === 2) {
                $parts[trim($kv[0])] = trim($kv[1]);
            }
        }

        $ts = $parts['ts'] ?? '';
        $h1 = $parts['h1'] ?? '';

        if ($ts === '' || $h1 === '' || ! ctype_digit($ts)) {
            return false;
        }

        // Reject replay attacks: webhook timestamp must not be too old.
        if (abs(time() - (int) $ts) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $ts.':'.$rawBody, $secret);

        return hash_equals($expected, $h1);
    }

    /**
     * Connection test used by the admin "Test connection" button.
     *
     * @return array{ok: bool, message: string}
     */
    public static function testConnection(): array
    {
        $response = self::client()->get('/prices', ['per_page' => 1]);

        if ($response->successful()) {
            return ['ok' => true, 'message' => 'Connection OK — Paddle server responded.'];
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return ['ok' => false, 'message' => 'Connection failed: invalid Paddle API key or missing permissions.'];
        }

        return ['ok' => false, 'message' => 'Connection failed: '.$response->status().' '.$response->reason()];
    }

    /**
     * Build a safe, secret-free error message from a failed Paddle response.
     */
    private static function apiError(string $prefix, $response): string
    {
        $body = is_object($response) && method_exists($response, 'json') ? $response->json() : [];
        $error = is_array($body) ? ($body['error'] ?? []) : [];

        $detail = is_array($error) ? ($error['detail'] ?? '') : '';
        $message = is_array($error) ? ($error['message'] ?? '') : '';

        if (is_string($detail) && $detail !== '') {
            return $prefix.': '.$detail;
        }

        if (is_string($message) && $message !== '') {
            return $prefix.': '.$message;
        }

        return $prefix.': '.$response->status().' '.$response->reason();
    }

    /**
     * Charge the customer's saved payment method by creating an
     * automatically-collected transaction against their stored catalog
     * price. Paddle charges the default payment method on file; completion
     * arrives asynchronously via transaction.completed (matched back through
     * custom_data.payment_id).
     *
     * $token is a JSON string: {customer_id, address_id?, price_id}.
     *
     * @param  array{cart_id: string, amount: float, currency: string, description: string, custom_data?: array}  $params
     * @return array{success: bool, tran_ref: string|null, response_code: string|null, message: string}
     */
    public static function chargeSaved(string $token, array $params): array
    {
        $saved = json_decode($token, true);

        if (! is_array($saved) || empty($saved['customer_id']) || empty($saved['price_id'])) {
            return [
                'success' => false,
                'tran_ref' => null,
                'response_code' => null,
                'message' => 'Invalid saved Paddle method payload.',
            ];
        }

        $payload = [
            'collection_mode' => 'automatic',
            'currency_code' => 'USD',
            'customer_id' => (string) $saved['customer_id'],
            'items' => [[
                'price_id' => (string) $saved['price_id'],
                'quantity' => 1,
            ]],
            'custom_data' => $params['custom_data'] ?? [],
        ];

        if (! empty($saved['address_id'])) {
            $payload['address_id'] = (string) $saved['address_id'];
        }

        try {
            $response = self::client()->post('/transactions', $payload);
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'tran_ref' => null,
                'response_code' => null,
                'message' => 'Paddle unreachable: '.$e->getMessage(),
            ];
        }

        if (! $response->successful()) {
            return [
                'success' => false,
                'tran_ref' => null,
                'response_code' => null,
                'message' => self::apiError('Paddle renewal failed', $response),
            ];
        }

        $data = $response->json('data') ?? [];

        return [
            // "success" here means initiated — money confirmation is async.
            'success' => ! empty($data['id']),
            'tran_ref' => isset($data['id']) ? (string) $data['id'] : null,
            'response_code' => (string) ($data['status'] ?? ''),
            'message' => 'Renewal transaction created — awaiting webhook confirmation.',
        ];
    }

    /**
     * Extract a saveable method from a transaction.completed webhook event.
     * The token encodes everything needed to re-charge later: customer,
     * address and the exact catalog price used at checkout.
     *
     * @param  array<string, mixed>  $result  webhook event `data` object
     * @return array{token: string, brand: string|null, last4: string|null}|null
     */
    public static function extractSavedMethod(array $result): ?array
    {
        $customerId = $result['customer_id'] ?? null;

        if (! is_string($customerId) || $customerId === '') {
            return null;
        }

        $priceId = $result['items'][0]['price']['id'] ?? null;

        if (! is_string($priceId) || $priceId === '') {
            return null;
        }

        $payment = is_array($result['payment'] ?? null) ? $result['payment'] : [];
        $details = is_array($payment['method_details'] ?? null) ? $payment['method_details'] : [];
        $card = is_array($details['card'] ?? null) ? $details['card'] : [];

        $brand = $card['type'] ?? ($card['brand'] ?? null);
        $last4 = $card['last4'] ?? null;

        return [
            'token' => json_encode(array_filter([
                'customer_id' => $customerId,
                'address_id' => $result['address_id'] ?? null,
                'price_id' => $priceId,
            ])),
            'brand' => is_string($brand) && $brand !== '' ? $brand : null,
            'last4' => ctype_digit((string) $last4) ? substr((string) $last4, -4) : null,
        ];
    }
}
