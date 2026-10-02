<?php

namespace App\Services\Backup;

use App\Models\Backup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Orchestrates a full backup pipeline:
 *   queued → running → verifying → completed | failed
 *
 * Every heavy step streams to disk (pg_dump / tar / openssl) so memory stays
 * flat regardless of database or file volume. The database is only read; no
 * destructive SQL is ever executed.
 */
class BackupManager
{
    public function __construct(
        protected DatabaseDumper $dumper,
        protected FilesArchiver $archiver,
        protected ConfigurationEncryptor $configEncryptor,
        protected ManifestBuilder $manifestBuilder,
        protected ChecksumService $checksumService,
        protected Verifier $verifier,
        protected SystemInfoCollector $systemInfo,
        protected RetentionService $retention,
    ) {
    }

    /**
     * Create the Backup record in QUEUED state. No heavy work here.
     */
    public function createRecord(string $reason = 'manual', ?string $userId = null): Backup
    {
        $backupId = sprintf(
            '%s_%s_%s',
            config('backup.name_prefix', 'SHAHEAD_BACKUP'),
            now()->format('Y-m-d_His'),
            strtolower(Str::random(6))
        );

        $backup = Backup::create([
            'backup_id' => $backupId,
            'status' => Backup::STATUS_QUEUED,
            'stage' => 'queued',
            'progress' => 0,
            'reason' => $reason,
            'created_by' => $userId,
        ]);

        Log::info('Backup queued', ['backup_id' => $backupId, 'reason' => $reason]);

        return $backup;
    }

    /**
     * Run the full pipeline. Throws RuntimeException on hard failure; the
     * caller (job) is responsible for marking FAILED and cleaning up.
     *
     * @throws RuntimeException
     */
    public function run(Backup $backup): void
    {
        // Heavy steps (pg_dump, tar, openssl) always run against a LOCAL
        // staging directory — the configured backup disk may be any driver
        // (local VPS disk today, S3/object storage later). The finished,
        // verified package is then persisted through the Storage abstraction.
        $disk = Storage::disk(config('backup.disk'));
        $staging = storage_path('app/backups/.staging/'.$backup->backup_id);
        $published = false;

        if (! is_dir($staging)) {
            mkdir($staging, 0775, true);
        }

        $startedAt = microtime(true);
        $backup->forceFill([
            'status' => Backup::STATUS_RUNNING,
            'stage' => 'starting',
            'started_at' => now(),
            'error_message' => null,
        ])->save();

        try {
            // ── 1. Database dump ────────────────────────────────────────
            $this->progress($backup, 'database_dump', 10);
            Log::info('Backup stage: database dump started', ['backup_id' => $backup->backup_id]);

            $tables = $this->dumper->listTables();
            if (empty($tables)) {
                throw new RuntimeException('No tables discovered in the database — aborting to avoid an empty dump.');
            }

            $dumpName = 'database.dump';
            $this->dumper->dump($staging.'/'.$dumpName, $tables);

            $backup->forceFill([
                'database_dump' => $dumpName,
                'tables' => $tables,
                'database_size_bytes' => $this->dumper->databaseSizeBytes(),
            ])->save();

            Log::info('Backup stage: database dump completed', [
                'backup_id' => $backup->backup_id,
                'tables' => count($tables),
                'dump_bytes' => filesize($staging.'/'.$dumpName),
            ]);

            // ── 2. Files archive ───────────────────────────────────────
            $this->progress($backup, 'files_archive', 45);
            Log::info('Backup stage: files archive started', ['backup_id' => $backup->backup_id]);

            $scan = $this->archiver->scan();
            $archiveName = 'files.tar.gz';
            $base = (string) config('backup.files.base', storage_path('app'));
            $archived = $this->archiver->archive($staging.'/'.$archiveName, $base, $scan['paths']);

            $backup->forceFill([
                'files_archive' => $archiveName,
                'file_count' => $archived,
                'file_list' => $scan['paths'],
            ])->save();

            Log::info('Backup stage: files archive completed', [
                'backup_id' => $backup->backup_id,
                'files' => $archived,
                'archive_bytes' => filesize($staging.'/'.$archiveName),
            ]);

            // ── 3. Encrypted configuration ─────────────────────────────
            $this->progress($backup, 'configuration', 75);
            Log::info('Backup stage: configuration encryption started', ['backup_id' => $backup->backup_id]);

            $configName = 'configuration.enc';
            $encrypted = $this->configEncryptor->build();
            file_put_contents($staging.'/'.$configName, $encrypted);
            $backup->forceFill(['config_archive' => $configName])->save();

            Log::info('Backup stage: configuration encryption completed', ['backup_id' => $backup->backup_id]);

            // ── 4. System info + manifest ──────────────────────────────
            $this->progress($backup, 'manifest', 85);

            $systemInfo = $this->systemInfo->collect();
            $systemInfoName = 'system-info.json';
            file_put_contents(
                $staging.'/'.$systemInfoName,
                json_encode($systemInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
            );
            $backup->forceFill(['system_info' => $systemInfoName])->save();

            // ── 5. Checksums ───────────────────────────────────────────
            $this->progress($backup, 'checksums', 90);

            $checksumInput = [
                $staging.'/'.$dumpName => $dumpName,
                $staging.'/'.$archiveName => $archiveName,
                $staging.'/'.$configName => $configName,
                $staging.'/'.$systemInfoName => $systemInfoName,
            ];
            $checksums = $this->checksumService->compute($checksumInput);
            $checksumsName = 'checksums.json';
            $this->checksumService->write($staging.'/'.$checksumsName, $checksums);
            $backup->forceFill([
                'checksums' => $checksumsName,
                'checksums_data' => $checksums,
            ])->save();

            // Manifest last — it embeds the package hash.
            $manifestName = 'manifest.json';
            $manifest = $this->manifestBuilder->build($backup, $systemInfo, $checksums);
            $this->manifestBuilder->write($staging.'/'.$manifestName, $manifest);
            $backup->forceFill([
                'manifest' => $manifestName,
                'manifest_data' => $manifest,
            ])->save();

            // ── 6. Verification (on the local staging package) ─────────
            $this->progress($backup, 'verification', 95);
            $backup->forceFill(['status' => Backup::STATUS_VERIFYING])->save();
            Log::info('Backup stage: verification started', ['backup_id' => $backup->backup_id]);

            $report = $this->verifier->verify($backup, $staging);

            if (! $report['ok']) {
                $this->fail($backup, 'verification_failed', 95, $report['errors'][0] ?? 'Verification failed.', $startedAt, $report);
                $this->removeDir($staging);

                return;
            }

            // ── 7. Persist to the configured disk ──────────────────────
            foreach ($backup->artifactFilenames() as $name) {
                $this->persistFile($disk, $backup->backup_id.'/'.$name, $staging.'/'.$name);
            }

            // Fresh manifest (final status) is persisted last.
            $finalManifest = $this->manifestBuilder->build($backup->fresh(), $systemInfo, $checksums);
            $this->manifestBuilder->write($staging.'/'.$manifestName, $finalManifest);
            $this->persistFile($disk, $backup->backup_id.'/'.$manifestName, $staging.'/'.$manifestName);
            $backup->forceFill(['manifest_data' => $finalManifest])->save();

            $published = true;

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            $backup->forceFill([
                'status' => Backup::STATUS_COMPLETED,
                'stage' => 'completed',
                'progress' => 100,
                'verification' => $report,
                'verified_at' => now(),
                'finished_at' => now(),
                'duration_ms' => $durationMs,
                'size_bytes' => $backup->packageSizeBytes(),
                'error_message' => null,
            ])->save();

            Log::info('Backup completed', [
                'backup_id' => $backup->backup_id,
                'duration_ms' => $durationMs,
                'size_bytes' => $backup->size_bytes,
                'files' => $backup->file_count,
                'tables' => count($tables),
            ]);

            $this->removeDir($staging);

            // Off-site sync (rclone) is optional and only runs when
            // BACKUP_RCLONE_REMOTE is configured. Never blocks completion.
            try {
                $this->syncOffsite($backup);
            } catch (\Throwable $e) {
                Log::warning('Off-site backup sync failed', [
                    'backup_id' => $backup->backup_id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Retention runs only for successful backups.
            $this->retention->prune($backup->created_by);
        } catch (\Throwable $e) {
            $this->removeDir($staging);

            if ($published) {
                // Package is safe on disk but something else failed (e.g.
                // retention) — do not hide a usable backup behind a failure.
                throw $e;
            }

            $this->fail($backup, 'failed', $backup->progress, $e->getMessage(), $startedAt, null);

            throw $e;
        }
    }

    protected function fail(Backup $backup, string $stage, int $progress, string $error, float $startedAt, ?array $report): void
    {
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $backup->forceFill([
            'status' => Backup::STATUS_FAILED,
            'stage' => $stage,
            'progress' => $progress,
            'verification' => $report,
            'finished_at' => now(),
            'duration_ms' => $durationMs,
            'error_message' => $error,
        ])->save();

        Log::error('Backup failed', [
            'backup_id' => $backup->backup_id,
            'stage' => $stage,
            'error' => $error,
        ]);
    }

    protected function persistFile($disk, string $remotePath, string $localPath): void
    {
        $handle = fopen($localPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot read staged artifact: '.$localPath);
        }

        $disk->put($remotePath, $handle);

        if (is_resource($handle)) {
            fclose($handle);
        }
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
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    protected function syncOffsite(Backup $backup): void
    {
        $remote = (string) config('services.backup.remote', '');

        if ($remote === '') {
            return; // not configured — nothing to do
        }

        $disk = Storage::disk(config('backup.disk'));

        foreach ($backup->artifactFilenames() as $name) {
            $result = \Illuminate\Support\Facades\Process::timeout(1800)
                ->path(base_path())
                ->run([
                    'rclone', 'copy',
                    $disk->path($backup->directoryPath().'/'.$name),
                    rtrim($remote, '/').'/'.$backup->backup_id,
                ]);

            if ($result->exitCode() !== 0) {
                throw new RuntimeException('Off-site sync failed: '.trim($result->errorOutput()));
            }
        }

        Log::info('Off-site backup sync completed', ['backup_id' => $backup->backup_id]);
    }

    protected function progress(Backup $backup, string $stage, int $progress): void
    {
        $backup->forceFill(['stage' => $stage, 'progress' => $progress])->save();
    }
}