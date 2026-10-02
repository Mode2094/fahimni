<?php

namespace App\Integrations;

use Illuminate\Support\Facades\Http;

class ZapierDriver extends BaseDriver
{
    public const EVENTS = [
        'new_student' => 'New student registered',
        'new_enrollment' => 'New course enrollment',
        'new_payment' => 'New payment completed',
        'new_exam_submitted' => 'New exam submitted',
        'new_announcement' => 'New announcement published',
        'new_assignment_submission' => 'New assignment submission',
        'live_session_started' => 'Live session started',
    ];

    public static function label(): string
    {
        return 'Zapier';
    }

    public static function category(): string
    {
        return 'webhook';
    }

    public function test(): array
    {
        $secret = $this->credential('webhook_secret');

        if (! $secret) {
            return $this->failure('Zapier webhook secret is missing — disconnect and reconnect.');
        }

        try {
            $payload = [
                'event' => 'test',
                'message' => 'Tadreesly integration test — connection successful.',
                'timestamp' => now()->toIso8601String(),
            ];

            $response = Http::timeout(15)->post($this->endpoint($secret), $payload);

            if ($response->successful()) {
                $this->logEvent('test_connection', ['event' => 'test']);

                // Never echo the endpoint — it embeds the webhook secret and
                // the test result is persisted and shown to every staff
                // member with access to the integrations page.
                return $this->success('Zapier webhook connected successfully.');
            }

            if ($response->status() === 410 || $response->status() === 404) {
                return $this->failure('Zapier endpoint not found — the Zap may be paused or deleted.');
            }

            return $this->failure('Zapier webhook failed: '.$response->status());
        } catch (\Throwable $e) {
            return $this->failure('Zapier connection failed: '.$e->getMessage());
        }
    }

    public function endpoint(?string $secret = null): string
    {
        $secret ??= $this->credential('webhook_secret');

        return url('/api/v1/integrations/zapier/webhook/'.$secret);
    }

    /**
     * Fire an event payload to the Zapier catch-hook.
     */
    public function trigger(string $event, array $data = []): bool
    {
        if (! $this->enabled() || ! $this->configured()) {
            return false;
        }

        $subscribed = (array) $this->config('events', array_keys(self::EVENTS));

        if (! in_array($event, $subscribed, true)) {
            return false;
        }

        try {
            $payload = [
                'event' => $event,
                'tenant_id' => $this->integration->tenant_id,
                'occurred_at' => now()->toIso8601String(),
                'data' => $data,
            ];

            $response = Http::timeout(15)->post($this->endpoint(), $payload);

            $this->logEvent($event, $payload, $response->successful() ? 'success' : 'error');

            return $response->successful();
        } catch (\Throwable $e) {
            $this->logEvent($event, $data, 'error', $e->getMessage());

            return false;
        }
    }
}
