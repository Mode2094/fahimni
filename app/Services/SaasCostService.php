<?php

namespace App\Services;

use App\Media\MediaProviderManager;
use App\Media\MediaUsageService;
use App\Models\PlanProfitability;
use App\Models\ProviderCostRule;
use App\Models\ProviderUsageCost;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use Illuminate\Support\Facades\Cache;

/**
 * SaaS Cost & Profit Engine.
 *
 * الحسابات كلها من قاعدة البيانات:
 *   - الكميات: media_usage_monthly + media_assets (استخدام حقيقي)
 *   - الأسعار: provider_cost_rules (المصدر الوحيد للحقيقة)
 *   - الحدود: PlanEngine (limits من الخطة)
 *
 * لا يوجد أي سعر أو حد داخل هذا الكود.
 */
class SaasCostService
{
    private const CACHE_KEY = 'saas_cost:';

    private const CACHE_TTL = 900;

    public function __construct(
        private readonly MediaUsageService $usage,
        private readonly MediaProviderManager $manager,
    ) {}

    // ─── أسعار الوحدات (DB) ────────────────────────────

    public function unitCost(string $provider, string $resource): float
    {
        return ProviderCostRule::unitCost($provider, $resource);
    }

    public function ruleMetadata(string $provider, string $resource, string $key, float $default): float
    {
        $rule = ProviderCostRule::rule($provider, $resource);

        return (float) (($rule?->metadata[$key] ?? null) ?: $default);
    }

    /**
     * المزود النشط المستخدم في الحسابات.
     */
    private function activeProvider(): string
    {
        return $this->manager->activeProvider()?->name() ?? 'bunny';
    }

    // ─── تكلفة الاستخدام الفعلية لمعلم (شهر) ───────────

    /**
     * التكلفة التقديرية الفعلية لمستأجر في شهر معين.
     *
     * @return array{total: float, currency: string, breakdown: array<string, float>, provider: string}
     */
    public function usageCost(string $tenantId, ?string $yearMonth = null, bool $cache = true): array
    {
        $yearMonth ??= now()->format('Y-m');
        $provider = $this->activeProvider();

        if ($cache) {
            $cached = Cache::get(self::CACHE_KEY.'usage:'.$tenantId.':'.$yearMonth);

            if (is_array($cached)) {
                return $cached;
            }
        }

        $monthly = $this->usage->monthlyAll($tenantId, $yearMonth);

        $storageGb = (float) (($monthly['storage_bytes'] ?? 0) / (1024 ** 3));
        $liveMinutes = (float) ($monthly['live_minutes'] ?? 0);
        $videosCreated = (float) ($monthly['videos'] ?? 0);

        $deliveryFactor = $this->ruleMetadata($provider, 'delivery', 'delivery_factor', 2.0);
        $expectedEmails = $this->ruleMetadata($provider, 'storage', 'expected_emails_month', 100.0);

        $breakdown = [
            'storage' => round($storageGb * $this->unitCost($provider, 'storage'), 4),
            'delivery' => round($storageGb * $deliveryFactor * $this->unitCost($provider, 'delivery'), 4),
            'encoding' => round($videosCreated * $this->unitCost($provider, 'encoding'), 4),
            'live' => round(($liveMinutes / 60) * $this->unitCost($provider, 'live'), 4),
            'email' => round($expectedEmails * $this->unitCost('email', 'email'), 4),
            'sms' => 0.0,
        ];

        // الحد الأدنى الشهري للمزود (Bunny: $1/شهر).
        $minMonthly = $this->ruleMetadata($provider, 'storage', 'min_monthly', 0.0);
        $total = round(array_sum($breakdown), 4);
        $currency = 'USD';

        $result = [
            'total' => max($minMonthly, $total),
            'total_before_min' => $total,
            'currency' => $currency,
            'breakdown' => $breakdown,
            'provider' => $provider,
            'year_month' => $yearMonth,
        ];

        if ($cache) {
            Cache::put(self::CACHE_KEY.'usage:'.$tenantId.':'.$yearMonth, $result, self::CACHE_TTL);
        }

        return $result;
    }

    /**
     * حفظ التكلفة الفعلية في provider_usage_costs (idempotent لكل شهر).
     */
    public function persistUsageCost(string $tenantId, ?string $yearMonth = null): array
    {
        $yearMonth ??= now()->format('Y-m');
        $cost = $this->usageCost($tenantId, $yearMonth, cache: false);

        foreach ($cost['breakdown'] as $resource => $amount) {
            ProviderUsageCost::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'provider' => $cost['provider'],
                    'resource' => $resource,
                    'year_month' => $yearMonth,
                ],
                [
                    'quantity' => $this->quantityFor($tenantId, $resource, $yearMonth),
                    'cost' => $amount,
                    'currency' => $cost['currency'],
                    'metadata' => ['breakdown_total' => $cost['total']],
                ],
            );
        }

        return $cost;
    }

    private function quantityFor(string $tenantId, string $resource, string $yearMonth): float
    {
        $monthly = $this->usage->monthlyAll($tenantId, $yearMonth);

        return match ($resource) {
            'storage' => (float) (($monthly['storage_bytes'] ?? 0) / (1024 ** 3)),
            'delivery' => round(((float) ($monthly['storage_bytes'] ?? 0) / (1024 ** 3))
                * $this->ruleMetadata($this->activeProvider(), 'delivery', 'delivery_factor', 2.0), 4),
            'live' => (float) (($monthly['live_minutes'] ?? 0) / 60),
            'encoding' => (float) ($monthly['videos'] ?? 0),
            default => 0.0,
        };
    }

    // ─── التكلفة القصوى المتوقعة للخطة (من حدودها) ─────

    /**
     * أقصى تكلفة بنية تحتية متوقعة للخطة من limits الخاصة بها.
     */
    public function planExpectedCost(SubscriptionPlan $plan): float
    {
        $provider = $this->activeProvider();

        $storageGb = (float) $plan->featureValue('storage_limit_mb', 0) / 1024;
        $videosLimit = (float) $plan->featureValue('videos_limit', 0);
        $liveMinutesLimit = (float) $plan->featureValue('live_minutes_limit', 0);
        $liveEnabled = filter_var($plan->featureValue('live_stream_enabled', 'false'), FILTER_VALIDATE_BOOLEAN);

        $deliveryFactor = $this->ruleMetadata($provider, 'delivery', 'delivery_factor', 2.0);
        $expectedEmails = $this->ruleMetadata($provider, 'storage', 'expected_emails_month', 100.0);

        $cost = ($storageGb * $this->unitCost($provider, 'storage'))
            + ($storageGb * $deliveryFactor * $this->unitCost($provider, 'delivery'))
            + ($videosLimit * $this->unitCost($provider, 'encoding'))
            + ($liveEnabled ? ($liveMinutesLimit / 60) * $this->unitCost($provider, 'live') : 0)
            + ($expectedEmails * $this->unitCost('email', 'email'));

        // الحد الأدنى الشهري للمزود (Bunny: $1/شهر).
        $minMonthly = $this->ruleMetadata($provider, 'storage', 'min_monthly', 0.0);

        return round(max($minMonthly, $cost), 4);
    }

    /**
     * ربحية الخطة: السعر − أقصى تكلفة متوقعة.
     *
     * @return array{price: float, estimated_cost: float, expected_profit: float, margin_percentage: float, loss: bool, currency: string}
     */
    public function planProfitability(SubscriptionPlan $plan, string $billingCycle = 'monthly'): array
    {
        // التكلفة محسوبة بالدولار — نحول سعر الخطة لنفس العملة عبر
        // currency_rate من إعدادات المنصة (المصدر الوحيد للحقيقة).
        $rate = (float) \App\Models\PlatformSetting::get('currency_rate', 1);
        $rate = $rate > 0 ? $rate : 1;

        $price = $billingCycle === 'yearly' ? (float) $plan->yearly_price : (float) $plan->monthly_price;
        $priceUsd = round($price * $rate, 2);
        $estimated = $this->planExpectedCost($plan);
        $profit = round($priceUsd - $estimated, 2);

        return [
            'price' => $price,
            'price_usd' => $priceUsd,
            'estimated_cost' => $estimated,
            'expected_profit' => $profit,
            'margin_percentage' => $priceUsd > 0 ? round(($profit / $priceUsd) * 100, 2) : 0.0,
            'roi_percentage' => $estimated > 0 ? round(($profit / $estimated) * 100, 2) : 0.0,
            'loss' => $profit < 0,
            'currency' => $plan->currency ?? 'JOD',
            'fx_rate' => $rate,
        ];
    }

    /**
     * حفظ لقطات الربحية لكل الخطط النشطة في plan_profitability.
     */
    public function persistPlanProfitability(): int
    {
        $count = 0;

        foreach (SubscriptionPlan::where('is_active', true)->get() as $plan) {
            foreach (['monthly', 'yearly'] as $cycle) {
                $p = $this->planProfitability($plan, $cycle);
                $subscribers = TenantSubscription::withoutTenant()
                    ->where('plan_id', $plan->id)
                    ->whereIn('status', ['active', 'trialing'])
                    ->count();

                PlanProfitability::updateOrCreate(
                    ['plan_id' => $plan->id, 'billing_cycle' => $cycle],
                    [
                        'subscription_price' => $p['price'],
                        'estimated_cost' => $p['estimated_cost'],
                        'expected_profit' => $p['expected_profit'],
                        'margin_percentage' => $p['margin_percentage'],
                        'subscribers_count' => $subscribers,
                        'recalculated_at' => now(),
                        'metadata' => ['provider' => $this->activeProvider()],
                    ],
                );

                $count++;
            }
        }

        return $count;
    }

    /**
     * تحذير الربحية عند تعديل خطة (لا يمنع — ينبه فقط).
     */
    public function profitabilityWarning(SubscriptionPlan $plan): ?array
    {
        $p = $this->planProfitability($plan);

        if (! $p['loss']) {
            return null;
        }

        return [
            'message' => "الخطة ({$plan->name}) بتكلفة متوقعة {$p['estimated_cost']} {$p['currency']} تتجاوز سعرها ({$p['price']} {$p['currency']}) — الخطة ستكون خاسرة.",
            'price' => $p['price'],
            'estimated_cost' => $p['estimated_cost'],
            'expected_profit' => $p['expected_profit'],
            'margin_percentage' => $p['margin_percentage'],
            'loss' => true,
        ];
    }

    // ─── لوحة BI ───────────────────────────────────────

    /**
     * ملخص Business Intelligence للوحة الأدمن.
     */
    public function biSummary(): array
    {
        $provider = $this->activeProvider();

        // ربحية الخطط
        $plans = SubscriptionPlan::where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($plan) {
                $row = $this->planProfitability($plan);

                return [
                    'plan_id' => $plan->id,
                    'plan_name' => $plan->name,
                    'price' => $row['price'],
                    'price_usd' => $row['price_usd'],
                    'estimated_cost' => $row['estimated_cost'],
                    'expected_profit' => $row['expected_profit'],
                    'margin_percentage' => $row['margin_percentage'],
                    'roi_percentage' => $row['roi_percentage'],
                    'loss' => $row['loss'],
                    'currency' => $row['currency'],
                    'subscribers' => TenantSubscription::withoutTenant()
                        ->where('plan_id', $plan->id)
                        ->whereIn('status', ['active', 'trialing'])
                        ->count(),
                ];
            })
            ->values();

        // تكاليف البنية: فعلية هذا الشهر لكل مزود
        $yearMonth = now()->format('Y-m');
        $infrastructure = ProviderUsageCost::withoutTenant()
            ->where('year_month', $yearMonth)
            ->get()
            ->groupBy('provider')
            ->map(function ($rows, $prov) {
                $total = (float) $rows->sum('cost');

                return [
                    'provider' => $prov,
                    'storage' => round((float) $rows->where('resource', 'storage')->sum('cost'), 4),
                    'delivery' => round((float) $rows->where('resource', 'delivery')->sum('cost'), 4),
                    'encoding' => round((float) $rows->where('resource', 'encoding')->sum('cost'), 4),
                    'live' => round((float) $rows->where('resource', 'live')->sum('cost'), 4),
                    'email' => round((float) $rows->where('resource', 'email')->sum('cost'), 4),
                    'total' => round($total, 4),
                    'currency' => $rows->first()->currency ?? 'USD',
                ];
            })
            ->values();

        $monthlyInfraCost = (float) $infrastructure->sum('total');

        // الإيراد بعملة المنصة (JOD) — الاشتراكات المدفوعة النشطة فقط
        // (التجارب لا تدر إيراداً) + معادله بالدولار عبر سعر الصرف الرسمي.
        $rate = (float) \App\Models\PlatformSetting::get('currency_rate', 1);
        $rate = $rate > 0 ? $rate : 1;

        $monthlyRevenue = TenantSubscription::withoutTenant()
            ->whereIn('status', ['active'])
            ->get()
            ->sum(fn ($s) => (float) ($s->billing_cycle === 'yearly' ? $s->plan?->yearly_price / 12 : $s->plan?->monthly_price));

        $monthlyRevenueUsd = round($monthlyRevenue * $rate, 2);

        return [
            'plans' => $plans,
            'infrastructure' => $infrastructure,
            'active_provider' => $provider,
            'monthly_infra_cost' => round($monthlyInfraCost, 2),
            'monthly_revenue' => round($monthlyRevenue, 2),
            'monthly_revenue_usd' => $monthlyRevenueUsd,
            'platform_margin' => round($monthlyRevenueUsd - $monthlyInfraCost, 2),
            'currency' => 'USD',     // عملة التكاليف/الهامش
            'revenue_currency' => 'JOD', // عملة الإيراد
            'fx_rate' => $rate,
        ];
    }
}
