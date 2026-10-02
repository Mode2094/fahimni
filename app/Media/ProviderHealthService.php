<?php

namespace App\Media;

use App\Models\MediaProvider;
use Illuminate\Support\Facades\Log;

/**
 * مراقبة صحة المزود — فحص دوري وتسجيل الحالة والخطأ الأخير.
 */
class ProviderHealthService
{
    public function __construct(
        private readonly MediaProviderManager $manager,
    ) {}

    public function check(?string $provider = null): array
    {
        $model = $this->manager->model($provider);
        $driver = $this->manager->provider($provider);

        if (! $driver->isConfigured()) {
            $this->record($model, 'not_configured', 'Credentials missing.');

            return ['provider' => $provider, 'ok' => false, 'status' => 'not_configured', 'message' => 'Credentials missing.'];
        }

        $result = $driver->healthCheck();

        $status = $result['ok'] ? 'healthy' : 'down';
        $this->record($model, $status, $result['ok'] ? null : $result['message']);

        Log::info('media.provider.health', [
            'provider' => $provider,
            'status' => $status,
            'latency_ms' => $result['latency_ms'],
        ]);

        return [
            'provider' => $provider,
            'ok' => $result['ok'],
            'status' => $status,
            'message' => $result['message'],
            'latency_ms' => $result['latency_ms'],
            'checked_at' => now()->toIso8601String(),
        ];
    }

    public function checkAll(): array
    {
        $out = [];

        foreach ($this->manager->registry() as $slug => $class) {
            $out[] = $this->check($slug);
        }

        return $out;
    }

    private function record(?MediaProvider $model, string $status, ?string $error): void
    {
        if ($model === null) {
            return;
        }

        $model->update([
            'connection_status' => $status,
            'last_health_check_at' => now(),
            'last_error' => $error !== null ? substr($error, 0, 1000) : null,
        ]);
    }
}
