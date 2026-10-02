<?php

namespace App\Console\Commands;

use App\Models\MonitoringSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ShowMonitoringMetrics extends Command
{
    protected $signature = 'monitoring:metrics {--json} {--limit=1}';

    protected $description = 'Show the latest monitoring snapshot(s).';

    public function handle(): int
    {
        $snapshots = MonitoringSnapshot::query()
            ->orderByDesc('collected_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($snapshots->isEmpty()) {
            $this->warn('No monitoring snapshots found. Run `monitoring:collect` first.');

            return self::SUCCESS;
        }

        foreach ($snapshots as $snapshot) {
            if ($this->option('json')) {
                $this->line(json_encode($snapshot->payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                continue;
            }

            $p = $snapshot->payload;
            $host = $p['host'] ?? [];
            $db = $p['database'] ?? [];
            $redis = $p['redis'] ?? [];
            $queue = $p['queue'] ?? [];

            $this->line('── Snapshot #'.$snapshot->id.' @ '.($snapshot->collected_at?->toDateTimeString() ?? 'n/a').' ('.$snapshot->duration_ms.'ms)');
            $this->line('  Host   : CPU '.($host['cpu_percent'] ?? '?').'%  RAM '.($host['mem_percent'] ?? '?').'%  Disk '.($host['disk_root_percent'] ?? '?').'%  Load '.($host['loadavg_1'] ?? '?'));
            $this->line('  DB     : '.($db['engine'] ?? '?').'  conn '.($db['connections_total'] ?? '?').'/'.($db['connections_max'] ?? '?').'  slow '.($db['slow_queries_running'] ?? 0).'  size '.($db['database_size_mb'] ?? '?').'MB');
            $this->line('  Redis  : '.($redis['connected'] ? 'up' : 'DOWN').'  mem '.($redis['memory_percent'] ?? '?').'%  evicted '.($redis['evicted_keys'] ?? 0));
            $this->line('  Queue  : pending '.($queue['total_pending'] ?? 0).'  failed '.($queue['failed_total'] ?? 0).'  horizon '.($queue['horizon'] ?? '?'));
            $this->line('  App    : req '.($p['app']['requests_total'] ?? 0).'  5xx '.($p['app']['requests_5xx'] ?? 0).'  pMax '.($p['app']['max_duration_ms'] ?? 0).'ms');
        }

        return self::SUCCESS;
    }
}