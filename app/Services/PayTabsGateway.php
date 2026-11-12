<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Http;

/**
 * PayTabs gateway — used ONLY for paid teacher bookings / student purchases
 * (one-off charges). It is never used for platform subscriptions: those are
 * collected exclusively by Freemius.
 */
class PayTabsGateway
{
    private const LIVE_URL = 'https://secure.paytabs.sa/payment/request';

    private const TEST_URL = 'https://secure.paytabs.sa/payment/request';

    private const QUERY_URL = 'https://secure.paytabs.sa/payment/query';

    public static function enabled(): bool
    {
        return (bool) PlatformSetting::get('paytabs_enabled', false);
    }

    public static function config(): array
    {
        return [
            'profile_id' => (string) PlatformSetting::get('paytabs_profile_id', ''),
            'server_key' => (string) PlatformSetting::getSecret('paytabs_server_key', ''),
            'test_mode' => (bool) PlatformSetting::get('paytabs_test_mode', true),
        ];
    }

    public static function configured(): bool
    {
        $c = self::config();

        return $c['profile_id'] !== '' && $c['server_key'] !== '';
    }

    /**
     * @param  array{tokenise?: bool}  $options  tokenise=true asks PayTabs for a
     *                                         persistent payment token (saved card) usable
     *                                         for future merchant-initiated renewals.
     *
     * @return array{redirect_url: string, tran_ref: string, token?: string|null}
     */
    public static function createPayment(array $params, array $options = []): array
    {
        $cfg = self::config();

        $payload = [
            'profile_id' => $cfg['profile_id'],
            'tran_type' => 'sale',
            'tran_class' => 'ecom',
            'cart_id' => $params['cart_id'],
            'cart_currency' => $params['currency'] ?? 'JOD',
            'cart_amount' => number_format((float) $params['amount'], 2, '.', ''),
            'cart_description' => $params['description'] ?? 'Tadreesly Subscription',
            'paypage_lang' => 'ar',
            'hide_shipping' => true,
            'callback' => $params['callback_url'],
            'return' => $params['return_url'],
            'customer_details' => [
                'name' => $params['customer']['name'] ?? 'Customer',
                'email' => $params['customer']['email'] ?? 'customer@example.com',
                'phone' => $params['customer']['phone'] ?? '0500000000',
                'street1' => 'N/A',
                'city' => 'Riyadh',
                'state' => 'Riyadh',
                'country' => 'SA',
                'zip' => '00000',
            ],
            'ship_to_details' => [
                'name' => $params['customer']['name'] ?? 'Customer',
                'email' => $params['customer']['email'] ?? 'customer@example.com',
                'phone' => $params['customer']['phone'] ?? '0500000000',
                'address' => 'N/A',
                'city' => 'Riyadh',
                'state' => 'Riyadh',
                'country' => 'SA',
                'zip' => '00000',
            ],
        ];

        // tokenise=2 → permanent token; the callback response then carries
        // the saved-card token we store for auto-renewal charges.
        if (! empty($options['tokenise'])) {
            $payload['tokenise'] = 2;
        }

        $response = Http::acceptJson()
            ->withToken($cfg['server_key'])
            ->post(self::TEST_URL, $payload);

        $body = $response->json() ?? [];

        if (! $response->successful() || empty($body['redirect_url'])) {
            throw new \RuntimeException('PayTabs request failed: '.($body['message'] ?? 'Unknown error'));
        }

        return [
            'redirect_url' => $body['redirect_url'],
            'tran_ref' => $body['tran_ref'] ?? null,
            'response_code' => $body['response_code'] ?? null,
            'token' => $body['token'] ?? null,
        ];
    }

    /**
     * Charge a previously saved card token (merchant-initiated renewal).
     *
     * @return array{success: bool, tran_ref: string|null, response_code: string|null, message: string}
     */
    public static function chargeToken(string $token, array $params): array
    {
        $cfg = self::config();

        $payload = [
            'profile_id' => $cfg['profile_id'],
            'tran_type' => 'sale',
            'tran_class' => 'ecom',
            'tran_ref' => $token,
            'cart_id' => $params['cart_id'],
            'cart_currency' => $params['currency'] ?? 'JOD',
            'cart_amount' => number_format((float) $params['amount'], 2, '.', ''),
            'cart_description' => $params['description'] ?? 'Tadreesly Subscription Renewal',
        ];

        try {
            $response = Http::acceptJson()
                ->withToken($cfg['server_key'])
                ->timeout(30)
                ->post(self::TEST_URL, $payload);
        } catch (\Throwable $e) {
            return ['success' => false, 'tran_ref' => null, 'response_code' => null, 'message' => 'Gateway unreachable'];
        }

        $body = $response->json() ?? [];

        $resultStatus = $body['payment_result']['response_status'] ?? null;
        $responseCode = $body['payment_result']['response_code']
            ?? $body['response_code']
            ?? null;
        $message = $body['payment_result']['response_message']
            ?? $body['message']
            ?? ($resultStatus === 'A' ? 'Approved' : 'Declined');

        return [
            'success' => $resultStatus === 'A',
            'tran_ref' => $body['tran_ref'] ?? null,
            'response_code' => is_array($responseCode) ? null : $responseCode,
            'message' => is_string($message) ? $message : json_encode($message),
        ];
    }

    public static function queryPayment(string $tranRef): array
    {
        $cfg = self::config();

        $response = Http::acceptJson()
            ->withToken($cfg['server_key'])
            ->post(self::QUERY_URL, [
                'profile_id' => $cfg['profile_id'],
                'tran_ref' => $tranRef,
            ]);

        return $response->json() ?? [];
    }
}
