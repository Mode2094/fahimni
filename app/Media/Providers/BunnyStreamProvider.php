<?php

namespace App\Media\Providers;

use App\Media\Contracts\MediaProviderInterface;
use App\Models\MediaProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * مزود Bunny Stream — الفيديوهات المسجلة والتخزين.
 *
 * الاعتمادات: media_providers (مشفرة AES-256 عبر Laravel Crypt) مع الرجوع
 * إلى الإعدادات البيئية (BUNNY_*). كل المنطق التجاري يتكلم مع MediaService
 * ولا يعرف هذا المزود مباشرة.
 *
 * واجهات Bunny المعتمدة:
 *   - https://video.bunnycdn.com/library/{libraryId}/videos        (إنشاء/قائمة)
 *   - https://video.bunnycdn.com/library/{libraryId}/videos/{id} (رفع مباشر PUT)
 *   - Token Auth: ?token={exp}|{signature} حيث
 *     signature = base64url(HMAC-SHA256(securityKey, "{exp}|{path}"))
 */
class BunnyStreamProvider implements MediaProviderInterface
{
    private const VIDEO_API = 'https://video.bunnycdn.com/library';

    // وثائق Bunny: الرفع يتم عبر PUT إلى video.bunnycdn.com نفسه
    // (upload.bunnycdn.com غير موجود في DNS).
    private const UPLOAD_API = 'https://video.bunnycdn.com/library';

    private ?MediaProvider $model = null;

    public function __construct(?MediaProvider $model = null)
    {
        $this->model = $model;
    }

    public function name(): string
    {
        return 'bunny';
    }

    public function capabilities(): array
    {
        return [
            'direct_upload' => true,
            'recordings' => true,
            'signed_urls' => true,
            'webhooks' => true,
            'thumbnails' => true,
        ];
    }

    // ─── Credentials (encrypted DB > env fallback) ─────

    private function credentials(): array
    {
        if ($this->model?->isConfigured()) {
            return $this->model->credentialArray();
        }

        return [
            'library_id' => config('bunny.library_id', ''),
            'api_key' => config('bunny.api_key', ''),
            'security_key' => config('bunny.security_key', ''),
            'webhook_secret' => config('bunny.webhook_secret', ''),
            'hostname' => config('bunny.hostname', ''), // النطاق المخصص مع Token Auth (اختياري)
        ];
    }

    public function isConfigured(): bool
    {
        $c = $this->credentials();

        return (string) ($c['library_id'] ?? '') !== '' && (string) ($c['api_key'] ?? '') !== '';
    }

    private function libraryId(): string
    {
        return (string) ($this->credentials()['library_id'] ?? '');
    }

    private function apiKey(): string
    {
        return (string) ($this->credentials()['api_key'] ?? '');
    }

    private function securityKey(): string
    {
        return (string) ($this->credentials()['security_key'] ?? '');
    }

    private function hostname(): string
    {
        return (string) ($this->credentials()['hostname'] ?? '');
    }

    private function videoBase(): string
    {
        return self::VIDEO_API.'/'.$this->libraryId();
    }

    private function uploadBase(): string
    {
        return self::UPLOAD_API.'/'.$this->libraryId();
    }

    // ─── HTTP ──────────────────────────────────────────

    private function request(string $method, string $url, array $data = [], bool $json = true): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Bunny Stream is not configured.');
        }

        $builder = Http::withHeaders([
            'AccessKey' => $this->apiKey(),
            'Accept' => 'application/json',
        ])->timeout(60);

        $response = $builder->send($method, $url, $json ? ['json' => $data] : []);

        if ($response->status() >= 500) {
            throw new RuntimeException('Bunny Stream unavailable (HTTP '.$response->status().').');
        }

        $body = $response->json();

        if (! $response->successful() || ($json && is_array($body) && ! empty($body['error']))) {
            $message = is_array($body) ? (string) ($body['message'] ?? ($body['error'] ?? 'unknown')) : 'unknown';
            throw new RuntimeException('Bunny Stream API error ('.$response->status().'): '.$message);
        }

        return is_array($body) ? $body : [];
    }

    // ─── Video ─────────────────────────────────────────

    public function createUploadTicket(array $params): array
    {
        $created = $this->request('POST', $this->videoBase().'/videos', [
            'title' => (string) ($params['title'] ?? 'Untitled video'),
            'collectionId' => null,
            'thumbnailTime' => 0,
        ]);

        $guid = (string) ($created['guid'] ?? '');

        if ($guid === '') {
            throw new RuntimeException('Bunny Stream did not return a video GUID.');
        }

        return [
            // الرفع المباشر: المتصفح يرفع PUT إلى Bunny مباشرة دون مرور السيرفر.
            'upload_url' => $this->uploadBase().'/videos/'.$guid,
            'asset_id' => $guid,
            'expires_in' => (int) ($params['expires_in'] ?? 3600),
        ];
    }

    public function getVideo(string $assetId): array
    {
        $video = $this->request('GET', $this->videoBase().'/videos/'.urlencode($assetId));

        return [
            'status' => $this->normalizeVideoStatus((int) ($video['status'] ?? 0)),
            'duration_seconds' => (int) round((float) ($video['length'] ?? 0)),
            'thumbnail_url' => isset($video['thumbnailFileName']) && $video['thumbnailFileName'] !== ''
                ? 'https://'.($this->hostname() ?: 'video.bunnycdn.com').'/'.$this->libraryId().'/'.(string) $video['thumbnailFileName']
                : null,
            'playback_id' => (string) ($video['guid'] ?? $assetId),
            'size_bytes' => (int) ($video['storageUsage'] ?? 0),
            'title' => $video['title'] ?? null,
            'raw' => $video,
        ];
    }

    public function deleteVideo(string $assetId): bool
    {
        $this->request('DELETE', $this->videoBase().'/videos/'.urlencode($assetId));

        return true;
    }

    public function listVideos(int $perPage = 100, ?string $search = null): array
    {
        return $this->request('GET', $this->videoBase().'/videos?page=1&itemsPerPage='.$perPage.'&search='.urlencode((string) $search));
    }

    // ─── Playback (Token Authentication) ───────────────

    /**
     * رابط HLS موقّع:
     *   https://{host}/library/{lib}/{assetId}/playlist.m3u8?token={exp}|{signature}
     * signature = base64url(HMAC-SHA256(securityKey, "{exp}|{path}"))
     * حيث path = "/library/{lib}/{assetId}/playlist.m3u8".
     */
    public function signedPlaybackUrl(string $assetId, ?string $userId = null, int $ttlSeconds = 900): string
    {
        // المسار الرسمي لـ HLS على Pull Zone المكتبة:
        //   https://{hostname}/{videoId}/playlist.m3u8  (بلا Library ID)
        // حماية المكتبة عبر Referer Domains (Block direct url file access) —
        // إضافة توقيع بلا تفعيل CDN token authentication يكسر الرابط (404).
        $host = $this->hostname() ?: 'video.bunnycdn.com';

        return 'https://'.$host.'/'.$assetId.'/playlist.m3u8';
    }

    /**
     * رابط تشغيل مباشر لمقطع (غير HLS) — مفيد للمعاينة السريعة.
     */
    public function playbackUrl(string $assetId, ?string $userId = null, int $ttlSeconds = 900): string
    {
        $host = $this->hostname() ?: 'video.bunnycdn.com';

        return 'https://'.$host.'/'.$assetId.'/playlist.m3u8';
    }

    /**
     * توقيع URL رسمي متوافق مع Bunny (CDN Token Authentication — Advanced):
     *
     *   token = "HS256-" + Base64URL(HMAC-SHA256(key, signature_path + expires + [ip] + signing_data))
     *
     * - directory: يُوقّع على دليل الفيديو وتوضع الرموز في المسار
     *   (/bcdn_token=...&token_path=...&expires=...) — ضروري لـ HLS.
     * - عكس ذلك: token + expires كمعاملات استعلام.
     */
    private function sign(string $url, int $exp, bool $directory = false): string
    {
        $key = $this->securityKey();

        if ($key === '') {
            return $url;
        }

        $parsed = parse_url($url);
        $base = ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '');
        $path = $parsed['path'] ?? '/';

        $parameters = [];
        if ($directory) {
            $parameters['token_path'] = rtrim($path, '/').'/';
        }

        ksort($parameters);

        $signingData = implode('&', array_map(
            fn ($k, $v) => $k.'='.$v,
            array_keys($parameters),
            $parameters,
        ));

        $signaturePath = $parameters['token_path'] ?? $path;
        $message = $signaturePath.$exp.$signingData;
        $token = 'HS256-'.rtrim(strtr(base64_encode(hash_hmac('sha256', $message, $key, true)), '+/', '-_'), '=');

        if ($directory) {
            $urlData = '&token_path='.rawurlencode($parameters['token_path']);

            return $base.'/bcdn_token='.$token.$urlData.'&expires='.$exp.$path;
        }

        return $base.$path.'?token='.$token.'&expires='.$exp;
    }

    public function thumbnailUrl(string $assetId, ?string $userId = null, int $ttlSeconds = 900): ?string
    {
        $video = null;

        try {
            $video = $this->getVideo($assetId);
        } catch (\Throwable) {
            return null;
        }

        $thumbnail = $video['thumbnail_url'] ?? null;

        if (! $thumbnail) {
            return null;
        }

        // thumbnails على Pull Zone: https://{hostname}/{videoId}/{thumbnailFileName}
        $parsed = parse_url($thumbnail);
        $fileName = basename($parsed['path'] ?? '');

        return 'https://'.$this->hostname().'/'.$assetId.'/'.$fileName;
    }

    private function tokenQuery(string $path, int $exp): string
    {
        if ($this->securityKey() === '') {
            return '';
        }

        // إزالة أي استعلام موجود لإبقاء التوقيع متوافقاً مع المسار الصافي.
        $cleanPath = strtok($path, '?') ?: $path;
        $signature = base64_encode(hash_hmac('sha256', $exp.'|'.$cleanPath, $this->securityKey(), true));
        $signature = rtrim(strtr($signature, '+/', '-_'), '=');

        return '?token='.$exp.'|'.$signature;
    }

    // ─── Usage / Health / Webhooks ─────────────────────

    public function getUsage(): array
    {
        if (! $this->isConfigured()) {
            return [];
        }

        try {
            $videos = $this->listVideos(100);
            $items = $videos['items'] ?? [];

            return [
                'videos_count' => count($items),
                'video_minutes' => (int) round(array_sum(array_column($items, 'length')) / 60),
                'provider' => 'bunny',
            ];
        } catch (\Throwable $e) {
            Log::warning('media.provider.usage_failed', ['provider' => 'bunny', 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * خريطة أحداث Bunny Stream (فيديوهات مسجلة) إلى أحداث المنصة الموحدة.
     */
    public function handleWebhook(array $event): array
    {
        $payload = $event['payload'] ?? $event;

        $type = (string) ($payload['type'] ?? '');

        $mapped = match ($type) {
            'video.ready' => ['type' => 'video.ready', 'asset_id' => (string) ($payload['guid'] ?? ''), 'data' => $payload],
            'video.error' => ['type' => 'video.error', 'asset_id' => (string) ($payload['guid'] ?? ''), 'data' => $payload],
            'video.deleted' => ['type' => 'video.deleted', 'asset_id' => (string) ($payload['guid'] ?? ''), 'data' => $payload],
            default => ['type' => 'unknown', 'asset_id' => (string) ($payload['guid'] ?? ''), 'data' => $payload],
        };

        return ['handled' => $mapped['type'] !== 'unknown', ...$mapped];
    }

    public function healthCheck(): array
    {
        $start = microtime(true);

        try {
            $this->request('GET', $this->videoBase().'/videos?page=1&itemsPerPage=1');

            return [
                'ok' => true,
                'message' => 'Connection OK — Bunny Stream responded.',
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'latency_ms' => (int) round((microtime(true) - $start) * 1000),
            ];
        }
    }

    /**
     * Bunny status: 0=queued, 1=processing, 2=transcoding, 3=finished, 4=error.
     */
    private function normalizeVideoStatus(int $status): string
    {
        return match ($status) {
            3, 4 => 'ready',    // 3 = انتهى الرفع، 4 = اكتمل الترميز وجاهز
            5 => 'error',       // 5 = فشل المعالجة (ملف تالف)
            0, 1, 2 => 'processing',
            default => 'processing',
        };
    }
}
