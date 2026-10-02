<?php

namespace Tests\Feature;

use App\Media\MediaProviderManager;
use App\Media\MediaService;
use App\Media\MediaUsageService;
use App\Media\MediaWebhookService;
use App\Media\Providers\BunnyStreamProvider;
use App\Models\MediaAsset;
use App\Models\MediaProvider;
use App\Models\PlanFeature;
use App\Models\TenantSubscription;
use App\Plans\Exceptions\QuotaExceededException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * اختبارات مزود Bunny Stream: الرفع، الحذف، الحدود، الروابط الموقّعة،
 * الويبهوك (Secret + Idempotency + Replay)، وصلاحيات التشغيل.
 */
class BunnyMediaProviderTest extends TenantTestCase
{
    private const LIBRARY = '112233';

    private function setupBunnyProvider(bool $withSecurityKey = true): void
    {
        $creds = [
            'library_id' => self::LIBRARY,
            'api_key' => 'test-api-key',
            'security_key' => $withSecurityKey ? 'test-security-key' : '',
            'hostname' => '',
        ];

        MediaProvider::where('provider', 'bunny')->delete();

        MediaProvider::create([
            'provider' => 'bunny',
            'label' => 'Bunny Stream',
            'enabled' => true,
            'credentials' => json_encode($creds),
            'webhook_secret' => 'bunny-webhook-secret',
            'environment' => 'sandbox',
            'connection_status' => 'unknown',
            'config' => [],
            'metadata' => [],
        ]);
    }

    private function fakeBunny(): void
    {
        Http::fake([
            // الفيديو: إنشاء/قائمة/فرد — يستجيب ببيانات فيديو جاهز.
            '*bunnycdn.com/*/videos*' => Http::response(['guid' => 'bunny-video-1', 'status' => 3, 'length' => 120.5, 'storageUsage' => 2500000, 'thumbnailFileName' => 'thumb.jpg', 'title' => 'Video'], 200),
            '*bunnycdn.com/*' => Http::response([], 200),
        ]);
    }

    public function test_bunny_is_registered_as_provider_and_default(): void
    {
        $manager = app(MediaProviderManager::class);

        $this->assertTrue($manager->supports('bunny'));
        $this->assertInstanceOf(BunnyStreamProvider::class, $manager->provider('bunny'));
    }

    public function test_bunny_creates_upload_ticket_for_direct_upload(): void
    {
        $this->setupBunnyProvider();
        $this->fakeBunny();

        $ticket = app(MediaProviderManager::class)->provider('bunny')->createUploadTicket([
            'title' => 'My video',
            'expires_in' => 3600,
        ]);

        $this->assertSame('bunny-video-1', $ticket['asset_id']);
        $this->assertStringContainsString('video.bunnycdn.com/library/'.self::LIBRARY.'/videos/bunny-video-1', $ticket['upload_url']);
        $this->assertSame(3600, $ticket['expires_in']);
    }

    public function test_bunny_get_video_normalizes_status_and_metadata(): void
    {
        $this->setupBunnyProvider();
        $this->fakeBunny();

        $video = app(MediaProviderManager::class)->provider('bunny')->getVideo('bunny-video-1');

        $this->assertSame('ready', $video['status']);
        $this->assertSame(121, $video['duration_seconds']);
        $this->assertSame(2500000, $video['size_bytes']);
        $this->assertNotNull($video['thumbnail_url']);
    }

    public function test_bunny_delete_video_calls_api_and_returns_true(): void
    {
        $this->setupBunnyProvider();
        Http::fake([
            'video.bunnycdn.com/*' => Http::response([], 200),
        ]);

        $deleted = app(MediaProviderManager::class)->provider('bunny')->deleteVideo('bunny-video-1');

        $this->assertTrue($deleted);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/videos/bunny-video-1'));
    }

    public function test_signed_playback_url_contains_expiry_and_hmac_token(): void
    {
        $this->setupBunnyProvider();

        $url = app(MediaProviderManager::class)->provider('bunny')
            ->signedPlaybackUrl('bunny-video-1', 'user-1', 900);

        // المسار الرسمي لـ HLS على Pull Zone المكتبة:
        // https://{hostname}/{videoId}/playlist.m3u8 (بلا Library ID).
        $this->assertSame(
            'https://video.bunnycdn.com/bunny-video-1/playlist.m3u8',
            $url
        );

        // بدون مفتاح توقيع: نفس الرابط (الحماية عبر Referer Domains).
        $this->setupBunnyProvider(withSecurityKey: false);
        $plain = app(MediaProviderManager::class)->provider('bunny')
            ->signedPlaybackUrl('bunny-video-1', 'user-1', 900);
        $this->assertSame($url, $plain);
    }

    public function test_video_webhook_events_are_mapped(): void
    {
        $this->setupBunnyProvider();

        $provider = app(MediaProviderManager::class)->provider('bunny');

        // أحداث الفيديو
        $ready = $provider->handleWebhook(['type' => 'video.ready', 'guid' => 'bunny-video-1']);
        $this->assertSame('video.ready', $ready['type']);
        $this->assertSame('bunny-video-1', $ready['asset_id']);
    }

    public function test_webhook_secret_validation_rejects_forged_requests(): void
    {
        $this->setupBunnyProvider();

        $service = app(MediaWebhookService::class);

        // سر خاطئ في الرابط → رفض
        $result = $service->handle(
            $this->makeWebhookRequest('bunny', ['type' => 'video.ready'], secret: 'forged'),
            'bunny',
        );

        $this->assertFalse($result['accepted']);

        // سر صحيح في الرابط → قبول
        $payload = ['type' => 'video.ready', 'guid' => 'bunny-video-1'];
        $result = $service->handle(
            $this->makeWebhookRequest('bunny', $payload, secret: 'bunny-webhook-secret'),
            'bunny',
        );

        $this->assertTrue($result['accepted']);
    }

    public function test_webhook_replay_is_rejected_by_event_hash(): void
    {
        $this->setupBunnyProvider();

        $service = app(MediaWebhookService::class);
        $payload = ['type' => 'video.ready', 'guid' => 'bunny-video-1'];

        $first = $service->handle($this->makeWebhookRequest('bunny', $payload, 'bunny-webhook-secret'), 'bunny');
        $second = $service->handle($this->makeWebhookRequest('bunny', $payload, 'bunny-webhook-secret'), 'bunny');

        $this->assertTrue($first['accepted']);

        // إعادة نفس الحدث → مقبول لكن مُتخطَّى (Idempotency/Replay protection)
        $this->assertTrue($second['accepted']);
        $this->assertSame('duplicate_skipped', $second['reason']);
    }

    public function test_storage_limit_is_enforced_at_backend_upload_ticket(): void
    {
        $this->setupBunnyProvider();
        $this->fakeBunny();

        $tenant = $this->makeTenant();
        $user = $this->makeUser(['is_super_admin' => true]);
        $this->attachToTenant($user, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        // خطة بسعة صغيرة (1 MB) — استخدام صغير (0.9 MB)
        $plan = TenantSubscription::where('tenant_id', $tenant->id)->first()->plan;
        PlanFeature::where('plan_id', $plan->id)->where('feature_key', 'storage_limit_mb')
            ->update(['feature_value' => '1']);

        MediaAsset::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'provider' => 'bunny',
            'provider_asset_id' => 'existing-video',
            'kind' => 'video',
            'status' => 'ready',
            'storage_bytes' => (int) (0.9 * 1024 * 1024),
            'size_bytes' => (int) (0.9 * 1024 * 1024),
            'metadata' => [],
        ]);

        // تحديث دفتر الاستخدام (ledger) ليعكس الأصل الموجود — 0.9MB من أصل 1MB.
        app(MediaUsageService::class)->applyDelta(
            $tenant->id,
            'storage_bytes',
            (int) (0.9 * 1024 * 1024),
            'upload',
        );

        $this->expectException(QuotaExceededException::class);

        app(MediaService::class)->createVideoUploadTicket($tenant->id, $user->id, [
            'title' => 'Too big',
            'file_size' => 1024 * 1024, // 1MB — يتجاوز الحد بعد استهلاك 0.9MB من أصل 1MB
        ]);
    }

    public function test_credentials_are_stored_encrypted_aes256(): void
    {
        $this->setupBunnyProvider();

        $model = MediaProvider::where('provider', 'bunny')->first();

        // القيمة الخام في DB مشفرة — لا نص صريح
        $raw = $model->getRawOriginal('credentials');
        $this->assertStringNotContainsString('test-api-key', $raw);
        $this->assertStringNotContainsString('test-security-key', $raw);

        // والوصول عبر الموديل يفك التشفير
        $this->assertSame('test-api-key', $model->credentialArray()['api_key'] ?? null);
    }

    private function makeWebhookRequest(string $provider, array $payload, ?string $secret = null): Request
    {
        $uri = '/api/v1/media/webhook/'.$provider;

        if ($secret !== null) {
            $uri .= '?secret='.urlencode($secret);
        }

        $request = Request::create(
            $uri,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($payload),
        );

        return $request;
    }
}
