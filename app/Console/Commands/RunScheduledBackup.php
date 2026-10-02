<?php

namespace App\Console\Commands;

use App\Jobs\CreateBackupJob;
use App\Models\Backup;
use App\Models\PlatformSetting;
use App\Services\Backup\BackupManager;
use Illuminate\Console\Command;

class RunScheduledBackup extends Command
{
    protected $signature = 'backup:run-scheduled';

    protected $description = 'Runs an automatic backup when the admin-configured schedule is due.';

    public function handle(BackupManager $manager): int
    {
        // Automatic backups are opt-in. Nothing runs until an admin enables
        // them from the UI (platform_settings backup_automatic_enabled).
        $enabled = PlatformSetting::bool('backup_automatic_enabled', (bool) config('backup.schedule.enabled'));

        if (! $enabled) {
            $this->line('Automatic backup is disabled. Enable it from the admin panel.');

            return self::SUCCESS;
        }

        if (! $this->isDue()) {
            return self::SUCCESS;
        }

        // Guard: skip if a manual/scheduled backup is already in flight.
        $active = Backup::query()
            ->whereIn('status', [Backup::STATUS_QUEUED, Backup::STATUS_RUNNING, Backup::STATUS_VERIFYING])
            ->exists();

        if ($active) {
            $this->line('A backup is already in progress — skipping scheduled run.');

            return self::SUCCESS;
        }

        $backup = $manager->createRecord('scheduled', null);
        CreateBackupJob::dispatch($backup->id);

        $this->info("Scheduled backup dispatched: {$backup->backup_id}");

        return self::SUCCESS;
    }

    protected function isDue(): bool
    {
        $frequency = (string) PlatformSetting::get('backup_frequency', config('backup.schedule.frequency'));
        $time = (string) PlatformSetting::get('backup_time', config('backup.schedule.time'));
        $weeklyDay = (int) PlatformSetting::get('backup_weekly_day', config('backup.schedule.weekly_day'));

        $now = now();

        if ($frequency === 'weekly' && $now->dayOfWeek !== $weeklyDay) {
            return false;
        }

        return $now->format('H:i') === $time;
    }
}