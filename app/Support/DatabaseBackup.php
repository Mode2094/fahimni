<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class DatabaseBackup
{
    public static function create(): string
    {
        $connection = config('database.connections.pgsql');
        $filename = 'backup-'.now()->format('Y-m-d_H-i-s').'.dump';
        $target = Storage::disk('local')->path('backups/'.$filename);

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }

        $result = Process::timeout(3600)
            ->path(base_path())
            ->env([
                // Postgres password auth is required non-interactively.
                'PGPASSWORD' => (string) ($connection['password'] ?? ''),
                'PGSSLMODE' => (string) ($connection['sslmode'] ?? 'prefer'),
            ])
            ->run([
                'pg_dump',
                '-U', $connection['username'],
                '-h', $connection['host'],
                '-p', (string) $connection['port'],
                '-d', $connection['database'],
                '-Fc',
                '-f', $target,
            ], function (string $type, string $output): void {
                if ($type === 'err') {
                    Log::error('pg_dump stderr: '.trim($output));
                }
            });

        if ($result->exitCode() !== 0 || ! file_exists($target)) {
            @unlink($target);
            throw new \RuntimeException('pg_dump failed: '.trim($result->errorOutput()));
        }

        $gzip = Process::timeout(600)->run(['gzip', '-f', $target]);
        if ($gzip->exitCode() !== 0 || ! file_exists($target.'.gz')) {
            throw new \RuntimeException('Backup compression failed: '.trim($gzip->errorOutput()));
        }

        return $filename;
    }

    public static function list(): array
    {
        $files = Storage::disk('local')->files('backups');

        return collect($files)
            ->filter(fn ($f) => str_ends_with($f, '.sql') || str_ends_with($f, '.dump.gz'))
            ->map(function ($f) {
                $size = Storage::disk('local')->size($f);

                return [
                    'name' => basename($f),
                    'size' => self::humanSize($size),
                    'size_bytes' => $size,
                    'created_at' => date('Y-m-d H:i:s', Storage::disk('local')->lastModified($f)),
                ];
            })
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    public static function deleteOld(int $keep = 7): void
    {
        $files = collect(Storage::disk('local')->files('backups'))
            ->filter(fn ($f) => str_ends_with($f, '.sql') || str_ends_with($f, '.dump.gz'))
            ->sort();
        $excess = $files->count() - $keep;
        if ($excess <= 0) {
            return;
        }
        foreach ($files->take($excess) as $f) {
            Storage::disk('local')->delete($f);
        }
    }

    /**
     * Off-site sync of the latest backup via rclone (S3/Google Drive/etc).
     * Configure BACKUP_RCLONE_REMOTE=remote:path and BACKUP_RCLONE_PATH in
     * .env; the daily scheduler calls this right after the local dump.
     *
     * @return bool true when synced (or when remote sync is not configured)
     */
    public static function syncOffsite(): bool
    {
        $remote = (string) config('services.backup.remote', '');

        if ($remote === '') {
            return true; // not configured — nothing to do
        }

        $path = (string) config('services.backup.path', 'backups');

        $files = collect(Storage::disk('local')->files($path))
            ->filter(fn ($f) => str_ends_with($f, '.sql') || str_ends_with($f, '.dump.gz'))
            ->sort()
            ->take(-5); // keep the last 5 backups off-site

        if ($files->isEmpty()) {
            return false;
        }

        foreach ($files as $file) {
            $result = Process::timeout(1800)
                ->path(base_path())
                ->run([
                    'rclone', 'copy',
                    Storage::disk('local')->path($file),
                    rtrim($remote, '/').'/'.dirname($file),
                ]);

            if ($result->exitCode() !== 0) {
                Log::error('Off-site backup sync failed: '.trim($result->errorOutput()));

                return false;
            }
        }

        Log::info('Off-site backup sync completed', ['files' => $files->count()]);

        return true;
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }
}
