<?php

namespace App\Media;

use App\Media\Contracts\MediaProviderInterface;
use App\Models\MediaWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Uid\UuidV7;

/**
 * استقبال ومعالجة أحداث المزود بأمان:
 *  1. تحقق من التوقيع (HMAC-SHA256) — fail-closed.
 *  2. Idempotency: نفس الحدث (provider_event_id أو hash) لا يُطبق مرتين.
 *  3. Replay protection: نرفض الأحداث القديمة خارج نافذة زمنية.
 *  4. التطبيق الفعلي يتم في MediaService عبر handleWebhook من المزود.
 */
class MediaWebhookService
{
    public const MAX_AGE_SECONDS = 3600; // نافذة قبول الأحداث

    public function __construct(
        private readonly MediaProviderManager $manager,
        private readonly MediaService $media,
    ) {}

    /**
     * نقطة الدخول العامة للويبهوك.
     *
     * @return array{accepted: bool, event_id: ?string, reason: string}
     */
    public function handle(Request $request, ?string $provider = null): array
    {
        $provider ??= $this->manager->activeProvider()?->name() ?? 'bunny';

        $providerModel = $this->manager->model($provider);
        $secret = $providerModel?->getRawOriginal('webhook_secret')
            ? (string) $providerModel->webhook_secret
            : (string) config('bunny.webhook_secret', '');

        if ($secret === '') {
            Log::warning('media.webhook.rejected', ['provider' => $provider, 'reason' => 'secret_not_configured']);

            return ['accepted' => false, 'event_id' => null, 'reason' => 'webhook_not_configured'];
        }

        // 1) التوقيع: Bunny Stream لا يدعم ترويسات مخصصة، لذا يبقى دعم
        //    ?secret= للتوافق — مع تفضيل الترويسة (X-Webhook-Secret أو
        //    Authorization: Bearer) لتفادي ظهور السر في سجلات الوصول.
        $querySecret = (string) $request->query('secret', '');
        $headerSecret = (string) ($request->header('X-Webhook-Secret')
            ?: preg_replace('/^Bearer\s+/i', '', (string) $request->header('Authorization', '')));

        $presented = $headerSecret !== '' ? $headerSecret : $querySecret;

        if ($presented === '' || ! hash_equals($secret, $presented)) {
            Log::warning('media.webhook.rejected', ['provider' => $provider, 'reason' => 'invalid_signature']);

            return ['accepted' => false, 'event_id' => null, 'reason' => 'invalid_signature'];
        }

        $event = $request->input();
        $eventId = (string) ($event['id'] ?? $event['uid'] ?? '');
        $hash = hash('sha256', $request->getContent());

        // 2) Replay protection: نافذة زمنية.
        $ts = (int) ($event['timestamp'] ?? $request->header('X-Timestamp', 0));
        if ($ts > 0 && abs(time() - $ts) > self::MAX_AGE_SECONDS) {
            Log::warning('media.webhook.rejected', ['provider' => $provider, 'reason' => 'event_too_old']);

            return ['accepted' => false, 'event_id' => $eventId, 'reason' => 'event_too_old'];
        }

        // 3) Idempotency: حدث مُعالج مسبقاً لا يُعاد.
        $existing = MediaWebhookEvent::query()
            ->where('provider', $provider)
            ->where('event_hash', $hash)
            ->first();

        if ($existing) {
            return ['accepted' => true, 'event_id' => $existing->id, 'reason' => 'duplicate_skipped'];
        }

        $record = MediaWebhookEvent::create([
            'id' => UuidV7::generate(),
            'provider' => $provider,
            'event_type' => (string) ($event['type'] ?? 'unknown'),
            'provider_event_id' => $eventId !== '' ? $eventId : null,
            'event_hash' => $hash,
            'payload' => $event,
            'status' => 'received',
            'received_at' => now(),
        ]);

        // 4) المعالجة.
        try {
            $driver = $this->manager->provider($provider);
            $normalized = $driver->handleWebhook($event);

            $this->apply($driver, $normalized, $record->id);

            $record->update(['status' => 'processed', 'processed_at' => now()]);

            return ['accepted' => true, 'event_id' => $record->id, 'reason' => 'processed'];
        } catch (\Throwable $e) {
            Log::error('media.webhook.failed', [
                'provider' => $provider,
                'event' => $record->id,
                'error' => $e->getMessage(),
            ]);

            $record->update(['status' => 'failed', 'error' => substr($e->getMessage(), 0, 1000)]);

            return ['accepted' => true, 'event_id' => $record->id, 'reason' => 'processing_failed'];
        }
    }

    /**
     * تطبيق حدث مُطبَّع على حالة النظام (Idempotent في كل خطوة).
     *
     * @param  array<string, mixed>  $normalized
     */
    private function apply(MediaProviderInterface $driver, array $normalized, string $eventId): void
    {
        $type = (string) ($normalized['type'] ?? 'unknown');
        $assetId = (string) ($normalized['asset_id'] ?? '');

        switch ($type) {
            case 'video.ready':
                $this->media->markAssetReady($driver, $assetId, $eventId);
                break;

            case 'video.error':
                $this->media->markAssetError($driver, $assetId, $eventId);
                break;

            case 'video.deleted':
                $this->media->markAssetDeleted($driver, $assetId, $eventId);
                break;

            default:
                Log::debug('media.webhook.ignored', ['type' => $type, 'event' => $eventId]);
        }
    }

    /**
     * الأحداث الأخيرة — للوحة الأدمن.
     */
    public function recentEvents(int $limit = 50): array
    {
        return MediaWebhookEvent::query()
            ->orderByDesc('received_at')
            ->limit($limit)
            ->get()
            ->map(fn (MediaWebhookEvent $e) => [
                'id' => $e->id,
                'provider' => $e->provider,
                'event_type' => $e->event_type,
                'status' => $e->status,
                'error' => $e->error,
                'received_at' => $e->received_at,
            ])
            ->all();
    }

    /**
     * إحصائيات الأحداث.
     */
    public function stats(): array
    {
        return [
            'total' => MediaWebhookEvent::query()->count(),
            'processed' => MediaWebhookEvent::query()->where('status', 'processed')->count(),
            'failed' => MediaWebhookEvent::query()->where('status', 'failed')->count(),
            'last_24h' => MediaWebhookEvent::query()
                ->where('received_at', '>=', now()->subDay())
                ->count(),
        ];
    }
}
