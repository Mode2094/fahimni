<?php

namespace App\Console\Commands;

use App\Monitoring\AlertDispatcher;
use App\Models\MonitoringSnapshot;
use Illuminate\Console\Command;

class CheckMonitoringAlerts extends Command
{
    protected $signature = 'monitoring:check';

    protected $description = 'Evaluate the latest monitoring snapshot against thresholds and dispatch alerts.';

    public function handle(AlertDispatcher $dispatcher): int
    {
        $snapshot = MonitoringSnapshot::query()
            ->orderByDesc('collected_at')
            ->first();

        if (! $snapshot) {
            $this->warn('No monitoring snapshot found. Run `monitoring:collect` first.');

            return self::SUCCESS;
        }

        $dispatched = $dispatcher->evaluate($snapshot->payload);

        if ($dispatched === []) {
            $this->info('No new alerts.');

            return self::SUCCESS;
        }

        foreach ($dispatched as $index => $isNew) {
            $this->line(($isNew ? 'ALERT: ' : 'repeat: ') . 'snapshot #' . $snapshot->id);
        }

        $this->info(count($dispatched) . ' alert(s) evaluated.');

        return self::SUCCESS;
    }
}