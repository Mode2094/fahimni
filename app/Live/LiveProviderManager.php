<?php

namespace App\Live;

use App\Live\Providers\GoogleMeetLiveProvider;
use App\Models\LiveSession;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * مدير مزودي البث المباشر — السجل الوحيد للمزودين.
 *
 * مزود الاجتماعات الوحيد: google_meet (رابط meet.google.com يقدمه المعلم).
 * لا مزود وسائط ولا WebRTC ولا بث عبر الكاميرا — أي مزود آخر أُزيل نهائياً.
 * جلسات قديمة بحالة "custom" تُحل إلى Google Meet (بياناتها من metadata).
 */
class LiveProviderManager
{
    public const PROVIDER_GOOGLE_MEET = 'google_meet';

    /** المزود الموروث "custom" يُحل إلى Google Meet لأجل الجلسات القديمة فقط. */
    public const LEGACY_CUSTOM = 'custom';

    /** المزودون الجاهزون لإنشاء جلسات جديدة (Google Meet فقط). */
    private const MEETING_PROVIDERS = [
        self::PROVIDER_GOOGLE_MEET => GoogleMeetLiveProvider::class,
    ];

    public function registry(): array
    {
        return self::MEETING_PROVIDERS;
    }

    public function supports(string $provider): bool
    {
        return isset(self::MEETING_PROVIDERS[$provider]);
    }

    /**
     * حل المزود الفعلي للجلسة.
     * الجلسات القديمة بمزودات محذوفة (zoom/jitsi/bunny/...) تُعامل كبيانات
     * metadata فقط ولا تُنشئ أي موارد — تعمل من بياناتها المخزنة بلا مزود.
     */
    public function resolve(string $provider): LiveProviderInterface
    {
        if ($provider === self::LEGACY_CUSTOM || ! $this->supports($provider)) {
            return $this->provider(self::PROVIDER_GOOGLE_MEET);
        }

        return $this->provider($provider);
    }

    public function provider(string $provider): LiveProviderInterface
    {
        $class = self::MEETING_PROVIDERS[$provider] ?? null;

        if ($class === null) {
            throw new RuntimeException("Live provider [{$provider}] is not supported.");
        }

        return app($class);
    }

    public function providerFor(LiveSession $session): LiveProviderInterface
    {
        return $this->resolve((string) $session->provider);
    }

    /**
     * المزودون المتاحون لإنشاء جلسات جديدة (مفعّل + مكوّن).
     *
     * @return array<string, LiveProviderInterface>
     */
    public function enabledProviders(): array
    {
        $out = [];

        foreach (self::MEETING_PROVIDERS as $slug => $class) {
            $driver = $this->provider($slug);

            if ($driver->isEnabled() && $driver->isConfigured()) {
                $out[$slug] = $driver;
            }
        }

        return $out;
    }

    /**
     * المزود الافتراضي — Google Meet دائماً.
     */
    public function defaultProvider(): string
    {
        $default = (string) PlatformSetting::get('live_default_provider', self::PROVIDER_GOOGLE_MEET);

        return $this->supports($default) ? $default : self::PROVIDER_GOOGLE_MEET;
    }

    /**
     * حالة المزودين للوحة الأدمن — Google Meet فقط، جاهز دائماً بلا اعتمادات.
     *
     * @return array<string, array<string, mixed>>
     */
    public function adminStatus(): array
    {
        return Cache::remember('live:providers:status', 300, function () {
            $driver = $this->provider(self::PROVIDER_GOOGLE_MEET);

            return [
                self::PROVIDER_GOOGLE_MEET => [
                    'provider' => self::PROVIDER_GOOGLE_MEET,
                    'label' => $driver->label(),
                    'enabled' => $driver->isEnabled(),
                    'configured' => $driver->isConfigured(),
                    'player_type' => $driver->playerType(),
                    'health' => $driver->health(),
                    'default' => true,
                    'credentials_set' => false,
                    'fields' => [],
                ],
            ];
        });
    }

    public function invalidateStatusCache(): void
    {
        Cache::forget('live:providers:status');
    }
}