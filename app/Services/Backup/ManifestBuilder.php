<?php

namespace App\Services\Backup;

use App\Models\Backup;

/**
 * Builds the non-secret manifest.json artifact. This file is safe to share —
 * it deliberately contains no secrets, no real paths and no credentials.
 */
class ManifestBuilder
{
    public function build(Backup $backup, array $systemInfo, array $checksums): array
    {
        $files = $checksums['files'] ?? [];
        $artifactList = array_keys($files);

        $manifest = [
            'format' => 'shahead-lms-backup',
            'version' => 1,
            'backup_id' => $backup->backup_id,
            'created_at' => $backup->created_at?->toIso8601String(),
            'started_at' => $backup->started_at?->toIso8601String(),
            'finished_at' => $backup->finished_at?->toIso8601String(),
            'application_version' => $systemInfo['app_version'] ?? null,
            'git_commit' => $systemInfo['git_commit'] ?? null,
            'laravel_version' => $systemInfo['laravel_version'] ?? null,
            'php_version' => $systemInfo['php_version'] ?? null,
            'node_version' => $systemInfo['node_version'] ?? null,
            'database_engine' => $systemInfo['database_engine'] ?? 'pgsql',
            'database_version' => $systemInfo['database_version'] ?? null,
            'backup_size_bytes' => $backup->size_bytes,
            'database_size_bytes' => $backup->database_size_bytes,
            'file_count' => $backup->file_count,
            'artifacts' => $artifactList,
            'checksum_algorithm' => $checksums['algorithm'] ?? 'sha256',
            'checksum' => $checksums['package_sha256'] ?? null,
            'status' => $backup->status,
            'reason' => $backup->reason,
            'environment' => $systemInfo['app_env'] ?? null,
        ];

        return $manifest;
    }

    public function write(string $path, array $manifest): void
    {
        file_put_contents(
            $path,
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
        );
    }
}