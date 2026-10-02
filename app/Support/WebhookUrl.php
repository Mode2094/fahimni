<?php

namespace App\Support;

/**
 * Validates webhook URLs before the server sends anything to them,
 * preventing SSRF (internal hosts, cloud metadata endpoints, attacker
 * domains) through tenant-supplied notification targets.
 */
final class WebhookUrl
{
    public static function safe(?string $url, string $provider): bool
    {
        if ($url === null || trim($url) === '') {
            return true;
        }

        $parts = parse_url($url);

        if (! $parts || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['https'], true)) {
            return false;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '/';

        return match ($provider) {
            'discord' => in_array($host, ['discord.com', 'discordapp.com', 'canary.discord.com', 'ptb.discord.com'], true)
                && str_starts_with($path, '/api/webhooks/'),
            'slack' => $host === 'hooks.slack.com'
                && str_starts_with($path, '/services/'),
            'telegram' => $host === 'api.telegram.org',
            default => false,
        };
    }
}
