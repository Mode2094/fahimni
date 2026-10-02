<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Lemon Squeezy gateway — Merchant of Record (MoR).
 *
 * Follows the exact convention of the other gateway services in this
 * project (static methods, PlatformSetting-backed config, secret-free
 * error messages). Lemon Squeezy handles the payment method itself on its
 * hosted checkout; this service only talks to the official API:
 *
 *   - POST /v1/checkouts        create a hosted checkout for a variant
 *   - GET  /v1/subscriptions/:id  read subscription state (incl. portal urls)
 *   - DELETE /v1/subscriptions/:id  cancel at period end
 *   - PATCH /v1/subscriptions/:id   pause / resume billing
 *   - POST /v1/refunds          initiate a refund against an order
 *   - GET  /v1/variants         connection test
 *
 * Secrets (API key + webhook signing secret) come from the environment
 * ONLY (config/services.php → env) and are never stored in the database,
 * never logged, and never sent to the frontend. Non-secret configuration
 * (enabled toggle, store id, api url) is overridable from the admin panel
 * via platform_settings.
 */
class LemonSqueezyGateway
{
    private const REQUEST_TIMEOUT = 15;

    private const CONNECT_TIMEOUT = 5;

    /**
     * Subscription statuses Lemon Squeezy can report.
     */
    public const STATUS_ON_TRIAL = 'on_trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_PAST_DUE = 'past_due';

    /**
     * Webhook events this integration acts on. Anything else is
     * acknowledged (so Lemon Squeezy stops retrying) and ignored.
     */
    public const SUPPORTED_EVENTS = [
        'subscription_created',
        'subscription_updated',
        'subscription_cancelled',
        'subscription_resumed',
        'subscription_expired',
        'subscription_paused',
        'subscription_unpaused',
        'order_created',
        'order_refunded',
    ];

    public static function enabled(): bool
    {
        return PlatformSetting::bool('lemon_enabled', (bool) config('services.lemonsqueezy.enabled', false));
    }

    /**
     * @return array{enabled: bool, api_key: string, store_id: string, signing_secret: string, api_url: string}
     */
    public static function config(): array
    {
        return [
            'enabled' => self::enabled(),
            // Secrets — environment only. Never stored in the database.
            'api_key' => (string) config('services.lemonsqueezy.api_key', ''),
            'signing_secret' => (string) config('services.lemonsqueezy.signing_secret', ''),
            // Non-secret overrides — admin-editable via platform_settings.
            'store_id' => (string) PlatformSetting::get('lemon_store_id', config('services.lemonsqueezy.store_id', '')),
            'api_url' => rtrim((string) PlatformSetting::get('lemon_api_url', config('services.lemonsqueezy.api_url', 'https://api.lemonsqueezy.com')), '/'),
        ];
    }

    public static function configured(): bool
    {
        $c = self::config();

        return $c['api_key'] !== '' && $c['signing_secret'] !== '' && $c['store_id'] !== '';
    }

    public static function baseUrl(): string
    {
        return self::config()['api_url'].'/v1';
    }

    private static function client(): PendingRequest
    {
        return Http::baseUrl(self::baseUrl())
            ->accept('application/vnd.api+json')
            ->asJson()
            ->withToken(self::config()['api_key'], 'Bearer')
            ->timeout(self::REQUEST_TIMEOUT)
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->retry(2, 500, throw: false);
    }

    /**
     * Create a hosted checkout for a variant with server-side custom data.
     *
     * The LMS backend is the single source of truth for the amount: when a
     * coupon / proration / service fee applies, `custom_price` (integer
     * cents) pins the exact first-payment amount so the customer can never
     * be charged anything other than what the server computed. Recurring
     * payments afterwards follow the variant's catalog price, which the
     * renewal webhooks apply with no local price input.
     *
     * @param  array{variant_id: int, custom_data: array<string, string>, price_minor: int, redirect_url: ?string}  $params
     * @return array{id: string, url: string, test_mode: bool}
     *
     * @throws \RuntimeException
     */
    public static function createCheckout(array $params): array
    {
        $payload = [
            'data' => [
                'type' => 'checkouts',
                'attributes' => [
                    'checkout_data' => [
                        'custom' => $params['custom_data'],
                        'variant_quantities' => [[
                            'variant_id' => (int) $params['variant_id'],
                            'quantity' => 1,
                        ]],
                    ],
                    'checkout_options' => [
                        'embed' => false,
                    ],
                    'custom_price' => (int) $params['price_minor'],
                    'expires_at' => now()->addHours(24)->toISOString(),
                ],
            ],
        ];

        $redirectUrl = $params['redirect_url'] ?? null;
        if (is_string($redirectUrl) && $redirectUrl !== '') {
            $payload['data']['attributes']['product_options'] = ['redirect_url' => $redirectUrl];
        }

        $response = self::client()->post('/checkouts', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException(self::apiError('Lemon Squeezy checkout failed', $response));
        }

        $data = $response->json('data') ?? [];
        $url = $data['attributes']['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new \RuntimeException('Lemon Squeezy checkout failed: no checkout URL returned.');
        }

        return [
            'id' => (string) ($data['id'] ?? ''),
            'url' => $url,
            'test_mode' => (bool) ($data['attributes']['test_mode'] ?? false),
        ];
    }

    /**
     * Fetch a subscription from Lemon Squeezy (status, renews_at, ends_at,
     * trial_ends_at, customer/variant ids and the customer portal URL).
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public static function getSubscription(string $subscriptionId): array
    {
        $response = self::client()->get('/subscriptions/'.rawurlencode($subscriptionId));

        if ($response->status() === 404) {
            throw new \RuntimeException('Lemon Squeezy subscription not found: '.$subscriptionId);
        }

        if (! $response->successful()) {
            throw new \RuntimeException(self::apiError('Lemon Squeezy subscription fetch failed', $response));
        }

        $attributes = $response->json('data.attributes') ?? [];

        if (! is_array($attributes)) {
            throw new \RuntimeException('Lemon Squeezy subscription fetch failed: malformed response.');
        }

        return $attributes;
    }

    /**
     * Cancel a subscription at the end of the current billing period
     * (Lemon Squeezy semantics). A 404 is treated as success so local state
     * can converge. Returns true on success; throws on API errors.
     */
    public static function cancelSubscription(string $subscriptionId): bool
    {
        $response = self::client()->delete('/subscriptions/'.rawurlencode($subscriptionId));

        if ($response->status() === 404) {
            return true; // already cancelled / never existed.
        }

        if (! $response->successful()) {
            throw new \RuntimeException(self::apiError('Lemon Squeezy cancel failed', $response));
        }

        return true;
    }

    /**
     * Pause billing (subscriptions with recurring billing only).
     */
    public static function pauseSubscription(string $subscriptionId): bool
    {
        return self::updateSubscriptionStatus($subscriptionId, self::STATUS_PAUSED);
    }

    /**
     * Resume billing after a pause.
     */
    public static function resumeSubscription(string $subscriptionId): bool
    {
        return self::updateSubscriptionStatus($subscriptionId, self::STATUS_ACTIVE);
    }

    private static function updateSubscriptionStatus(string $subscriptionId, string $status): bool
    {
        $response = self::client()->patch('/subscriptions/'.rawurlencode($subscriptionId), [
            'data' => [
                'type' => 'subscriptions',
                'id' => $subscriptionId,
                'attributes' => ['status' => $status],
            ],
        ]);

        if ($response->status() === 404) {
            return false;
        }

        if (! $response->successful()) {
            throw new \RuntimeException(self::apiError('Lemon Squeezy subscription update failed', $response));
        }

        return true;
    }

    /**
     * Initiate a refund against an order. Amount is integer cents.
     *
     * @return array{id: string, status: string}
     *
     * @throws \RuntimeException
     */
    public static function createRefund(int $orderId, int $amountMinor, string $reason): array
    {
        $response = self::client()->post('/refunds', [
            'data' => [
                'type' => 'refunds',
                'attributes' => [
                    'order_id' => $orderId,
                    'refund_amount' => $amountMinor,
                    'reason' => substr($reason, 0, 190),
                ],
            ],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(self::apiError('Lemon Squeezy refund failed', $response));
        }

        $data = $response->json('data') ?? [];

        return [
            'id' => (string) ($data['id'] ?? ''),
            'status' => (string) ($data['attributes']['status'] ?? ''),
        ];
    }

    /**
     * Verify a Lemon Squeezy webhook using the official scheme:
     *   X-Signature = hex(HMAC-SHA256(signing_secret, raw body))
     *
     * The raw request body must be used exactly as received — never a
     * re-encoded JSON. Comparison is constant-time. Lemon Squeezy sends no
     * timestamp header, so replay protection relies on the unique
     * (provider, event_id) idempotency ledger in webhook_events.
     */
    public static function verifyWebhookSignature(string $signature, string $rawBody): bool
    {
        $secret = self::config()['signing_secret'];

        if ($secret === '' || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, strtolower(trim($signature)));
    }

    /**
     * Connection test used by the admin "Test connection" button.
     *
     * @return array{ok: bool, message: string}
     */
    public static function testConnection(): array
    {
        $response = self::client()->get('/variants', ['page' => ['size' => 1]]);

        if ($response->successful()) {
            return ['ok' => true, 'message' => 'Connection OK — Lemon Squeezy server responded.'];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return ['ok' => false, 'message' => 'Connection failed: invalid Lemon Squeezy API key or missing permissions.'];
        }

        return ['ok' => false, 'message' => 'Connection failed: '.$response->status().' '.$response->reason()];
    }

    /**
     * Map a Lemon Squeezy subscription status to an internal subscription
     * status. `paused` has no direct internal equivalent (the platform does
     * not model paused billing) — callers keep the current status and only
     * flip auto_renew off.
     */
    public static function mapStatus(?string $lsStatus): ?string
    {
        return match ($lsStatus) {
            self::STATUS_ON_TRIAL => 'trialing',
            self::STATUS_ACTIVE => 'active',
            self::STATUS_CANCELLED => 'cancelled',
            self::STATUS_EXPIRED => 'expired',
            self::STATUS_PAST_DUE => 'past_due',
            default => null, // paused / unknown → no direct mapping
        };
    }

    /**
     * Build a safe, secret-free error message from a failed Lemon Squeezy
     * response. The JSON:API error envelope (data.errors[].detail) is used
     * when present; credentials are never part of it.
     */
    private static function apiError(string $prefix, $response): string
    {
        $body = is_object($response) && method_exists($response, 'json') ? $response->json() : [];
        $errors = is_array($body) ? ($body['errors'] ?? []) : [];

        if (is_array($errors) && isset($errors[0]['detail']) && is_string($errors[0]['detail'])) {
            return $prefix.': '.$errors[0]['detail'];
        }

        if (is_array($errors) && isset($errors[0]['title']) && is_string($errors[0]['title'])) {
            return $prefix.': '.$errors[0]['title'];
        }

        return $prefix.': '.$response->status().' '.$response->reason();
    }
}
