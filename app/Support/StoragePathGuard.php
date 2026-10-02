<?php

namespace App\Support;

/**
 * Guards storage paths supplied by clients before they are stored or used
 * to serve files. Rejects absolute paths, drive letters and traversal
 * segments (`..`), and optionally restricts to an allowlist of top-level
 * directories under the disk root.
 */
final class StoragePathGuard
{
    public static function isSafe(?string $path, array $allowedPrefixes = []): bool
    {
        if ($path === null || trim($path) === '') {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);

        if (str_starts_with($normalized, '/') || preg_match('/^[a-zA-Z]:\//', $normalized) === 1) {
            return false;
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..' || $segment === '.' || $segment === '') {
                return false;
            }
        }

        if ($allowedPrefixes !== []) {
            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($normalized, rtrim($prefix, '/').'/')) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }
}
