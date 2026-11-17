<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Services\FreemiusGateway;
use App\Services\FreemiusPlanProvisioner;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Idempotent provisioning of the Freemius plans/pricing that back the local
 * tiers: list → create missing → create one USD pricing → link real IDs →
 * verify. Covers dry-run safety, idempotency, the ambiguous/mismatch guards,
 * and that the DOCUMENTED products/ route is used when an FSA token is present.
 */
class FreemiusPlanProvisionTest extends TenantTestCase
{
    private function configureFreemius(string $fsaToken = ''): void
    {
        config()->set('services.freemius.enabled', true);
        config()->set('services.freemius.product_id', '777');
        config()->set('services.freemius.api_token', 'test-api-token');
        config()->set('services.freemius.secret_key', 'test-secret-key');
        config()->set('services.freemius.public_key', 'test-public-key');
        config()->set('services.freemius.api_url', 'https://api.freemius.test');
        config()->set('services.freemius.checkout_url', 'https://checkout.freemius.test');
        config()->set('services.freemius.developer_id', '123');
        config()->set('services.freemius.developer_fsa_token', $fsaToken);
        // Legacy fallback (used only when the FSA token is absent).
        config()->set('services.freemius.developer_public_key', $fsaToken === '' ? 'pk_test' : '');
        config()->set('services.freemius.developer_secret_key', $fsaToken === '' ? 'sk_test' : '');
    }

    private function makeTier(string $slug, float $monthly, float $annual, ?array $metadata = null): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'id' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'monthly_price' => $monthly,
            'yearly_price' => $annual,
            'currency' => 'USD',
            'trial_days' => 7,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
            'metadata' => $metadata ?? [],
        ]);
    }

    private function ping()
    {
        return Http::response(['timestamp' => now()->toRfc2822String()], 200);
    }

    public function test_dry_run_reports_a_missing_plan_and_writes_nothing(): void
    {
        $this->configureFreemius();
        $this->makeTier('starter', 49.0, 490.0);

        Http::fake([
            'api.freemius.test/v1/ping.json' => $this->ping(),
            'api.freemius.test/v1/products/777/plans.json' => Http::response(['plans' => []], 200),
        ]);

        $report = (new FreemiusPlanProvisioner)->provision(false, ['starter']);

        $this->assertTrue($report['ok']);
        $this->assertFalse($report['applied']);
        $this->assertSame('would_create_plan', $report['tiers'][0]['status']);
        $this->assertTrue($report['tiers'][0]['ok']);
        $this->assertNull($report['tiers'][0]['plan_id']);

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_apply_creates_plan_and_pricing_then_links_and_verifies(): void
    {
        $this->configureFreemius();
        $plan = $this->makeTier('starter', 49.0, 490.0);

        Http::fake([
            'api.freemius.test/v1/ping.json' => $this->ping(),
            'api.freemius.test/v1/products/777/plans.json' => Http::response(['plans' => []], 200),
            'api.freemius.test/v1/products/777/plans/1010/pricing.json' => Http::response(['pricing' => []], 200),
            'api.freemius.test/v1/products/777/plans/1010/pricing/5005.json' => Http::response(['pricing' => [
                'id' => '5005', 'plan_id' => '1010', 'currency' => 'usd', 'monthly_price' => 49, 'annual_price' => 490,
            ]], 200),
            'api.freemius.test/v1/developers/*' => function ($request) {
                if (str_ends_with($request->url(), '/plans.json')) {
                    return Http::response(['id' => '1010', 'name' => 'starter', 'title' => 'Starter'], 200);
                }

                return Http::response(['pricing' => ['id' => '5005', 'currency' => 'usd', 'monthly_price' => 49, 'annual_price' => 490]], 200);
            },
        ]);

        $report = (new FreemiusPlanProvisioner)->provision(true, ['starter']);

        $this->assertTrue($report['ok']);
        $this->assertSame('created', $report['tiers'][0]['status']);
        $this->assertSame('1010', $report['tiers'][0]['plan_id']);
        $this->assertSame('5005', $report['tiers'][0]['pricing_id']);

        $fresh = $plan->fresh();
        $this->assertSame('1010', $fresh->metadata['freemius_plan_id']);
        $this->assertSame('1010', $fresh->metadata['freemius_monthly_plan_id']);
        $this->assertSame('1010', $fresh->metadata['freemius_yearly_plan_id']);
        $this->assertSame('5005', $fresh->metadata['freemius_pricing_id']);
        $this->assertSame(49.0, (float) $fresh->metadata['freemius_prices']['monthly']['amount']);
        $this->assertSame(490.0, (float) $fresh->metadata['freemius_prices']['yearly']['amount']);

        // The DISPLAYED prices were not rewritten (they already matched).
        $this->assertSame(49.0, (float) $fresh->monthly_price);
        $this->assertSame(490.0, (float) $fresh->yearly_price);

        // The plan was created with the documented payload.
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/v1/developers/123/plugins/777/plans.json')
            && str_contains($request->body(), '"name":"starter"'));
    }

    public function test_second_run_is_idempotent_and_creates_nothing(): void
    {
        $this->configureFreemius();
        $this->makeTier('starter', 49.0, 490.0, [
            'freemius_plan_id' => '1010',
            'freemius_monthly_plan_id' => '1010',
            'freemius_yearly_plan_id' => '1010',
        ]);

        Http::fake([
            'api.freemius.test/v1/ping.json' => $this->ping(),
            'api.freemius.test/v1/products/777/plans.json' => Http::response(['plans' => [['id' => '1010', 'name' => 'starter']]], 200),
            'api.freemius.test/v1/products/777/plans/1010/pricing.json' => Http::response(['pricing' => [[
                'id' => '5005', 'plan_id' => '1010', 'currency' => 'usd', 'monthly_price' => 49, 'annual_price' => 490,
            ]]], 200),
            'api.freemius.test/v1/products/777/plans/1010/pricing/5005.json' => Http::response(['pricing' => [
                'id' => '5005', 'plan_id' => '1010', 'currency' => 'usd', 'monthly_price' => 49, 'annual_price' => 490,
            ]], 200),
        ]);

        $report = (new FreemiusPlanProvisioner)->provision(true, ['starter']);

        $this->assertTrue($report['ok']);
        $this->assertSame('linked', $report['tiers'][0]['status']);
        $this->assertSame('1010', $report['tiers'][0]['plan_id']);
        $this->assertSame('5005', $report['tiers'][0]['pricing_id']);

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_multiple_pricings_are_ambiguous_and_never_guessed(): void
    {
        $this->configureFreemius();
        $plan = $this->makeTier('starter', 49.0, 490.0);

        Http::fake([
            'api.freemius.test/v1/ping.json' => $this->ping(),
            'api.freemius.test/v1/products/777/plans.json' => Http::response(['plans' => [['id' => '1010', 'name' => 'starter']]], 200),
            'api.freemius.test/v1/products/777/plans/1010/pricing.json' => Http::response(['pricing' => [
                ['id' => '5005', 'currency' => 'usd', 'monthly_price' => 49, 'annual_price' => 490],
                ['id' => '6006', 'currency' => 'usd', 'monthly_price' => 59, 'annual_price' => 590],
            ]], 200),
        ]);

        $report = (new FreemiusPlanProvisioner)->provision(true, ['starter']);

        $this->assertFalse($report['ok']);
        $this->assertSame('ambiguous', $report['tiers'][0]['status']);
        $this->assertNull($report['tiers'][0]['pricing_id']);
        $this->assertNull($plan->fresh()->metadata['freemius_pricing_id'] ?? null);

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_mismatched_remote_price_does_not_link(): void
    {
        $this->configureFreemius();
        $plan = $this->makeTier('starter', 49.0, 490.0);

        Http::fake([
            'api.freemius.test/v1/ping.json' => $this->ping(),
            'api.freemius.test/v1/products/777/plans.json' => Http::response(['plans' => [['id' => '1010', 'name' => 'starter']]], 200),
            'api.freemius.test/v1/products/777/plans/1010/pricing.json' => Http::response(['pricing' => [[
                'id' => '5005', 'currency' => 'usd', 'monthly_price' => 49, 'annual_price' => 490,
            ]]], 200),
            'api.freemius.test/v1/products/777/plans/1010/pricing/5005.json' => Http::response(['pricing' => [
                'id' => '5005', 'currency' => 'usd', 'monthly_price' => 99, 'annual_price' => 990,
            ]], 200),
        ]);

        $report = (new FreemiusPlanProvisioner)->provision(true, ['starter']);

        $this->assertFalse($report['ok']);
        $this->assertSame('mismatch', $report['tiers'][0]['status']);
        $this->assertNull($plan->fresh()->metadata['freemius_plan_id'] ?? null);
    }

    public function test_fsa_token_uses_the_documented_products_route(): void
    {
        $this->configureFreemius('fsa-dev-token');
        $this->makeTier('starter', 49.0, 490.0);

        Http::fake([
            'api.freemius.test/v1/products/777/plans.json' => Http::response(['plans' => []], 200),
            'api.freemius.test/v1/developers/*' => Http::response(['id' => '1010', 'name' => 'starter'], 200),
        ]);

        $created = FreemiusGateway::createPlan(['name' => 'starter', 'title' => 'Starter']);

        $this->assertTrue($created['ok']);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/v1/developers/123/products/777/plans.json')
            && ! str_contains($request->url(), '/plugins/')
            && (string) $request->header('Authorization')[0] === 'FSA 123:fsa-dev-token');
    }
}
