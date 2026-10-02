<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * SHA-256 checksums for every artifact in a backup package. The package hash
 * is the SHA-256 of the checksums document itself and is recorded in the
 * manifest so tampering with either file is detectable.
 */
class ChecksumService
{
    public const ALGORITHM = 'sha256';

    public function hashFile(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Cannot checksum missing file: '.$path);
        }

        return hash_file(self::ALGORITHM, $path);
    }

    public function hashString(string $data): string
    {
        return hash(self::ALGORITHM, $data);
    }

    /**
     * @param array<string,string> $artifactPaths absolute path => artifact name
     * @return array{algorithm: string, created_at: string, files: array<string,string>, package_sha256: string}
     */
    public function compute(array $artifactPaths): array
    {
        $files = [];

        foreach ($artifactPaths as $absolutePath => $name) {
            $files[$name] = $this->hashFile($absolutePath);
        }

        // Deterministic order for stable output.
        ksort($files);

        $createdAt = now()->toIso8601String();

        $payload = [
            'algorithm' => self::ALGORITHM,
            'created_at' => $createdAt,
            'files' => $files,
        ];

        $packageHash = $this->hashString(json_encode($payload, JSON_UNESCAPED_SLASHES));

        return [
            'algorithm' => self::ALGORITHM,
            'created_at' => $createdAt,
            'files' => $files,
            'package_sha256' => $packageHash,
        ];
    }

    public function write(string $path, array $checksums): void
    {
        file_put_contents(
            $path,
            json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
        );
    }

    public function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Checksums file missing or unreadable: '.$path);
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || empty($data['files'])) {
            throw new RuntimeException('Checksums file is malformed: '.$path);
        }

        return $data;
    }
}