<?php

namespace App\Console\Commands;

use App\Models\MonitoringSlowRequest;
use Illuminate\Console\Command;

class PruneMonitoringSlowRequests extends Command
{
    protected $signature = 'monitoring:prune-slow-requests';

    protected $description = 'Prune old slow-request rows beyond the configured retention.';

    public function handle(): int
    {
        $retention = (int) config('monitoring.request.slow_retention', 10000);

        try {
            $ids = MonitoringSlowRequest::query()
                ->orderByDesc('id')
                ->offset($retention)
                ->limit(1000)
                ->pluck('id');

            $count = $ids->count();
            if ($count > 0) {
                MonitoringSlowRequest::whereIn('id', $ids)->delete();
            }

            $this->info("Pruned {$count} slow-request rows.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to prune slow requests: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}