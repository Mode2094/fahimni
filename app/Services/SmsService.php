<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    public function send(?string $phone, string $message, ?string $tenantId = null): void
    {
        if (! $phone) {
            return;
        }

        $driver = config('sms.driver', 'log');

        try {
            match ($driver) {
                'twilio' => $this->sendViaTwilio($phone, $message),
                default => $this->log($phone, $message),
            };
        } catch (\Throwable $e) {
            Log::error('SMS sending failed', ['error' => $e->getMessage(), 'phone' => $phone]);
        }
    }

    private function sendViaTwilio(string $phone, string $message): void
    {
        $sid = config('sms.twilio.sid');
        $token = config('sms.twilio.token');

        if (! $sid || ! $token) {
            Log::warning('SMS skipped: Twilio credentials missing.', ['phone' => $phone]);

            return;
        }

        Http::withBasicAuth($sid, $token)
            ->asForm()
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'From' => config('sms.twilio.from') ?? config('sms.from'),
                'To' => $phone,
                'Body' => $message,
            ])
            ->throw();
    }

    private function log(string $phone, string $message): void
    {
        Log::info('SMS (log driver)', ['to' => $phone, 'message' => $message]);
    }
}
