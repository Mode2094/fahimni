<?php

namespace App\Integrations\Concerns;

use App\Models\IntegrationEvent;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Uid\UuidV7;

trait ManagesIntegration
{
    public function configured(): bool
    {
        return $this->integration->isConfigured();
    }

    public function enabled(): bool
    {
        return $this->integration->enabled;
    }

    protected function credential(string $key, mixed $default = null): mixed
    {
        return $this->integration->credentialArray()[$key] ?? $default;
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return $this->integration->config[$key] ?? $default;
    }

    protected function logEvent(string $event, array $payload, string $status = 'success', ?string $error = null): void
    {
        try {
            IntegrationEvent::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $this->integration->tenant_id,
                'integration_id' => $this->integration->id,
                'provider' => $this->integration->provider,
                'direction' => 'out',
                'event' => $event,
                'payload' => $payload,
                'status' => $status,
                'error' => $error,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Integration event logging failed', ['error' => $e->getMessage()]);
        }
    }

    protected function failure(string $message, array $context = []): array
    {
        Log::warning("Integration {$this->integration->provider} failed", array_merge(['error' => $message], $context));

        return ['ok' => false, 'message' => $message];
    }

    protected function success(string $message, array $details = []): array
    {
        return array_merge(['ok' => true, 'message' => $message], $details);
    }
}
