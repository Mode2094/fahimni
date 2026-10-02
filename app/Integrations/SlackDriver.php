<?php

namespace App\Integrations;

use App\Integrations\Contracts\MessagingDriver;
use App\Support\WebhookUrl;
use Illuminate\Support\Facades\Http;

class SlackDriver extends BaseDriver implements MessagingDriver
{
    public static function label(): string
    {
        return 'Slack';
    }

    public static function category(): string
    {
        return 'messaging';
    }

    public function test(): array
    {
        if (! $this->configured()) {
            return $this->failure('Slack webhook URL is missing.');
        }

        try {
            $webhookUrl = $this->credential('webhook_url');

            if (! $webhookUrl) {
                return $this->failure('Webhook URL is required.');
            }

            if ($this->isIncomingWebhook($webhookUrl)) {
                $response = Http::timeout(15)->post($webhookUrl, [
                    'text' => '✅ Tadreesly integration test — connection successful.',
                ]);

                if (! $response->successful()) {
                    return $this->failure('Slack connection failed: '.$response->status());
                }

                $this->logEvent('test_connection', ['mode' => 'incoming_webhook']);

                return $this->success('Slack webhook connected successfully.', ['details' => ['mode' => 'Incoming webhook']]);
            }

            $token = $this->credential('bot_token');
            if (! $token) {
                return $this->failure('Bot token is required.');
            }

            $response = Http::withToken($token)->acceptJson()->timeout(15)->get('https://slack.com/api/auth.test');

            if (! $response->successful() || ! $response->json('ok')) {
                return $this->failure('Slack connection failed: '.($response->json('error') ?? $response->status()));
            }

            $this->logEvent('test_connection', ['team' => $response->json('team') ?? null]);

            return $this->success('Slack connected successfully.', [
                'details' => ['team' => $response->json('team'), 'user' => $response->json('user')],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('Slack connection failed: '.$e->getMessage());
        }
    }

    public function sendMessage(string $message, ?string $target = null): bool
    {
        $webhookUrl = $target ?: $this->credential('webhook_url');

        if (! $webhookUrl || ! $this->isIncomingWebhook($webhookUrl)) {
            $token = $this->credential('bot_token');
            $channel = $target ?: $this->credential('channel');

            if (! $token || ! $channel) {
                return false;
            }

            try {
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->timeout(15)
                    ->post('https://slack.com/api/chat.postMessage', [
                        'channel' => $channel,
                        'text' => $message,
                    ]);

                $this->logEvent('message_sent', ['channel' => $channel, 'message' => $message], $response->successful() && $response->json('ok') ? 'success' : 'error');

                return $response->successful() && $response->json('ok');
            } catch (\Throwable $e) {
                $this->logEvent('message_sent', ['channel' => $channel, 'message' => $message], 'error', $e->getMessage());

                return false;
            }
        }

        try {
            $response = Http::timeout(15)->post($webhookUrl, ['text' => $message]);

            $this->logEvent('message_sent', ['message' => $message], $response->successful() ? 'success' : 'error');

            return $response->successful();
        } catch (\Throwable $e) {
            $this->logEvent('message_sent', ['message' => $message], 'error', $e->getMessage());

            return false;
        }
    }

    private function isIncomingWebhook(string $url): bool
    {
        // Strict host+path check — a substring match alone lets an attacker
        // smuggle "hooks.slack.com/services/" inside another domain's path.
        return WebhookUrl::safe($url, 'slack');
    }
}
