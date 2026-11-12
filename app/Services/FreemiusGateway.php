<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Freemius gateway — the ONLY billing gateway for teacher subscriptions.
 *
 * Implements the official Freemius SaaS integration surface:
 *   - Hosted Checkout links (product/plan, prefilled + read-only email)
 *   - License retrieval + latest subscription     (entitlement source)
 *   - Upgrade / plan-change / payment-method link  (via checkout link API)
 *   - Cancel subscription at period end
 *   - Refunds
 *   - Hosted Customer Portal magic-login link
 *   - Webhook signature verification (HMAC-SHA256, constant time)
 *
 * Secrets (API token, webhook secret key) come from the environment ONLY —
 * never stored in the database, never logged, never sent to the frontend.
 * Non-secret configuration (enabled toggle, api/checkout base urls) may be
 * overridden from the admin panel via platform_settings.
 *
 * Scope: PLATFORM subscriptions only. Student purchases and bookings are a
 * separate concern and must never be routed through this service.
 *
 * Docs: https://freemius.com/help/documentation/saas/saas-integration/
 */
class FreemiusGateway
{
    private const REQUEST_TIMEOUT = 15;

    private const CONNECT_TIMEOUT = 5;

    /**
     * License events are the authoritative entitlement events. Per Freemius
     * guidance, `subscription.*` events must NOT be used to sync entitlements.
     */
    public const ENTITLEMENT_EVENTS = [
        'license.created',
        'license.extended',
        'license.shortened',
        'license.updated',
        'license.cancelled',
        'license.expired',
        'license.plan.changed',
    ];

    public const DELETE_EVENT = 'license.deleted';

    public static function enabled(): bool
    {
        return PlatformSetting::bool('freemius_enabled', (bool) config('services.freemius.enabled', false));
    }

    /**
     * @return array{enabled: bool, product_id: string, api_token: string, secret_key: string, public_key: string, api_url: string, checkout_url: string, sandbox: bool}
     */
    public static function config(): array
    {
        return [
            'enabled' => self::enabled(),
            // Secrets — environment only. Never stored in the database.
            'api_token' => (string) config('services.freemius.api_token', ''),
            'secret_key' => (string) config('services.freemius.secret_key', ''),
            'public_key' => (string) config('services.freemius.public_key', ''),
            // Non-secret overrides — admin-editable via platform_settings.
            'product_id' => (string) PlatformSetting::get('freemius_product_id', config('services.freemius.product_id', '')),
            'api_url' => rtrim((string) PlatformSetting::get('freemius_api_url', config('services.freemius.api_url', 'https://api.freemius.com')), '/'),
            'checkout_url' => rtrim((string) PlatformSetting::get('freemius_checkout_url', config('services.freemius.checkout_url', 'https://checkout.freemius.com')), '/'),
            'sandbox' => PlatformSetting::bool('freemius_sandbox', (bool) config('services.freemius.sandbox', false)),
        ];
    }

    public static function configured(): bool
    {
        $c = self::config();

        return $c['api_token'] !== '' && $c['secret_key'] !== '' && $c['product_id'] !== '';
    }

    public static function productId(): string
    {
        return self::config()['product_id'];
    }

    public static function publicKey(): string
    {
        return self::config()['public_key'];
    }

    private static function client(): PendingRequest
    {
        return Http::baseUrl(self::config()['api_url'])
            ->accept('application/json')
            ->asJson()
            ->withToken(self::config()['api_token'], 'Bearer')
            ->timeout(self::REQUEST_TIMEOUT)
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->retry(2, 500, throw: false);
    }

    /**
     * Build a Hosted Checkout link (direct checkout link).
     *
     * The email is prefilled and marked read-only so the purchase is made
     * with the same customer email as in our system.
     */
    public static function hostedCheckoutLink(string $planId, string $email, array $extra = []): string
    {
        $productId = self::productId();
        $url = self::config()['checkout_url']."/product/{$productId}/plan/{$planId}/";

        $query = array_merge([
            'user_email' => $email,
            'readonly_user' => 'true',
        ], array_filter($extra, fn ($v) => $v !== null && $v !== ''));

        return $url.'?'.http_build_query($query);
    }

    /**
     * Retrieve a license (the entitlement record) from the Freemius API.
     *
     * @return array<string, mixed>|null
     */
    public static function getLicense(string $licenseId): ?array
    {
        $productId = self::productId();

        try {
            $res = self::client()->get("/v1/products/{$productId}/licenses/{$licenseId}.json");

            return $res->successful() ? ($res->json() ?? null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Retrieve the latest subscription attached to a license.
     *
     * @return array<string, mixed>|null
     */
    public static function getLatestSubscription(string $licenseId): ?array
    {
        $productId = self::productId();

        try {
            $res = self::client()->get("/v1/products/{$productId}/licenses/{$licenseId}/subscription.json");

            return $res->successful() ? ($res->json() ?? null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Generate a Customer Portal magic-login link for a customer email.
     *
     * @return string|null The portal URL, or null on failure.
     */
    public static function customerPortalLink(string $email): ?string
    {
        $productId = self::productId();

        try {
            $res = self::client()
                ->withHeaders(['Accept' => 'application/json'])
                ->post("/v1/products/{$productId}/portal/login.json", ['email' => $email]);
        } catch (\Throwable) {
            return null;
        }

        if (! $res->successful()) {
            return null;
        }

        $json = $res->json();

        return $json['link'] ?? null;
    }

    /**
     * Generate an upgrade / plan-change / payment-method checkout link.
     *
     * @param  array{plan_id?: string, billing_cycle?: string, quota?: int, currency?: string, is_payment_method_update?: bool}  $params
     * @return array{url: string, settings: array, expires: string}|null
     */
    public static function upgradeCheckoutLink(string $licenseId, array $params): ?array
    {
        $productId = self::productId();

        try {
            $res = self::client()->post("/v1/products/{$productId}/licenses/{$licenseId}/checkout/link.json", $params);

            return $res->successful() ? ($res->json() ?? null) : null;
        } catch (\Throwable) {
            // Network/gateway failure — caller falls back to a fresh checkout.
            return null;
        }
    }

    /**
     * Cancel the active subscription of a license (at period end by default).
     *
     * @return bool true when Freemius accepted the cancellation.
     */
    public static function cancelSubscription(string $licenseId): bool
    {
        $productId = self::productId();

        try {
            $res = self::client()->delete("/v1/products/{$productId}/licenses/{$licenseId}/subscription.json");

            return $res->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Initiate a refund against a payment.
     *
     * @return array<string, mixed>|null
     */
    public static function refund(string $paymentId, string $reason = ''): ?array
    {
        $productId = self::productId();

        try {
            $res = self::client()->post("/v1/products/{$productId}/payments/{$paymentId}/refund.json", [
                'reason' => $reason,
            ]);

            return $res->successful() ? ($res->json() ?? null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Verify a webhook signature (HMAC-SHA256 over the RAW body with the
     * product secret key). Constant-time comparison; header `x-signature`.
     */
    public static function verifySignature(string $rawBody, string $signature): bool
    {
        $secret = self::config()['secret_key'];

        if ($secret === '' || $signature === '') {
            return false;
        }

        $computed = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($computed, $signature);
    }
}
