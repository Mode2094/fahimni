<?php

namespace App\Integrations;

use App\Integrations\Contracts\MessagingDriver;
use Illuminate\Support\Facades\Http;

class TelegramDriver extends BaseDriver implements MessagingDriver
{
    public static function label(): string
    {
        return 'Telegram';
    }

    public static function category(): string
    {
        return 'messaging';
    }

    public function test(): array
    {
        if (! $this->configured()) {
            return $this->failure('Telegram bot token is missing.');
        }

        try {
            $token = $this->credential('bot_token');

            if (! $token) {
                return $this->failure('Bot token is required.');
            }

            $response = Http::timeout(15)->get("https://api.telegram.org/bot{$token}/getMe");

            if (! $response->successful()) {
                return $this->failure('Telegram connection failed: '.($response->json('description') ?? $response->status()));
            }

            $bot = $response->json('result') ?? [];
            $this->logEvent('test_connection', ['bot' => $bot['username'] ?? null]);

            return $this->success('Telegram bot connected successfully.', [
                'details' => ['bot' => '@'.($bot['username'] ?? 'unknown')],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('Telegram connection failed: '.$e->getMessage());
        }
    }

    public function sendMessage(string $message, ?string $target = null): bool
    {
        $token = $this->credential('bot_token');
        $chatId = $target ?: $this->credential('chat_id');

        if (! $token || ! $chatId) {
            return false;
        }

        try {
            $response = Http::timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            $this->logEvent('message_sent', ['chat_id' => $chatId, 'message' => $message], $response->successful() ? 'success' : 'error');

            return $response->successful();
        } catch (\Throwable $e) {
            $this->logEvent('message_sent', ['chat_id' => $chatId, 'message' => $message], 'error', $e->getMessage());

            return false;
        }
    }
}
