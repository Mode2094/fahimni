<?php

namespace App\Media;

use App\Media\Contracts\MediaProviderInterface;
use App\Media\Providers\BunnyStreamProvider;
use App\Models\MediaProvider;
use RuntimeException;

/**
 * مدير مزودي الوسائط — يحل المزود النشط من قاعدة البيانات
 * (media_providers) ويسجل حالته الصحية وأخطاءه.
 *
 * مزود الفيديو المسجّل الوحيد: Bunny Stream.
 */
class MediaProviderManager
{
    private const PROVIDERS = [
        'bunny' => BunnyStreamProvider::class,
    ];

    /**
     * المزود الافتراضي عند عدم وجود مزود مفعّل في قاعدة البيانات.
     */
    private const DEFAULT_PROVIDER = 'bunny';

    /**
     * جميع المزودات المدعومة (slug => class).
     */
    public function registry(): array
    {
        return self::PROVIDERS;
    }

    public function supports(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider]);
    }

    public function provider(string $provider): MediaProviderInterface
    {
        $provider = $this->supports($provider) ? $provider : self::DEFAULT_PROVIDER;

        if (! isset(self::PROVIDERS[$provider])) {
            throw new RuntimeException("Media provider [{$provider}] is not supported.");
        }

        $class = self::PROVIDERS[$provider];

        return new $class($this->model($provider));
    }

    /**
     * المزود المفعّل (enabled + credentials) من قاعدة البيانات، أو
     * الرجوع للافتراضي إن كان مكوّناً بيئياً.
     */
    public function activeProvider(): ?MediaProviderInterface
    {
        // 1) مزود مفعّل وموثق في قاعدة البيانات.
        $model = MediaProvider::query()
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->first();

        if ($model && $model->isConfigured()) {
            return $this->provider($model->provider);
        }

        // 2) أول مزود مكوّن من الإعدادات البيئية (حسب ترتيب السجل).
        foreach (array_keys(self::PROVIDERS) as $slug) {
            $driver = $this->provider($slug);

            if ($driver->isConfigured()) {
                return $driver;
            }
        }

        return null;
    }

    public function model(string $provider): ?MediaProvider
    {
        return MediaProvider::query()->where('provider', $provider)->first();
    }

    public function requireActiveProvider(): MediaProviderInterface
    {
        $provider = $this->activeProvider();

        if ($provider === null) {
            throw new RuntimeException('No media provider is enabled and configured.');
        }

        return $provider;
    }

    /**
     * بيانات واجهة لوحة الأدمن (آمنة — بدون أسرار).
     */
    public function adminPayload(): array
    {
        $out = [];

        foreach (self::PROVIDERS as $slug => $class) {
            $model = $this->model($slug);
            $driver = $this->provider($slug);

            $out[$slug] = [
                'provider' => $slug,
                'label' => $model?->label ?? 'Bunny Stream',
                'enabled' => (bool) ($model?->enabled ?? false),
                'configured' => $driver->isConfigured(),
                'environment' => $model?->environment ?? 'production',
                'connection_status' => $model?->connection_status ?? 'not_configured',
                'last_health_check_at' => $model?->last_health_check_at,
                'last_synced_at' => $model?->last_synced_at,
                'last_error' => $model?->last_error,
                'webhook_secret_set' => filled((string) ($model?->getAttributes()['webhook_secret'] ?? '')),
                // الاعتمادات الفعلية (للأدمن فقط — المسار محمي بـ middleware admin)
                // حتى يتمكن من رؤيتها وتعديلها.
                'credentials' => $model?->credentialArray() ?? [],
                // رابط الويب هوك الكامل مع السر (للأدمن فقط — يلصقه في المزود).
                'webhook_url' => $model?->webhook_secret
                    ? rtrim(config('app.frontend_url') ?: config('app.url'), '/').'/api/v1/media/webhook/'.$slug.'?secret='.urlencode((string) $model->webhook_secret)
                    : null,
                'capabilities' => $driver->capabilities(),
            ];
        }

        return $out;
    }
}
