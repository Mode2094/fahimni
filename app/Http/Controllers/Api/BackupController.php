<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CreateBackupJob;
use App\Jobs\VerifyBackupJob;
use App\Models\Backup;
use App\Models\PlatformSetting;
use App\Services\Backup\BackupManager;
use App\Services\Backup\RetentionService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class BackupController extends Controller
{
    public function __construct(
        protected BackupManager $manager,
        protected RetentionService $retention,
    ) {
    }

    // ─── Listing ────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Backup::class);

        $query = Backup::query()->with('creator:id,name,email');

        if (! $request->boolean('include_deleted')) {
            $query->notDeleted();
        }

        $backups = $query->orderByDesc('created_at')->limit(100)->get();

        return response()->json([
            'backups' => $backups->map(fn (Backup $b) => $this->payload($b)),
            'latest' => $backups->first() ? $this->payload($backups->first()) : null,
        ]);
    }

    public function show(Request $request, Backup $backup): JsonResponse
    {
        $this->authorize('view', $backup);

        return response()->json(['backup' => $this->payload($backup, full: true)]);
    }

    // ─── Create ─────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Backup::class);

        // One active pipeline at a time — avoids two heavy dumps racing and
        // saturating the same disk.
        $active = Backup::query()
            ->whereIn('status', [Backup::STATUS_QUEUED, Backup::STATUS_RUNNING, Backup::STATUS_VERIFYING])
            ->exists();

        if ($active) {
            return response()->json([
                'message' => 'A backup is already in progress. Wait for it to finish before starting another.',
            ], 409);
        }

        $backup = $this->manager->createRecord('manual', $request->user()?->id);
        CreateBackupJob::dispatch($backup->id);

        AuditLogger::log($request, 'backup.created', 'backup', $backup->backup_id, [
            'status' => $backup->status,
            'reason' => 'manual',
        ]);

        return response()->json(['backup' => $this->payload($backup)], 202);
    }

    // ─── Verify ─────────────────────────────────────────────────

    public function verify(Request $request, Backup $backup): JsonResponse
    {
        $this->authorize('verify', $backup);

        if (! $backup->packageExists()) {
            return response()->json(['message' => 'Backup package is missing on disk.'], 422);
        }

        $backup->forceFill(['status' => Backup::STATUS_VERIFYING, 'stage' => 'queued_for_verification'])->save();
        VerifyBackupJob::dispatch($backup->id);

        AuditLogger::log($request, 'backup.verify_requested', 'backup', $backup->backup_id, [
            'status' => $backup->status,
        ]);

        return response()->json(['backup' => $this->payload($backup)], 202);
    }

    // ─── Download ───────────────────────────────────────────────

    public function download(Request $request, Backup $backup, ?string $artifact = null)
    {
        $this->authorize('download', $backup);

        AuditLogger::log($request, 'backup.downloaded', 'backup', $backup->backup_id, ['artifact' => $artifact ?? 'package']);

        return $this->streamArtifact($backup, $artifact);
    }

    /**
     * Public signed download link. The signature is HMAC-SHA256 over
     * "{backupId}|{artifact}|{expires}" with the app key — no secrets in the
     * URL and the link expires after the configured TTL. The signature itself
     * is the authorization: only a super admin can mint these links.
     */
    public function signedDownload(Request $request, string $backupId, ?string $artifact = null)
    {
        $backup = Backup::query()->where('backup_id', $backupId)->firstOrFail();

        if ($backup->status !== Backup::STATUS_COMPLETED) {
            return response()->json(['message' => 'Backup is not downloadable.'], 403);
        }

        $artifact = $artifact ?? (string) $request->query('artifact', 'package');

        $expires = (int) $request->query('expires');
        $sig = (string) $request->query('sig');

        if ($expires < now()->timestamp) {
            return response()->json(['message' => 'Download link expired.'], 403);
        }

        $expected = $backup->signedDownloadToken($artifact, $expires);
        if (! hash_equals($expected, $sig)) {
            return response()->json(['message' => 'Invalid download signature.'], 403);
        }

        AuditLogger::log($request, 'backup.downloaded', 'backup', $backup->backup_id, [
            'artifact' => $artifact,
            'via' => 'signed_link',
        ]);

        return $this->streamArtifact($backup, $artifact === 'package' ? null : $artifact);
    }

    protected function streamArtifact(Backup $backup, ?string $artifact = null)
    {
        if (! $backup->packageExists()) {
            return response()->json(['message' => 'Backup package is missing on disk.'], 404);
        }

        $disk = Storage::disk(config('backup.disk'));

        // Whole-package download: stream a fresh tar.gz on the fly (no extra
        // on-disk copy, constant memory) and let the caller unpack it.
        if ($artifact === null || $artifact === 'package') {
            // Local disks can stream a tar directly from the filesystem; any
            // other driver streams each artifact through Storage instead.
            if (method_exists($disk, 'path')) {
                return $this->streamTar($disk->path($backup->directoryPath()), $backup->backup_id.'.tar.gz');
            }

            return $this->streamRemotePackage($backup);
        }

        $allowed = $backup->artifactFilenames();
        if (! in_array($artifact, $allowed, true)) {
            return response()->json(['message' => 'Unknown artifact.'], 422);
        }

        $remotePath = $backup->directoryPath().'/'.$artifact;

        if (! $disk->exists($remotePath)) {
            return response()->json(['message' => 'Artifact not found.'], 404);
        }

        return response()->streamDownload(function () use ($disk, $remotePath): void {
            $stream = $disk->readStream($remotePath);
            if ($stream === false) {
                return;
            }
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
            }
            fclose($stream);
        }, $artifact, ['Content-Type' => $this->contentType($artifact)]);
    }

    /**
     * Package download for non-local (S3/object-storage) disks: pull each
     * artifact through Storage into a local temp tarball, then stream it.
     */
    protected function streamRemotePackage(Backup $backup)
    {
        $tmpDir = sys_get_temp_dir().'/backup-pkg-'.uniqid();
        mkdir($tmpDir, 0775, true);

        try {
            $disk = Storage::disk(config('backup.disk'));

            foreach ($backup->artifactFilenames() as $name) {
                $local = $tmpDir.'/'.$name;
                $stream = $disk->readStream($backup->directoryPath().'/'.$name);
                if ($stream !== false) {
                    file_put_contents($local, $stream);
                    fclose($stream);
                }
            }

            $tar = $tmpDir.'/package.tar.gz';
            $process = new Process(['tar', '-czf', $tar, '-C', $tmpDir, '.']);
            $process->setTimeout(3600);
            $process->run();

            if ($process->getExitCode() !== 0) {
                return response()->json(['message' => 'Could not assemble package.'], 500);
            }

            return response()->download($tar, $backup->backup_id.'.tar.gz', ['Content-Type' => 'application/gzip'])
                ->deleteFileAfterSend(true);
        } finally {
            $this->removeLocalDir($tmpDir);
        }
    }

    protected function removeLocalDir(string $dir): void
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

    public function signedDownloadUrl(Backup $backup, ?string $artifact = null): string
    {
        $expires = now()->addSeconds((int) config('backup.download.ttl_seconds', 3600))->timestamp;

        return url('/api/v1/admin/backups/'.$backup->backup_id.'/signed-download')
            .'?artifact='.($artifact ?? 'package')
            .'&expires='.$expires
            .'&sig='.$backup->signedDownloadToken($artifact ?? 'package', $expires);
    }

    // ─── Delete ─────────────────────────────────────────────────

    public function destroy(Request $request, Backup $backup): JsonResponse
    {
        $this->authorize('delete', $backup);

        $this->retention->deleteBackup($backup, Storage::disk(config('backup.disk')), 'manual');

        AuditLogger::log($request, 'backup.deleted', 'backup', $backup->backup_id, [
            'status' => $backup->status,
            'size_bytes' => $backup->size_bytes,
        ]);

        return response()->json(['message' => 'Backup deleted.']);
    }

    // ─── Settings ───────────────────────────────────────────────

    public function settings(): JsonResponse
    {
        $this->authorize('manageSettings', Backup::class);

        return response()->json(['settings' => $this->settingsPayload()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->authorize('manageSettings', Backup::class);

        $validated = $request->validate([
            'automatic_enabled' => 'sometimes|boolean',
            'frequency' => 'sometimes|in:daily,weekly',
            'time' => 'sometimes|date_format:H:i',
            'weekly_day' => 'sometimes|integer|between:0,6',
            'retention_count' => 'sometimes|integer|min:1|max:365',
        ]);

        foreach ([
            'automatic_enabled' => 'backup_automatic_enabled',
            'frequency' => 'backup_frequency',
            'time' => 'backup_time',
            'weekly_day' => 'backup_weekly_day',
            'retention_count' => 'backup_retention_count',
        ] as $field => $settingKey) {
            if (array_key_exists($field, $validated)) {
                PlatformSetting::set($settingKey, $validated[$field]);
            }
        }

        AuditLogger::log($request, 'backup.settings_updated', null, null, [
            'settings' => array_map('strval', $validated),
        ]);

        return response()->json(['settings' => $this->settingsPayload()]);
    }

    // ─── Helpers ────────────────────────────────────────────────

    protected function settingsPayload(): array
    {
        return [
            'automatic_enabled' => PlatformSetting::bool('backup_automatic_enabled', (bool) config('backup.schedule.enabled')),
            'frequency' => (string) PlatformSetting::get('backup_frequency', config('backup.schedule.frequency')),
            'time' => (string) PlatformSetting::get('backup_time', config('backup.schedule.time')),
            'weekly_day' => (int) PlatformSetting::get('backup_weekly_day', config('backup.schedule.weekly_day')),
            'retention_count' => (int) PlatformSetting::get('backup_retention_count', config('backup.retention.default')),
            'storage' => [
                'disk' => (string) config('backup.disk'),
                'reachable' => $this->storageReachable(),
                'free_bytes' => $this->freeBytes(),
            ],
        ];
    }

    protected function storageReachable(): bool
    {
        try {
            $disk = Storage::disk(config('backup.disk'));

            return $disk->exists('.backup-write-test') || $disk->put('.backup-write-test', 'ok');
        } catch (Throwable) {
            return false;
        }
    }

    protected function freeBytes(): ?int
    {
        try {
            $path = Storage::disk(config('backup.disk'))->path('');
            $free = disk_free_space($path);

            return $free === false ? null : (int) $free;
        } catch (Throwable) {
            return null;
        }
    }

    protected function payload(Backup $b, bool $full = false): array
    {
        $data = [
            'id' => $b->id,
            'backup_id' => $b->backup_id,
            'status' => $b->status,
            'stage' => $b->stage,
            'progress' => $b->progress,
            'reason' => $b->reason,
            'created_by' => $b->creator ? ['id' => $b->creator->id, 'name' => $b->creator->name, 'email' => $b->creator->email] : null,
            'size' => $b->humanSize(),
            'size_bytes' => $b->size_bytes,
            'database_size' => Backup::human($b->database_size_bytes),
            'database_size_bytes' => $b->database_size_bytes,
            'file_count' => $b->file_count,
            'duration_ms' => $b->duration_ms,
            'tables_count' => is_array($b->tables) ? count($b->tables) : null,
            'checksum' => $b->checksums_data['package_sha256'] ?? null,
            'created_at' => $b->created_at?->toIso8601String(),
            'started_at' => $b->started_at?->toIso8601String(),
            'finished_at' => $b->finished_at?->toIso8601String(),
            'verified_at' => $b->verified_at?->toIso8601String(),
            'error_message' => $b->status === Backup::STATUS_FAILED ? $this->sanitizeError($b->error_message) : null,
            'artifacts' => $b->artifactFilenames(),
        ];

        if ($full) {
            $data['manifest'] = $b->manifest_data;
            $data['checksums'] = $b->checksums_data;
            $data['verification'] = $b->verification;
            $data['app_version'] = $b->manifest_data['application_version'] ?? null;
            $data['laravel_version'] = $b->manifest_data['laravel_version'] ?? null;
            $data['database_version'] = $b->manifest_data['database_version'] ?? null;
            $data['download_url'] = $this->signedDownloadUrl($b);
        }

        return $data;
    }

    /**
     * Failure messages may contain command stderr; strip anything that looks
     * like credentials before it can reach a client.
     */
    protected function sanitizeError(?string $error): ?string
    {
        if ($error === null || $error === '') {
            return null;
        }

        $sanitized = preg_replace(
            '/(password|passwd|secret|token|api[_-]?key)\s*[:=]\s*\S+/i',
            '$1: [REDACTED]',
            $error
        );

        return Str::limit($sanitized ?? $error, 500);
    }

    protected function contentType(string $artifact): string
    {
        return match (true) {
            str_ends_with($artifact, '.dump') => 'application/octet-stream',
            str_ends_with($artifact, '.tar.gz') => 'application/gzip',
            str_ends_with($artifact, '.enc') => 'application/octet-stream',
            str_ends_with($artifact, '.json') => 'application/json',
            default => 'application/octet-stream',
        };
    }

    /**
     * Stream a directory as a gzipped tarball without creating an extra copy
     * on disk and without loading it into memory.
     */
    protected function streamTar(string $dir, string $downloadName)
    {
        return response()->stream(function () use ($dir): void {
            $process = new Process(['tar', '-czf', '-', '-C', $dir, '.']);
            $process->setTimeout(3600);
            $process->run(function ($type, $buffer): void {
                if ($type === Process::OUT) {
                    echo $buffer;
                    flush();
                }
            });
        }, 200, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="'.$downloadName.'"',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}