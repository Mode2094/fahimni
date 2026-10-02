<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Builds the encrypted configuration artifact (configuration.enc).
 *
 * The payload is a JSON document containing everything an engineer needs to
 * restore the platform's configuration: the full .env file (encrypted at
 * rest), non-secret connection metadata, the list of sensitive keys present,
 * and a pointer explaining that provider credentials (Bunny Stream) and
 * video references live inside the database dump — already encrypted with the
 * application key and re-linkable on restore.
 *
 * The whole JSON is then AES-256-GCM encrypted via BackupCipher. No secret is
 * ever written to disk, logs, filenames, manifests or API responses.
 */
class ConfigurationEncryptor
{
    public function build(): string
    {
        $payload = [
            'format' => 'shahead-lms-backup-config',
            'version' => 1,
            'created_at' => now()->toIso8601String(),
            'app' => [
                'name' => config('app.name'),
                'environment' => app()->environment(),
                'url' => (string) config('app.url'),
                'locale' => config('app.locale'),
            ],
            'database' => $this->databaseMetadata(),
            'env_file' => $this->readEnvFile(),
            'sensitive_keys' => $this->sensitiveKeysPresent(),
            'media' => [
                'note' => 'Bunny Stream videos are hosted off-server and are NOT downloaded. All references needed to re-link them (provider, library_id, video_id / provider_asset_id, playback_id, lesson/course/teacher relations, status, metadata, idempotency keys) are preserved in the database dump (media_providers, media_assets, video_contents, lesson_contents).',
                'providers_encrypted_in_database' => true,
                'video_files_included' => false,
                'video_references_in_database' => true,
            ],
        ];

        return BackupCipher::encrypt(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function databaseMetadata(): array
    {
        $connection = config('database.connections.'.config('database.default'), []);

        return [
            'engine' => 'pgsql',
            'host' => $connection['host'] ?? null,
            'port' => $connection['port'] ?? null,
            'database' => $connection['database'] ?? null,
            'username' => $connection['username'] ?? null,
            // password intentionally omitted — it is present in env_file (encrypted)
            'password_in_env' => true,
        ];
    }

    protected function readEnvFile(): string
    {
        $envPath = base_path('.env');

        if (! is_file($envPath) || ! is_readable($envPath)) {
            return '';
        }

        return (string) file_get_contents($envPath);
    }

    protected function sensitiveKeysPresent(): array
    {
        $needleKeys = [
            'APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD', 'MAIL_PASSWORD',
            'SANCTUM_TOKEN_PREFIX', 'VIDEO_SIGNING_KEY', 'VIDEO_SIGNING_KEY_PREVIOUS',
            'REFUND_WEBHOOK_SECRET',
            'BUNNY_LIBRARY_ID', 'BUNNY_API_KEY', 'BUNNY_SECURITY_KEY', 'BUNNY_WEBHOOK_SECRET',
            'BACKUP_ENCRYPTION_KEY', 'SUPER_ADMIN_PASSWORD',
        ];

        $present = [];

        foreach (explode("\n", $this->readEnvFile()) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $key = explode('=', $line, 2)[0] ?? '';
            if ($key !== '' && in_array($key, $needleKeys, true)) {
                $present[] = $key;
            }
        }

        sort($present);

        return $present;
    }
}