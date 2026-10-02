<?php

namespace App\Jobs\Media;

use App\Media\MediaCostService;
use App\Media\MediaReconciliationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncProviderUsageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 120;

    public function handle(MediaReconciliationService $reconcile, MediaCostService $costs): void
    {
        // 1) مزامنة الأصول والاستخدام مع المزود (Bunny Stream).
        $reconcile->reconcile('bunny');

        // 2) حساب التكلفة الشهرية.
        $tenants = $costs->persistMonthlyCosts();

        Log::info('media.job.sync_usage_done', ['tenants_costed' => $tenants]);
    }
}