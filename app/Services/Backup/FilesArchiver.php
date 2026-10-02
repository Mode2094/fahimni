<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Local files archive step. Discovers the real storage locations instead of
 * assuming paths: always includes storage/app/public and storage/app/private,
 * plus any extra roots configured via BACKUP_EXTRA_PATHS (e.g. an uploads/
 * directory). The backup store itself, caches and logs are always excluded.
 *
 * Bunny Stream videos are deliberately NOT downloaded — their references
 * live in the database dump and are documented in configuration.enc.
 */
class FilesArchiver
{
    /**
     * Discover the files that belong in the archive: relative paths from
     * the archive base + total byte count.
     *
     * @return array{paths: string[], total_bytes: int}
     */
    public function scan(): array
    {
        $base = (string) config('backup.files.base', storage_path('app'));
        $always = (array) config('backup.files.always_include', ['public', 'private']);
        $excludes = (array) config('backup.files.exclude_patterns', ['backups', 'cache', 'logs', '*.tmp']);

        $paths = [];
        $totalBytes = 0;

        $collect = function (string $root, string $prefix) use (&$paths, &$totalBytes, $excludes): void {
            if (! is_dir($root)) {
                return;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || ! $file->isReadable()) {
                    continue;
                }

                $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');

                if ($this->isExcluded($relative, $excludes)) {
                    continue;
                }

                $paths[] = ltrim($prefix.'/'.$relative, '/');
                $totalBytes += $file->getSize();
            }
        };

        foreach ($always as $dir) {
            $collect($base.'/'.$dir, $dir);
        }

        foreach ((array) config('backup.files.extra_paths', []) as $extra) {
            $absolute = str_starts_with($extra, '/') ? $extra : base_path($extra);
            $prefix = ltrim(str_replace('\\', '/', $extra), '/');
            $collect($absolute, $prefix);
        }

        // Sort for deterministic archives/checksums.
        sort($paths);

        return ['paths' => $paths, 'total_bytes' => $totalBytes];
    }

    protected function isExcluded(string $relative, array $patterns): bool
    {
        $normalized = trim($relative, '/');
        $segments = explode('/', $normalized);
        $first = $segments[0] ?? '';

        foreach ($patterns as $pattern) {
            $pattern = trim($pattern, '/');
            if ($pattern === '') {
                continue;
            }

            if (str_starts_with($pattern, '*')) {
                if (fnmatch(ltrim($pattern, '*'), $normalized) || fnmatch(ltrim($pattern, '*'), basename($normalized))) {
                    return true;
                }
            } elseif (in_array($pattern, $segments, true) || str_starts_with($normalized, $pattern.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a gzip tarball of $paths (relative to $base) at $targetPath,
     * streaming through the OS tools. Returns the number of archived files.
     *
     * @throws RuntimeException on failure
     */
    public function archive(string $targetPath, string $base, array $paths): int
    {
        if (empty($paths)) {
            // Empty archives are valid (nothing to back up) but we still emit
            // a valid tar.gz so the package is structurally complete.
            $result = Process::timeout(120)->run(['tar', '-czf', $targetPath, '--files-from', '/dev/null']);
            if ($result->exitCode() !== 0) {
                throw new RuntimeException('Failed to create empty archive: '.trim($result->errorOutput()));
            }

            return 0;
        }

        if (! is_dir($base)) {
            throw new RuntimeException('Archive base directory does not exist: '.$base);
        }

        // Paths may be deep; strip the leading ./ so entries are deterministic.
        $relativePaths = array_map(
            fn ($p) => './'.ltrim($p, '/'),
            $paths
        );

        $timeout = (int) config('backup.process_timeouts.files_archive', 3600);

        // Use a temp manifest file for the file list so argv length never
        // becomes a limit on huge trees.
        $manifestFile = tempnam(sys_get_temp_dir(), 'backup-list-');
        file_put_contents($manifestFile, implode("\n", $relativePaths)."\n");

        try {
            $result = Process::timeout($timeout)
                ->path($base)
                ->run([
                    'tar', '-czf', $targetPath,
                    '--files-from', $manifestFile,
                ], function (string $type, string $output): void {
                    if ($type === 'err' && trim($output) !== '') {
                        Log::warning('tar stderr: '.trim($output));
                    }
                });
        } finally {
            @unlink($manifestFile);
        }

        if ($result->exitCode() !== 0 || ! file_exists($targetPath)) {
            $stderr = trim($result->errorOutput());
            @unlink($targetPath);

            throw new RuntimeException('Files archive failed (exit '.$result->exitCode().'). '.$stderr);
        }

        return count($paths);
    }

    /**
     * Validate a tar.gz archive: tar --test should exit 0 on the whole tree.
     */
    public function verifyArchive(string $archivePath): bool
    {
        if (! file_exists($archivePath)) {
            return false;
        }

        $timeout = (int) config('backup.process_timeouts.verify_archive', 600);

        $result = Process::timeout($timeout)->run(['tar', '-tzf', $archivePath]);

        return $result->exitCode() === 0;
    }
}