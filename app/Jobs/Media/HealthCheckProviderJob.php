<?php

namespace App\Jobs\Media;

use App\Media\ProviderHealthService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class HealthCheckProviderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function handle(ProviderHealthService $health): void
    {
        $results = $health->checkAll();

        Log::info('media.job.provider_health', ['results' => $results]);
    }
}
