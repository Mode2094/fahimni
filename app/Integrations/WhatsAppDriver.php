<?php

namespace App\Integrations;

use App\Integrations\Contracts\MessagingDriver;
use Illuminate\Support\Facades\Http;

class WhatsAppDriver extends BaseDriver implements MessagingDriver
{
    private const GRAPH = 'https://graph.facebook.com/v20.0';

    public static function label(): string
    {
        return 'WhatsApp Business';
    }

    public static function category(): string
    {
        return 'messaging';
    }

    public function test(): array
    {
        if (! $this->configured()) {
            return $this->failure('WhatsApp credentials are missing.');
        }

        try {
            $token = $this->credential('access_token');
            $phoneNumberId = $this->credential('phone_number_id');

            if (! $token || ! $phoneNumberId) {
                return $this->failure('Access token and phone number ID are required.');
            }

            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(15)
                ->get(self::GRAPH."/{$phoneNumberId}", ['fields' => 'display_phone_number,verified_name,quality_rating']);

            if (! $response->successful()) {
                return $this->failure('WhatsApp connection failed: '.($response->json('error')['message'] ?? $response->status()));
            }

            $data = $response->json();
            $this->logEvent('test_connection', ['phone' => $data['display_phone_number'] ?? null]);

            return $this->success('WhatsApp Business connected successfully.', [
                'details' => [
                    'phone' => $data['display_phone_number'] ?? null,
                    'verified_name' => $data['verified_name'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->failure('WhatsApp connection failed: '.$e->getMessage());
        }
    }

    public function sendMessage(string $message, ?string $target = null): bool
    {
        $token = $this->credential('access_token');
        $phoneNumberId = $this->credential('phone_number_id');
        $phone = $target ?: $this->config('default_to');

        if (! $token || ! $phoneNumberId || ! $phone) {
            return false;
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(15)
                ->post(self::GRAPH."/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => preg_replace('/[^0-9+]/', '', $phone),
                    'type' => 'text',
                    'text' => ['body' => $message],
                ]);

            $this->logEvent('message_sent', ['to' => $phone, 'message' => $message], $response->successful() ? 'success' : 'error');

            return $response->successful();
        } catch (\Throwable $e) {
            $this->logEvent('message_sent', ['to' => $phone, 'message' => $message], 'error', $e->getMessage());

            return false;
        }
    }
}
