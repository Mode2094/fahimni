<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Http;

/**
 * PayPal gateway — used ONLY for paid teacher bookings / student purchases
 * (one-off charges). It is never used for platform subscriptions: those are
 * collected exclusively by Freemius.
 */
class PayPalGateway
{
    private const LIVE_API = 'https://api-m.paypal.com';

    private const TEST_API = 'https://api-m.sandbox.paypal.com';

    public static function enabled(): bool
    {
        return (bool) PlatformSetting::get('paypal_enabled', false);
    }

    public static function config(): array
    {
        return [
            'client_id' => (string) PlatformSetting::get('paypal_client_id', ''),
            'secret' => (string) PlatformSetting::getSecret('paypal_secret', ''),
            'test_mode' => (bool) PlatformSetting::get('paypal_test_mode', true),
        ];
    }

    public static function configured(): bool
    {
        $c = self::config();

        return $c['client_id'] !== '' && $c['secret'] !== '';
    }

    private static function baseUrl(): string
    {
        return self::config()['test_mode'] ? self::TEST_API : self::LIVE_API;
    }

    /**
     * Public (non-secret) identifier needed by the frontend to load the
     * PayPal JS SDK for CardFields. The secret never leaves the backend.
     */
    public static function clientId(): string
    {
        return (string) PlatformSetting::get('paypal_client_id', '');
    }

    public static function webhookId(): string
    {
        return (string) PlatformSetting::get('paypal_webhook_id', '');
    }

    /**
     * Server-side eligibility for card payments (v6 SDK find-eligible-methods).
     * The browser call can be rejected for sandbox apps, so the backend is
     * the single source of truth. Returns ['advanced' => bool, 'basic' => bool].
     *
     * @return array{advanced: bool, basic: bool}
     */
    public static function cardMethods(): array
    {
        $token = self::accessToken();

        $response = Http::acceptJson()
            ->withToken($token)
            ->post(self::baseUrl().'/v2/payments/find-eligible-methods', [
                'currencyCode' => 'USD',
            ]);

        $body = $response->json() ?? [];
        $methods = $body['eligible_methods'] ?? [];

        return [
            'advanced' => isset($methods['advanced_cards']) && (bool) ($methods['advanced_cards']['can_be_vaulted'] ?? true),
            'basic' => isset($methods['basic_cards']) && (bool) ($methods['basic_cards']['guest_enabled'] ?? false),
        ];
    }

    public static function accessToken(): string
    {
        $cfg = self::config();

        $response = Http::asForm()
            ->withBasicAuth($cfg['client_id'], $cfg['secret'])
            ->post(self::baseUrl().'/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        $body = $response->json() ?? [];

        if (! $response->successful() || empty($body['access_token'])) {
            throw new \RuntimeException('PayPal auth failed: '.($body['error_description'] ?? 'Unknown error'));
        }

        return $body['access_token'];
    }

    /**
     * @return array{id: string, approval_url: string}
     */
    /**
     * Currencies accepted by PayPal Checkout (Orders v2).
     *
     * @var string[]
     */
    private const SUPPORTED_CURRENCIES = [
        'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS',
        'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'RUB',
        'SGD', 'SEK', 'CHF', 'THB', 'USD',
    ];

    /**
     * PayPal does not support every platform currency (e.g. JOD). When the
     * currency is unsupported the amount is converted to USD via the
     * platform's currency_rate setting.
     */
    public static function normalizeCurrency(string $currency, float $amount): array
    {
        $currency = strtoupper($currency ?: 'USD');

        if (in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            return [$currency, $amount];
        }

        $rate = (float) PlatformSetting::get('currency_rate', 1) ?: 1;

        return ['USD', round($amount * $rate, 2)];
    }

    /**
     * @param  array{tokenise?: bool}  $options  tokenise=true asks PayPal to
     *                                         vault the card used at capture
     *                                         (advanced cards). The vault id
     *         is returned inside the capture response and extracted by
     *         extractSavedMethod().
     */
    public static function createOrder(array $params, array $options = []): array
    {
        $token = self::accessToken();

        [$currency, $amount] = self::normalizeCurrency(
            $params['currency'] ?? 'USD',
            (float) $params['amount'],
        );

        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $params['cart_id'],
                'description' => $params['description'] ?? 'Tadreesly Subscription',
                'amount' => [
                    'currency_code' => $currency,
                    'value' => number_format($amount, 2, '.', ''),
                ],
            ]],
            'application_context' => [
                'brand_name' => $params['brand_name'] ?? 'Tadreesly',
                'locale' => 'en-US',
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
                'return_url' => $params['return_url'],
                'cancel_url' => $params['cancel_url'],
            ],
        ];

        if (! empty($options['tokenise'])) {
            $payload['payment_source'] = [
                'card' => ['store' => true],
            ];
        }

        $response = Http::acceptJson()
            ->withToken($token)
            ->post(self::baseUrl() . '/v2/checkout/orders', $payload);

        $body = $response->json() ?? [];

        if (! $response->successful() || empty($body['id'])) {
            $detail = collect($body['details'] ?? [])
                ->map(fn ($d) => ($d['issue'] ?? '').': '.($d['description'] ?? ''))
                ->implode(' | ');
            throw new \RuntimeException('PayPal order failed: '.($body['message'] ?? 'Unknown error').($detail ? " ({$detail})" : ''));
        }

        $approvalUrl = null;
        foreach ($body['links'] ?? [] as $link) {
            if ($link['rel'] === 'approve') {
                $approvalUrl = $link['href'];
            }
        }

        if (! $approvalUrl) {
            throw new \RuntimeException('PayPal approval link not found.');
        }

        return [
            'id' => $body['id'],
            'approval_url' => $approvalUrl,
        ];
    }

    /**
     * Verify a webhook transmission using PayPal's documented self
     * verification: the original message is
     *   transmissionId | timeStamp | webhookId | crc32(raw body)
     * and is validated against the signature header using the public key
     * from the certificate in paypal-cert-url (cached on disk).
     *
     * @param  array<string, string>  $headers
     */
    public static function verifyWebhookSignature(array $headers, string $rawBody): bool
    {
        $webhookId = self::webhookId();

        if ($webhookId === '') {
            return false;
        }

        $transmissionId = $headers['paypal-transmission-id'] ?? '';
        $timeStamp = $headers['paypal-transmission-time'] ?? '';
        $signature = $headers['paypal-transmission-sig'] ?? '';
        $certUrl = $headers['paypal-cert-url'] ?? '';

        if ($transmissionId === '' || $timeStamp === '' || $signature === '' || $certUrl === '') {
            return false;
        }

        $crc = crc32($rawBody);
        $message = $transmissionId.'|'.$timeStamp.'|'.$webhookId.'|'.$crc;

        $certPem = self::cachedCertificate($certUrl);

        if ($certPem === null) {
            return false;
        }

        $signatureRaw = base64_decode($signature, true);

        if ($signatureRaw === false) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($certPem);

        if ($publicKey === false) {
            return false;
        }

        $ok = openssl_verify($message, $signatureRaw, $publicKey, OPENSSL_ALGO_SHA256) === 1;

        openssl_free_key($publicKey);

        return $ok;
    }

    /**
     * Download (once) and cache the certificate used to verify webhook
     * signatures. Cached under storage/app/paypal-certs keyed by cert URL.
     */
    /**
     * Exact hosts PayPal serves webhook certificates from. The cert URL
     * arrives in a client-supplied header, so anything outside this list is
     * rejected before any HTTP request is made (SSRF guard).
     */
    private const CERT_HOST_ALLOWLIST = [
        'api.paypal.com',
        'api.sandbox.paypal.com',
        'api-m.paypal.com',
        'api-m.sandbox.paypal.com',
    ];

    private static function cachedCertificate(string $certUrl): ?string
    {
        if (! filter_var($certUrl, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($certUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        // SSRF guard: HTTPS only, exact PayPal host allowlist, fixed cert path.
        if ($scheme !== 'https'
            || ! in_array($host, self::CERT_HOST_ALLOWLIST, true)
            || ! str_starts_with($path, '/v1/notifications/certs/')) {
            return null;
        }

        // Cache key includes the full-URL hash so a different URL can never
        // reuse a poisoned entry that only shared the path.
        $safeKey = 'v2_'.hash('sha256', $certUrl);
        $cacheDir = storage_path('app/paypal-certs');
        $cacheFile = $cacheDir.'/'.$safeKey.'.pem';

        if (is_file($cacheFile) && filesize($cacheFile) > 0) {
            return (string) file_get_contents($cacheFile);
        }

        try {
            $response = Http::timeout(10)
                ->withOptions(['allow_redirects' => false])
                ->get($certUrl);

            if (! $response->successful()) {
                return null;
            }

            $pem = $response->body();

            if (! str_contains($pem, 'BEGIN CERTIFICATE')) {
                return null;
            }

            if (! is_dir($cacheDir)) {
                @mkdir($cacheDir, 0755, true);
            }

            @file_put_contents($cacheFile, $pem);

            return $pem;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function captureOrder(string $orderId): array
    {
        $token = self::accessToken();

        $response = Http::acceptJson()
            ->withToken($token)
            ->post(self::baseUrl()."/v2/checkout/orders/{$orderId}/capture");

        $body = $response->json() ?? [];

        if (! $response->successful() || ($body['status'] ?? '') !== 'COMPLETED') {
            throw new \RuntimeException('PayPal capture failed: '.($body['message'] ?? 'Unknown error'));
        }

        return $body;
    }
}
