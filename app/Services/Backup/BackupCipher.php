<?php

namespace App\Services\Backup;

use RuntimeException;

/**
 * Authenticated encryption for backup configuration files.
 *
 * Format (self-describing, contains NO secret material):
 *   SHAHEAD-ENC:v1:aes-256-gcm:<base64(iv)>:<base64(tag)>:<base64(ciphertext)>
 *
 * The key is derived deterministically with SHA-256 from the configured key
 * material (BACKUP_ENCRYPTION_KEY, falling back to APP_KEY) so it never needs
 * to be stored inside the package. Anyone restoring the platform supplies the
 * same key and can decrypt with the standalone scripts/decrypt-backup.php.
 */
class BackupCipher
{
    public const HEADER = 'SHAHEAD-ENC';

    public const VERSION = 'v1';

    public const CIPHER = 'aes-256-gcm';

    public static function keyBytes(): string
    {
        $material = (string) config('backup.encryption_key', '');

        if (trim($material) === '') {
            throw new RuntimeException('Backup encryption key is empty. Set BACKUP_ENCRYPTION_KEY or APP_KEY.');
        }

        // Normalize base64-encoded keys (e.g. "base64:...") to raw bytes first,
        // then hash to a stable 32-byte key. Deterministic and CLI-usable.
        $raw = $material;
        if (str_starts_with($material, 'base64:')) {
            $decoded = base64_decode(substr($material, 7), true);
            if ($decoded !== false) {
                $raw = $decoded;
            }
        }

        return hash('sha256', $raw, true);
    }

    public static function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::keyBytes(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Backup encryption failed: '.openssl_error_string());
        }

        return implode(':', [
            self::HEADER,
            self::VERSION,
            self::CIPHER,
            base64_encode($iv),
            base64_encode($tag),
            base64_encode($ciphertext),
        ]);
    }

    public static function decrypt(string $payload): string
    {
        $parts = explode(':', $payload, 6);

        if (count($parts) !== 6 || $parts[0] !== self::HEADER || $parts[1] !== self::VERSION || $parts[2] !== self::CIPHER) {
            throw new RuntimeException('Unsupported or malformed backup encryption payload.');
        }

        $iv = base64_decode($parts[3], true);
        $tag = base64_decode($parts[4], true);
        $ciphertext = base64_decode($parts[5], true);

        if ($iv === false || $tag === false || $ciphertext === false) {
            throw new RuntimeException('Backup encryption payload is corrupt.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::keyBytes(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException('Backup decryption failed. Wrong key or tampered payload.');
        }

        return $plaintext;
    }

    public static function canDecrypt(string $payload): bool
    {
        try {
            self::decrypt($payload);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}