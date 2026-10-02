<?php

namespace App\Media;

use App\Models\MediaCost;
use App\Models\MediaUsageAggregate;
use App\Models\ProviderPricing;
use App\Models\TenantSubscription;
use Illuminate\Support\Facades\Cache;

/**
 * محرك التكلفة — يحوّل الاستخدام الفعلي إلى تكلفة باستخدام أسعار ديناميكية
 * (provider_pricing) قابلة للتعديل من لوحة الأدمن. لا يوجد أي سعر في الكود.
 *
 * وحدات الموارد المتوقعة في provider_pricing:
 *   storage_gb   → سعر الجيجابايت المخزن شهرياً
 *   delivery_gb  → سعر جيجابايت التسليم (تشغيل)
 *   live_minutes → سعر دقيقة البث المباشر
 *   video_minutes → سعر دقيقة الفيديو المخزن
 */
class MediaCostService
{
    private const CACHE_KEY = 'media:cost:';

    public function __construct(
        private readonly MediaUsageService $usage,
    ) {}

    /**
     * التكلفة التقديرية لمعلم واحد في شهر معين.
     *
     * @return array{total: float, currency: string, breakdown: array<string, float>}
     */
    public function tenantCost(string $tenantId, ?string $yearMonth = null): array
    {
        $yearMonth ??= now()->format('Y-m');
        $cacheKey = self::CACHE_KEY.'tenant:'.$tenantId.':'.$yearMonth;

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $monthly = $this->usage->monthlyAll($tenantId, $yearMonth);

        // المصدر: المزود الفعلي النشط — لا تثبيت على أي مزود بعينه.
        $provider = app(\App\Media\MediaProviderManager::class)
            ->activeProvider()?->name() ?? 'bunny';

        $breakdown = [
            'storage_gb' => $this->cost($provider, 'storage_gb', round(($monthly['storage_bytes'] ?? 0) / (1024 ** 3), 4)),
            'live_minutes' => $this->cost($provider, 'live_minutes', $monthly['live_minutes'] ?? 0),
            'delivery_gb' => $this->cost($provider, 'delivery_gb', 0), // delivery يحتاج بيانات من المزود
        ];

        $total = round(array_sum($breakdown), 6);

        $result = [
            'total' => $total,
            'currency' => $this->currency($provider),
            'breakdown' => $breakdown,
            'year_month' => $yearMonth,
        ];

        Cache::put($cacheKey, $result, 900);

        return $result;
    }

    private function cost(string $provider, string $resource, float $quantity): float
    {
        return round(ProviderPricing::unitPrice($provider, $resource) * $quantity, 6);
    }

    private function currency(string $provider): string
    {
        return ProviderPricing::latest($provider, 'storage_gb')?->currency ?? 'USD';
    }

    /**
     * إجمالي تكلفة البنية التحتية للمنصة في شهر معين.
     */
    public function platformTotal(?string $yearMonth = null): array
    {
        $yearMonth ??= now()->format('Y-m');

        $tenantIds = MediaUsageAggregate::withoutTenant()->distinct()->pluck('tenant_id');

        $total = 0.0;
        $currency = 'USD';
        $count = 0;

        foreach ($tenantIds as $tenantId) {
            $cost = $this->tenantCost($tenantId, $yearMonth);
            $total += $cost['total'];
            $currency = $cost['currency'];
            $count++;
        }

        return ['total' => round($total, 2), 'currency' => $currency, 'tenants_with_cost' => $count, 'year_month' => $yearMonth];
    }

    /**
     * حساب وتخزين التكلفة الشهرية لكل معلم (media_costs) — Job شهري.
     */
    public function persistMonthlyCosts(?string $yearMonth = null): int
    {
        $yearMonth ??= now()->format('Y-m');
        $count = 0;

        $tenantIds = MediaUsageAggregate::withoutTenant()->distinct()->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $cost = $this->tenantCost($tenantId, $yearMonth);

            foreach ($cost['breakdown'] as $resource => $amount) {
                $provider = app(\App\Media\MediaProviderManager::class)
                    ->activeProvider()?->name() ?? 'bunny';

                MediaCost::updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'year_month' => $yearMonth,
                        'provider' => $provider,
                        'resource' => $resource,
                    ],
                    [
                        'amount' => $amount,
                        'currency' => $cost['currency'],
                        'metadata' => ['total' => $cost['total']],
                        'calculated_at' => now(),
                    ],
                );
            }

            $count++;
        }

        return $count;
    }

    /**
     * ربحية المعلمين: إيراد الخطة مقابل تكلفة البنية التحتية.
     */
    public function teacherProfitability(?string $yearMonth = null, int $limit = 100): array
    {
        $yearMonth ??= now()->format('Y-m');

        $subscriptions = TenantSubscription::withoutTenant()
            ->whereIn('status', ['active', 'trialing'])
            ->with('plan')
            ->get()
            ->keyBy('tenant_id');

        $out = [];

        foreach ($subscriptions as $tenantId => $subscription) {
            $cost = $this->tenantCost($tenantId, $yearMonth);
            $planPrice = (float) ($subscription->billing_cycle === 'yearly'
                ? $subscription->plan?->yearly_price ?? 0
                : $subscription->plan?->monthly_price ?? 0);

            $margin = $planPrice - $cost['total'];

            $out[] = [
                'tenant_id' => $tenantId,
                'tenant_name' => $subscription->tenant?->name ?? $tenantId,
                'plan_name' => $subscription->plan?->name ?? '—',
                'plan_price' => $planPrice,
                'infrastructure_cost' => $cost['total'],
                'margin' => round($margin, 2),
                'margin_pct' => $planPrice > 0 ? (int) round(($margin / $planPrice) * 100) : null,
                'currency' => $cost['currency'],
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        usort($out, fn ($a, $b) => $b['infrastructure_cost'] <=> $a['infrastructure_cost']);

        return $out;
    }

    /**
     * ملخص شامل للوحة الأدمن.
     */
    public function adminSummary(?string $yearMonth = null): array
    {
        $yearMonth ??= now()->format('Y-m');

        $totalStorage = (int) MediaUsageAggregate::withoutTenant()->where('resource', 'storage_bytes')->sum('value');
        $totalVideos = (int) MediaUsageAggregate::withoutTenant()->where('resource', 'video_count')->sum('value');
        $totalLiveSessions = (int) MediaUsageAggregate::withoutTenant()->where('resource', 'live_sessions_count')->sum('value');
        $totalLiveMinutes = (int) MediaUsageAggregate::withoutTenant()->where('resource', 'live_minutes')->sum('value');
        $totalRecordings = (int) MediaUsageAggregate::withoutTenant()->where('resource', 'recording_count')->sum('value');
        $teachersUsingMedia = MediaUsageAggregate::withoutTenant()
            ->whereIn('resource', ['storage_bytes', 'video_count', 'live_sessions_count'])
            ->distinct()
            ->count('tenant_id');

        return [
            'year_month' => $yearMonth,
            'storage_gb' => round($totalStorage / (1024 ** 3), 2),
            'videos' => $totalVideos,
            'live_sessions' => $totalLiveSessions,
            'live_minutes' => $totalLiveMinutes,
            'recordings' => $totalRecordings,
            'teachers_using_media' => $teachersUsingMedia,
            'cost' => $this->platformTotal($yearMonth),
        ];
    }
}
