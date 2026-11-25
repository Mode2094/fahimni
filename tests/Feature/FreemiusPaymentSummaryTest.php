<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Services\FreemiusPaymentSummaryService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FreemiusPaymentSummaryTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'services.freemius.enabled' => true,
            'services.freemius.api_url' => 'https://api.freemius.test',
            'services.freemius.product_id' => '123',
            'services.freemius.api_token' => 'read-only-test-token',
            'services.freemius.secret_key' => 'test-webhook-secret',
        ]);
        PlatformSetting::set('freemius_enabled', true);
        PlatformSetting::set('freemius_product_id', '123');
    }

    public function test_provider_payments_are_grouped_by_currency_and_refunds_are_separate(): void
    {
        Http::fake([
            'https://api.freemius.test/v1/products/123/payments.json*' => Http::response([
                'payments' => [
                    [
                        'id' => 'sale-1',
                        'type' => 'payment',
                        'currency' => 'usd',
                        'gross' => 100,
                        'gateway_fee' => 3,
                        'vat' => 0,
                    ],
                    [
                        'id' => 'refund-1',
                        'type' => 'refund',
                        'currency' => 'usd',
                        'gross' => 20,
                        'gateway_fee' => 0,
                        'vat' => 0,
                    ],
                    [
                        'id' => 'sale-eur',
                        'type' => 'payment',
                        'currency' => 'eur',
                        'gross' => 50,
                        'gateway_fee' => 2,
                        'vat' => 0,
                    ],
                ],
            ]),
        ]);

        $summary = app(FreemiusPaymentSummaryService::class)->summary();

        $this->assertSame('available', $summary['status']);
        $this->assertTrue($summary['complete']);
        $this->assertSame(3, $summary['payment_rows']);
        $this->assertSame(['EUR' => 50.0, 'USD' => 100.0], $summary['gross_collected_by_currency']);
        $this->assertSame(['USD' => 20.0], $summary['refunds_by_currency']);
        $this->assertSame(['EUR' => 2.0, 'USD' => 3.0], $summary['gateway_fees_by_currency']);

        Http::assertSent(function (ClientRequest $request): bool {
            return str_contains($request->url(), '/v1/products/123/payments.json')
                && $request->hasHeader('Authorization', 'Bearer read-only-test-token')
                && ! str_contains($request->url(), 'email');
        });
    }

    public function test_duplicate_provider_payment_ids_are_counted_once_across_pages(): void
    {
        $payment = [
            'id' => 'sale-1',
            'type' => 'payment',
            'currency' => 'usd',
            'gross' => 100,
            'gateway_fee' => 0,
            'vat' => 0,
        ];

        Http::fake(function (ClientRequest $request) use ($payment) {
            $offset = (int) ($request->data()['offset'] ?? 0);

            return Http::response([
                'payments' => $offset === 0
                    ? array_fill(0, 50, $payment)
                    : [$payment],
            ]);
        });

        $summary = app(FreemiusPaymentSummaryService::class)->summary();

        $this->assertSame('available', $summary['status']);
        $this->assertTrue($summary['complete']);
        $this->assertSame(1, $summary['payment_rows']);
        $this->assertSame(['USD' => 100.0], $summary['gross_collected_by_currency']);
    }

    public function test_provider_failure_is_not_reported_as_zero_revenue(): void
    {
        Http::fake([
            'https://api.freemius.test/v1/products/123/payments.json*' => Http::response([], 503),
        ]);

        $summary = app(FreemiusPaymentSummaryService::class)->summary();

        $this->assertSame('unavailable', $summary['status']);
        $this->assertFalse($summary['complete']);
        $this->assertNull($summary['gross_collected_by_currency']);
    }
}
