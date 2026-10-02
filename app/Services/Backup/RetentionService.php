<?php

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Retention policy. The admin-editable count lives in platform_settings
 * (backup_retention_count); the code only provides defaults from config.
 */
class RetentionService
{
    public function prune(?string $actingUserId = null): array
    {
        $disk = Storage::disk(config('backup.disk'));
        $pruned = [];

        // 1. Keep the latest N COMPLETED backups.
        $keep = (int) PlatformSetting::get('backup_retention_count', config('backup.retention.default', 7));
        if ($keep < 1) {
            $keep = 1;
        }

        $completed = Backup::query()
            ->where('status', Backup::STATUS_COMPLETED)
            ->orderByDesc('created_at')
            ->get();

        foreach ($completed->slice($keep)->values() as $backup) {
            $this->deleteBackup($backup, $disk, 'retention');
            $pruned[] = $backup->backup_id;
        }

        // 2. Failed/aborted backups older than the failed-retention window.
        $failedDays = (int) config('backup.retention.failed_retention_days', 3);
        $staleFailed = Backup::query()
            ->whereIn('status', [Backup::STATUS_FAILED, Backup::STATUS_QUEUED])
            ->where('created_at', '<', now()->subDays($failedDays))
            ->get();

        foreach ($staleFailed as $backup) {
            $this->deleteBackup($backup, $disk, 'retention');
            $pruned[] = $backup->backup_id;
        }

        if ($pruned !== []) {
            Log::info('Backup retention pruned', ['pruned' => $pruned, 'keep' => $keep]);
        }

        return $pruned;
    }

    public function deleteBackup(Backup $backup, $disk, string $reason = 'manual'): void
    {
        if ($disk->directoryExists($backup->directoryPath())) {
            $disk->deleteDirectory($backup->directoryPath());
        }

        $backup->forceFill([
            'status' => Backup::STATUS_DELETED,
            'deleted_at' => now(),
        ])->save();

        Log::info('Backup deleted', ['backup_id' => $backup->backup_id, 'reason' => $reason]);
    }
}