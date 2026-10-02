<?php

namespace App\Services\Backup;

use App\Models\Backup;
use RuntimeException;

/**
 * Post-build verification of a backup package. A backup is only marked
 * COMPLETED after every check passes; any failure flips it to FAILED.
 */
class Verifier
{
    public function __construct(
        protected ChecksumService $checksums,
        protected DatabaseDumper $dumper,
        protected FilesArchiver $archiver,
    ) {
    }

    /**
     * @return array{ok: bool, verified_at: string, checks: array<string,bool>, messages: string[], errors: string[]}
     */
    public function verify(Backup $backup, string $directory): array
    {
        $dir = rtrim($directory, '/');
        $artifactNames = $backup->artifactFilenames();

        $messages = [];
        $errors = [];
        $checks = [];

        // 1. Required artifacts all present on disk.
        $present = true;
        foreach ($artifactNames as $name) {
            $path = $dir.'/'.$name;
            $checks['present:'.$name] = is_file($path) && filesize($path) > 0;
            $present = $present && $checks['present:'.$name];
            if ($checks['present:'.$name]) {
                $messages[] = "Artifact present: {$name}";
            } else {
                $errors[] = "Missing artifact: {$name}";
            }
        }

        // 2. Manifest readable + non-secret + coherent.
        try {
            $manifestPath = $dir.'/'.$backup->manifest;
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            $checks['manifest_parses'] = is_array($manifest) && ($manifest['backup_id'] ?? null) === $backup->backup_id;
            $checks['manifest_no_secrets'] = $this->manifestIsClean($manifestPath);
            if ($checks['manifest_parses']) {
                $messages[] = 'Manifest parses and matches backup_id';
            } else {
                $errors[] = 'Manifest is missing, malformed, or backup_id mismatch';
            }
            if (! $checks['manifest_no_secrets']) {
                $errors[] = 'Manifest contains secret-like material';
            }
        } catch (RuntimeException $e) {
            $checks['manifest_parses'] = false;
            $checks['manifest_no_secrets'] = false;
            $errors[] = 'Manifest unreadable: '.$e->getMessage();
        }

        // 3. Recompute checksums and compare.
        try {
            $stored = $this->checksums->read($dir.'/'.$backup->checksums);

            $allMatch = true;
            foreach ($stored['files'] as $name => $expected) {
                $path = $dir.'/'.$name;
                $actual = is_file($path) ? $this->checksums->hashFile($path) : null;
                $key = 'checksum:'.$name;
                $checks[$key] = is_string($actual) && hash_equals($expected, $actual);
                $allMatch = $allMatch && $checks[$key];
                if ($checks[$key]) {
                    $messages[] = "Checksum OK: {$name}";
                } else {
                    $errors[] = "Checksum mismatch or missing file: {$name}";
                }
            }

            // Package hash (checksum of the checksums doc) must equal manifest.checksum.
            $packageHash = $this->checksums->hashString(json_encode(
                ['algorithm' => $stored['algorithm'], 'created_at' => $stored['created_at'] ?? '', 'files' => $stored['files']],
                JSON_UNESCAPED_SLASHES
            ));
            $manifestChecksum = $manifest['checksum'] ?? null;
            $checks['package_hash_matches_manifest'] = is_string($manifestChecksum) && hash_equals($manifestChecksum, $packageHash);
            if ($checks['package_hash_matches_manifest']) {
                $messages[] = 'Package hash matches manifest';
            } else {
                $errors[] = 'Package hash does not match manifest.checksum';
            }

            $checks['checksums_complete'] = $allMatch;
        } catch (RuntimeException $e) {
            $checks['checksums_complete'] = false;
            $checks['package_hash_matches_manifest'] = false;
            $errors[] = 'Checksums unreadable: '.$e->getMessage();
        }

        // 4. Database dump structural validation (pg_restore --list).
        if (config('backup.verify.dump', true) && $backup->database_dump) {
            try {
                $dumpPath = $dir.'/'.$backup->database_dump;
                $checks['dump_valid'] = is_file($dumpPath) && $this->dumper->verifyDump($dumpPath);
                if ($checks['dump_valid']) {
                    $messages[] = 'Database dump validated with pg_restore --list';
                } else {
                    $errors[] = 'Database dump failed pg_restore --list validation';
                }
            } catch (\Throwable $e) {
                $checks['dump_valid'] = false;
                $errors[] = 'Database dump validation error: '.$e->getMessage();
            }
        }

        // 5. Files archive structural validation (tar --test).
        if (config('backup.verify.archive', true) && $backup->files_archive) {
            try {
                $archivePath = $dir.'/'.$backup->files_archive;
                $checks['archive_valid'] = is_file($archivePath) && $this->archiver->verifyArchive($archivePath);
                if ($checks['archive_valid']) {
                    $messages[] = 'Files archive validated with tar --test';
                } else {
                    $errors[] = 'Files archive failed tar --test validation';
                }
            } catch (\Throwable $e) {
                $checks['archive_valid'] = false;
                $errors[] = 'Files archive validation error: '.$e->getMessage();
            }
        }

        // 6. Encrypted configuration must be decryptable with the current key.
        try {
            $configPath = $dir.'/'.$backup->config_archive;
            $checks['config_decrypts'] = is_file($configPath) && BackupCipher::canDecrypt((string) file_get_contents($configPath));
            if ($checks['config_decrypts']) {
                $messages[] = 'Encrypted configuration decrypts with configured key';
            } else {
                $errors[] = 'Encrypted configuration failed decryption check';
            }
        } catch (\Throwable $e) {
            $checks['config_decrypts'] = false;
            $errors[] = 'Encrypted configuration check error: '.$e->getMessage();
        }

        $ok = $present && count($errors) === 0;

        return [
            'ok' => $ok,
            'verified_at' => now()->toIso8601String(),
            'checks' => $checks,
            'messages' => $messages,
            'errors' => $errors,
        ];
    }

    /**
     * Cheap heuristic: the manifest must never contain secret-looking values.
     * It only holds non-secret metadata, so any sensitive key name or long
     * base64 blob is a red flag.
     */
    protected function manifestIsClean(string $manifestPath): bool
    {
        $raw = (string) file_get_contents($manifestPath);

        $sensitiveKeys = [
            'PASSWORD', 'SECRET', 'API_KEY', 'TOKEN', 'PRIVATE_KEY',
            'SIGNING_KEY', 'ENCRYPTION_KEY', 'ACCESS_KEY',
        ];

        foreach ($sensitiveKeys as $needle) {
            if (stripos($raw, $needle) !== false) {
                return false;
            }
        }

        // Long base64 blobs (key material) are usually > 60 chars and contain
        // non-hex characters (+ / = uppercase outside [a-f]). A pure-hex run
        // is legitimately our own SHA-256 checksum, so it is allowed.
        if (preg_match_all('/[A-Za-z0-9+\/=]{60,}/', $raw, $matches)) {
            foreach ($matches[0] as $run) {
                if (preg_match('/[^a-fA-F0-9]/', $run)) {
                    return false;
                }
            }
        }

        return true;
    }
}