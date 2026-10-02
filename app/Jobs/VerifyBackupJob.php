<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\Backup\Verifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class VerifyBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public function __construct(public readonly string $backupId)
    {
        $this->onQueue(config('backup.queue', 'backups'));
        $this->timeout = (int) config('backup.timeout', 7200);
    }

    public function handle(Verifier $verifier): void
    {
        $backup = Backup::query()->find($this->backupId);

        if (! $backup || $backup->status === Backup::STATUS_DELETED) {
            return;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk(config('backup.disk'));

        if (! $backup->packageExists()) {
            $backup->forceFill([
                'status' => Backup::STATUS_FAILED,
                'stage' => 'verification_failed',
                'error_message' => 'Package directory is missing on disk.',
            ])->save();

            return;
        }

        $backup->forceFill([
            'status' => Backup::STATUS_VERIFYING,
            'stage' => 'verification',
        ])->save();

        $staging = storage_path('app/backups/.staging/'.$backup->backup_id.'-verify');
        if (! is_dir($staging)) {
            mkdir($staging, 0775, true);
        }

        try {
            // Stage the package locally so verification runs against real
            // files regardless of the storage driver (local/S3/object).
            foreach ($backup->artifactFilenames() as $name) {
                $stream = $disk->readStream($backup->directoryPath().'/'.$name);
                if ($stream !== false) {
                    file_put_contents($staging.'/'.$name, $stream);
                    fclose($stream);
                }
            }

            $report = $verifier->verify($backup, $staging);
        } finally {
            $this->removeDir($staging);
        }

        $backup->forceFill([
            'status' => $report['ok'] ? Backup::STATUS_COMPLETED : Backup::STATUS_FAILED,
            'stage' => $report['ok'] ? 'completed' : 'verification_failed',
            'verification' => $report,
            'verified_at' => $report['ok'] ? now() : null,
            'error_message' => $report['ok'] ? null : implode('; ', array_slice($report['errors'], 0, 5)),
        ])->save();

        Log::info('Backup re-verification finished', [
            'backup_id' => $backup->backup_id,
            'ok' => $report['ok'],
        ]);
    }

    protected function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }

    public function failed(?Throwable $e): void
    {
        $backup = Backup::query()->find($this->backupId);

        if ($backup) {
            $backup->forceFill([
                'status' => Backup::STATUS_FAILED,
                'stage' => 'verification_failed',
                'error_message' => $e?->getMessage(),
            ])->save();
        }

        Log::error('Backup re-verification job failed', [
            'backup_id' => $this->backupId,
            'error' => $e?->getMessage(),
        ]);
    }
}