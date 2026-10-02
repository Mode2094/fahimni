<?php

namespace App\Jobs\Media;

use App\Models\Tenant;
use App\Services\SaasCostService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * إعادة حساب تكاليف الاستخدام لكل المستأجرين + لقطات ربحية الخطط.
 * يضمن أن provider_usage_costs و plan_profitability محدثة دائماً.
 */
class RecalculateCostsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function handle(SaasCostService $cost): void
    {
        foreach (Tenant::query()->pluck('id') as $tenantId) {
            try {
                $cost->persistUsageCost((string) $tenantId);
            } catch (\Throwable) {
                // مستأجر بلا بيانات استخدام — يتخطى.
            }
        }

        $cost->persistPlanProfitability();
    }
}
