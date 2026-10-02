<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    public function send(?string $phone, string $message, ?string $tenantId = null): void
    {
        if (! $phone) {
            return;
        }

        $driver = config('whatsapp.driver', 'log');

        try {
            match ($driver) {
                'graph' => $this->sendViaGraph($phone, $message),
                default => $this->log($phone, $message),
            };
        } catch (\Throwable $e) {
            Log::error('WhatsApp sending failed', ['error' => $e->getMessage(), 'phone' => $phone]);
        }
    }

    private function sendViaGraph(string $phone, string $message): void
    {
        $token = config('whatsapp.access_token');
        $phoneNumberId = config('whatsapp.phone_number_id');

        if (! $token || ! $phoneNumberId) {
            Log::warning('WhatsApp skipped: credentials missing.', ['phone' => $phone]);

            return;
        }

        $phone = preg_replace('/[^0-9+]/', '', $phone);

        Http::withToken($token)
            ->timeout(config('whatsapp.timeout'))
            ->post(rtrim(config('whatsapp.api_url'), '/')."/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $phone,
                'type' => 'text',
                'text' => ['body' => $message],
            ])
            ->throw();
    }

    private function log(string $phone, string $message): void
    {
        Log::info('WhatsApp (log driver)', ['to' => $phone, 'message' => $message]);
    }
}
