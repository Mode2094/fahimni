#!/usr/bin/env php
<?php

/**
 * Standalone decryption for SHAHEAD backup configuration files
 * (configuration.enc) — no Laravel bootstrap required.
 *
 * Usage:
 *   php scripts/decrypt-backup.php <configuration.enc> [--key=<BACKUP_ENCRYPTION_KEY|APP_KEY>]
 *
 * If --key is omitted the script looks for BACKUP_ENCRYPTION_KEY then APP_KEY
 * in the local .env. The key derivation matches App\Services\Backup\BackupCipher:
 * the raw key material (base64:... decoded when present) is SHA-256 hashed to a
 * 32-byte AES key, AES-256-GCM authenticated decryption.
 *
 * Output: the decrypted JSON payload on stdout.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$args = array_slice($argv, 1);
$file = null;
$key = null;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--key=')) {
        $key = substr($arg, 6);
    } elseif ($file === null) {
        $file = $arg;
    }
}

if ($file === null || ! is_file($file) || ! is_readable($file)) {
    fwrite(STDERR, "Usage: php scripts/decrypt-backup.php <configuration.enc> [--key=<secret>]\n");
    exit(2);
}

if ($key === null) {
    $env = @file_get_contents(dirname(__DIR__).'/.env') ?: '';
    foreach (explode("\n", $env) as $line) {
        $line = trim($line);
        if (str_starts_with($line, 'BACKUP_ENCRYPTION_KEY=')) {
            $key = substr($line, strlen('BACKUP_ENCRYPTION_KEY='));
            break;
        }
        if (str_starts_with($line, 'APP_KEY=')) {
            $key = substr($line, strlen('APP_KEY='));
        }
    }
}

if ($key === null || $key === '') {
    fwrite(STDERR, "No key found. Pass --key=<secret> or set BACKUP_ENCRYPTION_KEY/APP_KEY in .env\n");
    exit(2);
}

if (str_starts_with($key, 'base64:')) {
    $decoded = base64_decode(substr($key, 7), true);
    if ($decoded !== false) {
        $key = $decoded;
    }
}

$keyBytes = hash('sha256', $key, true);

$payload = trim((string) file_get_contents($file));
$parts = explode(':', $payload, 6);

if (count($parts) !== 6 || $parts[0] !== 'SHAHEAD-ENC' || $parts[1] !== 'v1' || $parts[2] !== 'aes-256-gcm') {
    fwrite(STDERR, "Unsupported or malformed backup encryption payload.\n");
    exit(2);
}

$iv = base64_decode($parts[3], true);
$tag = base64_decode($parts[4], true);
$ciphertext = base64_decode($parts[5], true);

if ($iv === false || $tag === false || $ciphertext === false) {
    fwrite(STDERR, "Corrupt payload.\n");
    exit(2);
}

$plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $keyBytes, OPENSSL_RAW_DATA, $iv, $tag);

if ($plain === false) {
    fwrite(STDERR, "Decryption failed. Wrong key or tampered payload.\n");
    exit(1);
}

echo $plain."\n";
exit(0);
