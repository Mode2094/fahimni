<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class WriteMonitoringHeartbeat extends Command
{
    protected $signature = 'monitoring:heartbeat';

    protected $description = 'Write the scheduler heartbeat file (proves schedule:work is alive).';

    public function handle(): int
    {
        $path = (string) config('monitoring.scheduler.heartbeat_path', storage_path('monitoring/scheduler-heartbeat'));

        try {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            file_put_contents($path, now()->toIso8601String());

            $this->info('Heartbeat written to '.$path);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to write heartbeat: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}