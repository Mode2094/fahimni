<?php

namespace App\Console\Commands;

use App\Models\PlatformSetting;
use Illuminate\Console\Command;

/**
 * One-time (idempotent) migration of legacy plaintext payment-gateway
 * secrets to Crypt at-rest encryption with an `enc:v1:` version marker.
 *
 * Safe to run repeatedly: values that are already encrypted are skipped.
 * Never prints secret values — only counts.
 */
class EncryptPlatformSecrets extends Command
{
    protected $signature = 'settings:encrypt-secrets {--dry-run : Report what would change without writing}';

    protected $description = 'Encrypt legacy plaintext gateway secrets in platform_settings (idempotent, prints no values).';

    public function handle(): int
    {
        $encrypted = 0;
        $already = 0;
        $empty = 0;

        foreach (PlatformSetting::secretKeys() as $key) {
            $raw = PlatformSetting::get($key);

            if ($raw === null || trim((string) $raw) === '') {
                $empty++;
                continue;
            }

            if (PlatformSetting::isEncryptedSecret($raw)) {
                $already++;
                continue;
            }

            if (! $this->option('dry-run')) {
                PlatformSetting::setSecret($key, $raw);
            }

            $encrypted++;
        }

        $mode = $this->option('dry-run') ? 'dry-run' : 'applied';
        $this->info("settings:encrypt-secrets ({$mode}) encrypted={$encrypted} already_encrypted={$already} empty={$empty}");

        return self::SUCCESS;
    }
}
