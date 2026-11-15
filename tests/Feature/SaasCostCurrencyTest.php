<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Services\SaasCostService;
use Illuminate\Support\Str;

/**
 * Phase 6 — pricing correctness. Platform-subscription prices are stored in
 * USD, so the cost engine must not convert them a second time via
 * currency_rate (that inflated profit/margin for every non-1 rate).
 */
class SaasCostCurrencyTest extends TenantTestCase
{
    private function makePlan(float $price, string $currency): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'id' => (string) Str::uuid(),
            'name' => 'Plan '.Str::random(4),
            'slug' => 'plan-'.Str::random(6),
            'monthly_price' => $price,
            'yearly_price' => $price * 10,
            'currency' => $currency,
            'trial_days' => 7,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_a_usd_plan_price_is_not_converted_again(): void
    {
        PlatformSetting::set('currency_rate', 3);

        $plan = $this->makePlan(99, 'USD');
        $p = app(SaasCostService::class)->planProfitability($plan);

        $this->assertSame(99.0, $p['price_usd']);
        $this->assertSame('USD', $p['currency']);
        // No cost rules seeded → expected cost 0 → profit equals the price.
        $this->assertSame(99.0, $p['expected_profit']);
    }

    public function test_a_legacy_non_usd_plan_is_still_converted(): void
    {
        PlatformSetting::set('currency_rate', 3);

        $plan = $this->makePlan(50, 'JOD');
        $p = app(SaasCostService::class)->planProfitability($plan);

        $this->assertSame(150.0, $p['price_usd']);
        $this->assertSame('JOD', $p['currency']);
    }

    public function test_bi_revenue_is_not_multiplied_by_the_fx_rate(): void
    {
        PlatformSetting::set('currency_rate', 3);

        $plan = $this->makePlan(99, 'USD');
        $tenant = $this->makeTenant();

        TenantSubscription::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'auto_renew' => true,
        ]);

        $bi = app(SaasCostService::class)->biSummary();

        $this->assertSame('USD', $bi['revenue_currency']);
        $this->assertSame(99.0, $bi['monthly_revenue_usd']);
        $this->assertSame(99.0, $bi['monthly_revenue']);
    }
}
