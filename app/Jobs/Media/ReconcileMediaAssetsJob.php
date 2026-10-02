<?php

namespace App\Jobs\Media;

use App\Media\MediaReconciliationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ReconcileMediaAssetsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(private readonly ?string $provider = 'bunny', private readonly ?string $tenantId = null) {}

    public function handle(MediaReconciliationService $service): void
    {
        $result = $service->reconcile($this->provider, $this->tenantId);

        Log::info('media.job.reconcile_done', ['provider' => $this->provider, 'result' => $result]);
    }
}
