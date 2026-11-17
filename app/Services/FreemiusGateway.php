<?php

namespace App\Services;

use App\Exceptions\FreemiusApiException;
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
     * @return array{enabled: bool, product_id: string, api_token: string, secret_key: string, public_key: string, api_url: string, checkout_url: string, sandbox: bool, developer_id: string, developer_public_key: string, developer_secret_key: string}
     */
    public static function config(): array
    {
        return [
            'enabled' => self::enabled(),
            // Secrets — environment only. Never stored in the database.
            'api_token' => (string) config('services.freemius.api_token', ''),
            'secret_key' => (string) config('services.freemius.secret_key', ''),
            'public_key' => (string) config('services.freemius.public_key', ''),
            // Developer scope — environment only. Needed to publish prices.
            'developer_id' => (string) config('services.freemius.developer_id', ''),
            'developer_public_key' => (string) config('services.freemius.developer_public_key', ''),
            'developer_secret_key' => (string) config('services.freemius.developer_secret_key', ''),
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

    /**
     * Whether developer-scope credentials are present. The product Bearer token
     * can NEVER change a plan price (Freemius allows pricing writes for the
     * developer scope only); publishing therefore requires these values from
     * Dashboard → My Profile → Keys.
     */
    public static function developerConfigured(): bool
    {
        $c = self::config();

        return $c['developer_id'] !== ''
            && $c['developer_public_key'] !== ''
            && $c['developer_secret_key'] !== '';
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
     * Official URL schema:
     *   {checkout}/product/{product_id}/plan/{plan_id}/?<Buy Button options>
     *
     * `$extra` carries documented Buy Button options as query params. To charge
     * the verified pricing we pass `pricing_id` and `billing_cycle`
     * (monthly|annual) plus `currency=usd` — see the caller.
     *
     * The email is prefilled and marked read-only so the purchase is made
     * with the same customer email as in our system.
     *
     * When sandbox is enabled, the official sandbox parameters
     * (`sandbox` token + `s_ctx_ts` context) are appended so the Checkout
     * actually opens in Sandbox mode — `FREEMIUS_SANDBOX=true` alone does NOT
     * switch the checkout; Freemius requires these signed params (see the
     * official "Testing the Freemius Checkout via Sandbox" docs).
     */
    public static function hostedCheckoutLink(string $planId, string $email, array $extra = []): string
    {
        $productId = self::productId();
        $url = self::config()['checkout_url']."/product/{$productId}/plan/{$planId}/";

        $query = array_merge([
            'user_email' => $email,
            'readonly_user' => 'true',
        ], array_filter($extra, fn ($v) => $v !== null && $v !== ''));

        // Sandbox: append the official sandbox token + context. These are
        // non-secret derived values (md5 of ctx+ids+keys), but we never log
        // them; they only appear in the checkout URL returned to the browser.
        if (self::config()['sandbox']) {
            $sandbox = self::getSandboxParams();

            if ($sandbox !== null) {
                $query['sandbox'] = $sandbox['token'];
                $query['s_ctx_ts'] = $sandbox['ctx'];
            }
        }

        return $url.'?'.http_build_query($query);
    }

    /**
     * Generate the official Hosted-Checkout sandbox parameters.
     *
     * Per Freemius docs:
     *   ctx           = time() (or any random unique string)
     *   sandbox_token = md5(ctx . product_id . secret_key . public_key . 'checkout')
     *
     * Returns null when the required values are missing. The resulting token
     * is a derived, non-secret value; it is never logged.
     *
     * @return array{token: string, ctx: string}|null
     */
    public static function getSandboxParams(): ?array
    {
        $c = self::config();

        $productId = $c['product_id'];
        $secretKey = $c['secret_key'];
        $publicKey = $c['public_key'];

        if ($productId === '' || $secretKey === '' || $publicKey === '') {
            return null;
        }

        $ctx = (string) time();

        $token = md5($ctx.$productId.$secretKey.$publicKey.'checkout');

        return ['token' => $token, 'ctx' => $ctx];
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
     * Strict license fetch for webhook/reconciliation sync.
     *
     * Throws FreemiusApiException on a transient failure (network/timeout/5xx)
     * so the caller can RETRY instead of silently treating it as "not found"
     * (which would lose a real payment event). Returns null only on a
     * definitive not-found (404).
     *
     * @return array<string, mixed>|null
     */
    public static function getLicenseOrFail(string $licenseId): ?array
    {
        $productId = self::productId();

        try {
            $res = self::client()->get("/v1/products/{$productId}/licenses/{$licenseId}.json");
        } catch (\Throwable $e) {
            throw new FreemiusApiException('Freemius license fetch failed: '.$e->getMessage(), 0, $e);
        }

        if ($res->status() === 404) {
            return null; // definitive: license does not exist
        }

        if (! $res->successful()) {
            throw new FreemiusApiException('Freemius license fetch returned HTTP '.$res->status());
        }

        return $res->json() ?? null;
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
     * Strict subscription fetch for webhook/reconciliation sync. A missing
     * subscription (404) is a legitimate state (lifetime license) and returns
     * null; a transient failure throws so the caller retries.
     *
     * @return array<string, mixed>|null
     */
    public static function getLatestSubscriptionOrFail(string $licenseId): ?array
    {
        $productId = self::productId();

        try {
            $res = self::client()->get("/v1/products/{$productId}/licenses/{$licenseId}/subscription.json");
        } catch (\Throwable $e) {
            throw new FreemiusApiException('Freemius subscription fetch failed: '.$e->getMessage(), 0, $e);
        }

        if ($res->status() === 404) {
            return null;
        }

        if (! $res->successful()) {
            throw new FreemiusApiException('Freemius subscription fetch returned HTTP '.$res->status());
        }

        return $res->json() ?? null;
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
     * Official body (POST .../licenses/{id}/checkout/link.json):
     *   plan_id, billing_cycle (monthly|annual|lifetime), quota,
     *   is_payment_method_update. There is no pricing_id/currency here — the
     *   caller must have verified the target plan price first.
     *
     * @param  array{plan_id?: string, billing_cycle?: string, quota?: int|string, is_payment_method_update?: bool}  $params
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

    // ─── Plan pricing: read (product scope) + write (developer scope) ───

    /**
     * List every pricing of a plan (PRODUCT-scope read). Returns the raw
     * `pricing` array, or null when none could be read (transport/HTTP error).
     *
     * @return array<int, array<string, mixed>>|null
     */
    public static function getPlanPricing(string $planId): ?array
    {
        $productId = self::productId();

        if ($productId === '' || $planId === '') {
            return null;
        }

        try {
            $res = self::client()->get("/v1/products/{$productId}/plans/{$planId}/pricing.json");
        } catch (\Throwable) {
            return null;
        }

        if (! $res->successful()) {
            return null;
        }

        $json = $res->json();

        if (! is_array($json)) {
            return null;
        }

        $list = $json['pricing'] ?? (array_is_list($json) ? $json : null);

        return is_array($list) ? array_values(array_filter($list, 'is_array')) : null;
    }

    /**
     * Retrieve a single pricing by id (PRODUCT-scope read).
     *
     * @return array<string, mixed>|null
     */
    public static function getPricing(string $planId, string $pricingId): ?array
    {
        $productId = self::productId();

        if ($productId === '' || $planId === '' || $pricingId === '') {
            return null;
        }

        try {
            $res = self::client()->get("/v1/products/{$productId}/plans/{$planId}/pricing/{$pricingId}.json");
        } catch (\Throwable) {
            return null;
        }

        if (! $res->successful()) {
            return null;
        }

        $json = $res->json();

        if (! is_array($json)) {
            return null;
        }

        if (isset($json['pricing']) && is_array($json['pricing'])) {
            return $json['pricing'];
        }

        return $json !== [] ? $json : null;
    }

    /**
     * Update a plan's pricing in Freemius using DEVELOPER-scope authentication.
     *
     * This is the ONLY officially documented way to change a plan price via the
     * API: `PUT /v1/developers/{dev}/plugins/{prod}/plans/{plan}/pricing/{id}.json`.
     * The product Bearer token does NOT authorize this operation.
     *
     * (The developer scope aliases the product as `plugins/`; `products/` is
     * only valid in the product scope and returns `not_implemented` here.)
     *
     * Authentication mirrors the official legacy SDK (freemius-php-sdk): a
     * signed `Authorization: FS {dev_id}:{public_key}:{signature}` header where
     * signature = base64url(hmac_sha256(hex)) over
     * "METHOD\nContent-MD5\nContent-Type\nDate\nresourcePath".
     *
     * Never throws: returns a structured result the caller can act on honestly.
     *
     * @param  array<string, mixed>  $payload  e.g. ['monthly_price' => 59, 'currency' => 'usd']
     * @return array{ok: bool, status: int, data: array<string, mixed>|null, error: ?string}
     */
    public static function updatePricing(string $planId, string $pricingId, array $payload): array
    {
        $c = self::config();

        if (! self::developerConfigured()) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'developer_credentials_missing'];
        }

        $productId = $c['product_id'];

        if ($productId === '' || $planId === '' || $pricingId === '') {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'missing_ids'];
        }

        // NOTE: the developer scope is registered under `plugins/`, NOT
        // `products/`. Using `products/` here makes Freemius answer
        // `400 not_implemented` ("Invalid request path") and the write can
        // never succeed. Verified against the live API: the same signed
        // request returns `404 plan_not_found` (route reached) only with
        // `plugins/`.
        $resourcePath = "/v1/developers/{$c['developer_id']}/plugins/{$productId}/plans/{$planId}/pricing/{$pricingId}.json";
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'payload_encode_failed'];
        }

        $contentType = 'application/json';
        $contentMd5 = md5($json);
        $date = date('r', time() - self::clockDiff());
        $authType = ($c['developer_secret_key'] !== $c['developer_public_key']) ? 'FS' : 'FSP';
        $signature = self::freemiusSignature('PUT', $contentMd5, $contentType, $date, $resourcePath, $c['developer_public_key'], $c['developer_secret_key']);

        try {
            $res = Http::baseUrl($c['api_url'])
                ->accept('application/json')
                ->withHeaders([
                    'Date' => $date,
                    'Content-MD5' => $contentMd5,
                    'Authorization' => "{$authType} {$c['developer_id']}:{$c['developer_public_key']}:{$signature}",
                ])
                ->withBody($json, $contentType)
                ->timeout(self::REQUEST_TIMEOUT)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                // A price write is a money-affecting operation: never auto-retry.
                ->put($resourcePath);
        } catch (\Throwable) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'transport_error'];
        }

        $data = null;

        try {
            $data = $res->json();
        } catch (\Throwable) {
            $data = null;
        }

        return [
            'ok' => $res->successful(),
            'status' => $res->status(),
            'data' => is_array($data) ? ($data['pricing'] ?? $data) : null,
            'error' => $res->successful() ? null : 'http_'.$res->status(),
        ];
    }

    /**
     * Freemius developer-scope request signature (official algorithm).
     *
     * NOTE: the official SDK base64url-encodes the HEX digest, so we mirror that
     * exactly (not the raw bytes) to stay byte-compatible with the API.
     */
    public static function freemiusSignature(
        string $method,
        string $contentMd5,
        string $contentType,
        string $date,
        string $resourcePath,
        string $publicKey,
        string $secretKey,
    ): string {
        $stringToSign = implode("\n", [strtoupper($method), $contentMd5, $contentType, $date, $resourcePath]);
        $hex = hash_hmac('sha256', $stringToSign, $secretKey);

        return rtrim(strtr(base64_encode($hex), '+/', '-_'), '=');
    }

    /**
     * Clock difference (seconds) between this server and the Freemius API,
     * derived from the public `ping.json` endpoint. Falls back to 0 on failure.
     */
    private static function clockDiff(): int
    {
        static $diff = null;

        if ($diff !== null) {
            return $diff;
        }

        try {
            $res = Http::timeout(self::CONNECT_TIMEOUT)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->get(self::config()['api_url'].'/v1/ping.json');

            $timestamp = $res->successful() ? $res->json('timestamp') : null;
            $diff = $timestamp !== null ? time() - (int) strtotime((string) $timestamp) : 0;
        } catch (\Throwable) {
            $diff = 0;
        }

        return $diff;
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

    /**
     * Verify the HOSTED checkout redirect signature (official algorithm):
     *
     *   1. Take the full absolute URL.
     *   2. Remove the "&signature=..." (or "?signature=...") part.
     *   3. HMAC-SHA256(cleanUrl, PRODUCT_SECRET_KEY).
     *   4. Constant-time compare with the provided signature.
     *
     * Returns the parsed redirect parameters when the signature is valid, or
     * null when it is missing/invalid. The caller MUST still require the
     * authenticated session and the server-side purchase facts; a valid
     * signature proves authenticity of the Freemius redirect, not ownership of
     * a specific tenant.
     *
     * @return array<string, string>|null
     */
    public static function verifyCheckoutRedirect(string $fullUrl): ?array
    {
        $secret = self::config()['secret_key'];

        if ($secret === '') {
            return null;
        }

        // Parse query params from the URL.
        $parts = parse_url($fullUrl);

        if ($parts === false || empty($parts['query'])) {
            return null;
        }

        parse_str($parts['query'], $query);

        $signature = isset($query['signature']) ? (string) $query['signature'] : '';

        if ($signature === '') {
            return null;
        }

        // Rebuild the clean URL exactly as Freemius does: absolute URL with the
        // signature parameter removed. Freemius' SDK also normalizes encoded
        // spaces (%20 -> +) before hashing, so we mirror that.
        $cleanUrl = str_replace('%20', '+', self::stripSignatureParam($fullUrl));

        $computed = hash_hmac('sha256', $cleanUrl, $secret);

        if (! hash_equals($computed, $signature)) {
            return null;
        }

        /** @var array<string, string> $params */
        $params = array_filter(
            array_map(fn ($v) => is_array($v) ? (string) reset($v) : (string) $v, $query),
            fn ($v) => $v !== '',
        );

        return $params;
    }

    /**
     * Remove the signature query parameter from a URL, preserving the rest of
     * the query string order (so the HMAC matches Freemius' computation).
     */
    private static function stripSignatureParam(string $url): string
    {
        // Cut everything from the first signature parameter onward (Freemius
        // appends `&signature=` last).
        foreach (['&signature=', '?signature='] as $needle) {
            $pos = strpos($url, $needle);

            if ($pos !== false) {
                return substr($url, 0, $pos);
            }
        }

        return $url;
    }
}
