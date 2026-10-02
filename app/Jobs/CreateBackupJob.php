<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\Backup\BackupManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(public readonly string $backupId)
    {
        $this->onQueue(config('backup.queue', 'backups'));
        $this->timeout = (int) config('backup.timeout', 7200);
    }

    public function handle(BackupManager $manager): void
    {
        $backup = Backup::query()->find($this->backupId);

        if (! $backup) {
            Log::warning('Backup job skipped: record not found', ['backup_id' => $this->backupId]);

            return;
        }

        if ($backup->isTerminal()) {
            Log::warning('Backup job skipped: already terminal', [
                'backup_id' => $this->backupId,
                'status' => $backup->status,
            ]);

            return;
        }

        $manager->run($backup);
    }

    public function failed(?Throwable $e): void
    {
        $backup = Backup::query()->find($this->backupId);

        if ($backup && $backup->status !== Backup::STATUS_COMPLETED) {
            $backup->forceFill([
                'status' => Backup::STATUS_FAILED,
                'stage' => 'job_failed',
                'finished_at' => now(),
                'error_message' => $e?->getMessage() ?? 'Job failed without exception.',
            ])->save();
        }

        Log::error('Backup job failed', [
            'backup_id' => $this->backupId,
            'error' => $e?->getMessage(),
        ]);
    }
}