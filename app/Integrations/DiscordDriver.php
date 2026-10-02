<?php

namespace App\Integrations;

use App\Integrations\Contracts\MessagingDriver;
use App\Support\WebhookUrl;
use Illuminate\Support\Facades\Http;

class DiscordDriver extends BaseDriver implements MessagingDriver
{
    public static function label(): string
    {
        return 'Discord';
    }

    public static function category(): string
    {
        return 'messaging';
    }

    public function test(): array
    {
        if (! $this->configured()) {
            return $this->failure('Discord webhook URL is missing.');
        }

        try {
            $webhookUrl = $this->credential('webhook_url');

            if (! $webhookUrl) {
                return $this->failure('Webhook URL is required.');
            }

            // SSRF guard: only Discord's official webhook hosts are fetched.
            if (! WebhookUrl::safe($webhookUrl, 'discord')) {
                return $this->failure('Invalid Discord webhook URL.');
            }

            $response = Http::timeout(15)->get($webhookUrl);

            if (! $response->successful()) {
                return $this->failure('Discord connection failed: '.($response->json('message') ?? $response->status()));
            }

            $data = $response->json();
            $this->logEvent('test_connection', ['channel' => $data['name'] ?? null]);

            return $this->success('Discord webhook connected successfully.', [
                'details' => ['channel' => '#'.($data['name'] ?? 'unknown'), 'guild' => $data['guild_id'] ?? null],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('Discord connection failed: '.$e->getMessage());
        }
    }

    public function sendMessage(string $message, ?string $target = null): bool
    {
        $webhookUrl = $target ?: $this->credential('webhook_url');

        if (! $webhookUrl || ! WebhookUrl::safe($webhookUrl, 'discord')) {
            return false;
        }

        try {
            $response = Http::timeout(15)->post($webhookUrl.(str_contains($webhookUrl, '?') ? '&' : '?').'wait=true', [
                'content' => $message,
            ]);

            $this->logEvent('message_sent', ['message' => $message], $response->successful() ? 'success' : 'error');

            return $response->successful();
        } catch (\Throwable $e) {
            $this->logEvent('message_sent', ['message' => $message], 'error', $e->getMessage());

            return false;
        }
    }
}
