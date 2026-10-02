<?php

namespace App\Console\Commands;

use App\Monitoring\MetricCollector;
use App\Monitoring\AlertDispatcher;
use Illuminate\Console\Command;

class CollectMonitoringMetrics extends Command
{
    protected $signature = 'monitoring:collect';

    protected $description = 'Collect a full infrastructure snapshot (host, db, redis, queue, storage, security) and store it.';

    public function handle(MetricCollector $collector, AlertDispatcher $dispatcher): int
    {
        $snapshot = $collector->collect();

        // Prune old snapshots (scheduler task retention config).
        $retention = (int) config('monitoring.snapshot_retention', 1440);
        try {
            \App\Models\MonitoringSnapshot::where('id', '<', \App\Models\MonitoringSnapshot::max('id') - $retention)->delete();
        } catch (\Throwable) {
            // best effort
        }

        $this->info('Monitoring snapshot collected at ' . ($snapshot['collected_at'] ?? now()->toIso8601String()));

        return self::SUCCESS;
    }
}