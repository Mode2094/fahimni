<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VideoContent;
use App\Models\VideoPlaybackSession;
use App\Plans\Exceptions\QuotaExceededException;
use App\Plans\PlanEngine;
use App\Services\VideoProtectionService;
use App\Support\DevicePolicy;
use App\Support\StoragePathGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VideoStreamController extends Controller
{
    private const SESSION_WINDOW_SECONDS = 900;

    public function __construct(
        private readonly VideoProtectionService $protection,
    ) {}

    /**
     * Authenticated endpoint: returns a short-lived signed stream URL.
     * Security chain (all server-side, none skippable from the client):
     *   auth → feature gate → access (enrollment/expiry/drip) → device policy
     *   → playback session (single quota charge) → signed URL.
     */
    public function playback(Request $request, string $videoId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $video = VideoContent::findOrFail($videoId);

        // 1) ميزة التشغيل الآمن — إن كانت الخطة تعطّلها، لا روابط موقعة إطلاقاً.
        $secureOn = app(PlanEngine::class)->features((string) $video->tenant_id)['secure_playback_enabled'] ?? 'true';
        if (filter_var($secureOn, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false || $secureOn === '0') {
            Log::warning('video.playback_denied', ['event' => 'video_playback_denied', 'user_id' => $user->id, 'video_id' => $video->id, 'reason' => 'secure_playback_disabled']);

            return response()->json(['message' => 'Secure playback is not available in this plan.', 'code' => 'FEATURE_UNAVAILABLE'], 403);
        }

        // 2) صلاحية الوصول: تسجيل / اشتراك / درس / Drip.
        if (! $this->protection->canAccess($video, $user)) {
            Log::warning('video.playback_denied', ['event' => 'video_playback_denied', 'user_id' => $user->id, 'video_id' => $video->id, 'reason' => 'no_access']);

            return response()->json(['message' => 'No access to this video.'], 403);
        }

        // 3) سياسة الأجهزة: تسجيل الجهاز + فرض الحد (لا يُتجاوز بالاتصال المباشر).
        $deviceId = substr((string) ($request->header('X-Device-Id') ?? ''), 0, 100);
        $deviceHash = $deviceId !== '' ? hash('sha256', $deviceId) : null;

        $isStaff = $user->is_super_admin || $user->tenants()
            ->where('tenant_id', $video->tenant_id)
            ->wherePivotIn('role', ['teacher', 'owner', 'admin'])
            ->wherePivot('status', 'active')
            ->exists();

        if (! $isStaff && DevicePolicy::policyEnabled((string) $video->tenant_id)) {
            if ($deviceId === '') {
                Log::warning('video.playback_suspicious', ['event' => 'suspicious_playback', 'user_id' => $user->id, 'video_id' => $video->id, 'reason' => 'missing_device_id']);
            } else {
                $error = DevicePolicy::register($request, (string) $video->tenant_id, $user);
                if ($error) {
                    Log::warning('video.playback_denied', ['event' => 'device_limit_reached', 'user_id' => $user->id, 'video_id' => $video->id]);

                    return response()->json(['message' => $error['message'], 'code' => 'DEVICE_LIMIT_REACHED'], 403);
                }
            }
        }

        // 4) جلسة مشاهدة: الخصم من حصة الدقائق مرة واحدة لكل نافذة جلسة —
        //    التكرار المتكرر لنفس الفيديو لا يستنزف الحصة (حماية من quota-DoS).
        $session = $this->activeSession($video, $user, $deviceHash);

        if (! $session) {
            try {
                app(\App\Media\MediaQuotaService::class)->assertWatchMinutes((string) $video->tenant_id);
                app(\App\Media\MediaUsageService::class)->applyDelta(
                    (string) $video->tenant_id,
                    'delivery_minutes',
                    1,
                    'stream',
                    userId: (string) $user->id,
                    referenceType: 'video',
                    referenceId: $video->id,
                );
            } catch (QuotaExceededException $e) {
                return response()->json(['message' => $e->getMessage()], 403);
            }

            $watermark = $this->protection->watermarkFor($video);
            $session = VideoPlaybackSession::create([
                'tenant_id' => $video->tenant_id,
                'user_id' => $user->id,
                'video_id' => $video->id,
                'lesson_id' => $video->lesson_id,
                'course_id' => $video->lessonContent?->lesson?->section?->course_id,
                'device_id' => $deviceId !== '' ? $deviceId : null,
                'device_hash' => $deviceHash,
                'watermark_enabled' => $watermark['enabled'] ?? false,
                'watermark_text' => ($watermark['enabled'] ?? false)
                    ? $this->protection->watermarkText($user, (bool) ($watermark['show_id'] ?? false))
                    : null,
                'started_at' => now(),
                'last_seen_at' => now(),
                'expires_at' => now()->addSeconds(self::SESSION_WINDOW_SECONDS),
            ]);

            Log::info('video.session_created', [
                'event' => 'video_session_created',
                'user_id' => $user->id,
                'video_id' => $video->id,
                'watermark' => $session->watermark_enabled,
            ]);
        } else {
            $session->update(['last_seen_at' => now()]);
        }

        // 5) الرد حسب نوع المحتوى:
        //    - local MP4 (is_uploaded) → تدفق موقّع عبر وسيط النظام (playbackPayload).
        //    - روابط خارجية (youtube/vimeo) → payload خارجي يُشغَّل داخل iframe.
        //    - بث مزوّد (bunny/cloudflare) → روابط موقّعة من المزوّد (providerPayload).
        if ($video->is_uploaded || in_array($video->provider, ['youtube', 'vimeo'], true)) {
            return response()->json([
                'playback' => $this->protection->playbackPayload($video, $user, $deviceHash),
            ]);
        }

        return response()->json([
            'playback' => $this->providerPayload($video, $user),
        ]);
    }

    private function activeSession(VideoContent $video, User $user, ?string $deviceHash): ?VideoPlaybackSession
    {
        return VideoPlaybackSession::where('user_id', $user->id)
            ->where('video_id', $video->id)
            ->where('expires_at', '>', now())
            ->where(function ($q) use ($deviceHash) {
                $q->whereNull('device_hash')
                    ->orWhere('device_hash', $deviceHash);
            })
            ->latest('started_at')
            ->first();
    }

    private function providerPayload(VideoContent $video, User $user): array
    {
        $protection = $this->protection->protectionConfig($video);
        $watermark = $this->protection->watermarkFor($video);

        if ($video->status !== 'ready') {
            return [
                'id' => $video->id,
                'type' => 'provider',
                'provider' => $video->provider,
                'status' => $video->status,
                'title' => $video->title,
                'protection' => $protection,
            ];
        }

        // مزود غير مدعوم (مزودات قديمة محذوفة مثل cloudflare): لا نحاول
        // بناء روابط تشغيل عبر مزود آخر — البيانات القديمة تبقى بلا تشغيل.
        $manager = app(\App\Media\MediaProviderManager::class);

        if (! $manager->supports($video->provider)) {
            return [
                'id' => $video->id,
                'type' => 'provider',
                'provider' => $video->provider,
                'status' => 'ready',
                'title' => $video->title,
                'protection' => $protection,
            ];
        }

        $driver = $manager->provider($video->provider);
        $ttl = (int) config('stream.signed_url_ttl', 900);

        return [
            'id' => $video->id,
            'type' => 'provider',
            'provider' => $video->provider,
            'status' => 'ready',
            'title' => $video->title,
            'duration' => (int) $video->duration,
            'thumbnail_url' => $driver->thumbnailUrl($video->provider_video_id, $user->id, $ttl),
            'stream_url' => $driver->signedPlaybackUrl($video->provider_video_id, $user->id, $ttl),
            'expires_in' => $ttl,
            'watermark' => $watermark['enabled'] ? [
                'enabled' => true,
                'text' => $this->protection->watermarkText($user, (bool) ($watermark['show_id'] ?? false)),
                'opacity' => $watermark['opacity'],
                'speed' => $watermark['speed'],
                'size' => $watermark['size'],
            ] : ['enabled' => false],
            'protection' => $protection,
        ];
    }

    /**
     * Media endpoint (no Authorization header possible for <video>):
     * verifies the signed token (video + user + expiry + device), checks access
     * rules, then streams the file with HTTP Range support.
     * Never sends Content-Disposition: attachment.
     */
    public function serve(Request $request, string $videoId): Response|StreamedResponse|JsonResponse
    {
        $video = VideoContent::find($videoId);

        if (! $video || ! $video->is_uploaded || ! $video->storage_path) {
            return response()->json(['message' => 'Video not found.'], 404);
        }

        $token = (string) $request->query('token', '');
        $deviceHash = (string) $request->query('d', '') ?: null;

        $user = $this->protection->resolveUserFromToken($token, $video, $deviceHash);

        if (! $user) {
            Log::warning('video.stream_denied', ['event' => 'video_token_rejected', 'video_id' => $video->id, 'reason' => 'invalid_expired_or_device_mismatch']);

            return response()->json(['message' => 'Invalid or expired stream token.'], 403);
        }

        if (! $this->protection->canAccess($video, $user)) {
            return response()->json(['message' => 'No access to this video.'], 403);
        }

        // Guard against traversal: the stored path must stay under the
        // video/lesson-file roots of the private disk.
        if (! StoragePathGuard::isSafe($video->storage_path, ['videos', 'lesson-files', 'uploads'])) {
            return response()->json(['message' => 'Invalid video path.'], 404);
        }

        // Tenant-owned files must live under the video's own tenant folder.
        if (str_starts_with((string) $video->storage_path, 'lesson-files/')
            && ! str_starts_with((string) $video->storage_path, 'lesson-files/'.$video->tenant_id.'/')) {
            return response()->json(['message' => 'Invalid video path.'], 404);
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($video->storage_path)) {
            return response()->json(['message' => 'Video file missing.'], 404);
        }

        $size = $disk->size($video->storage_path);
        $mime = $video->mime_type ?? 'video/mp4';

        $baseHeaders = [
            'Content-Type' => $mime,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'X-Frame-Options' => 'DENY',
            'X-Robots-Tag' => 'noindex, nofollow',
        ];

        $range = $request->header('Range');
        $start = 0;
        $end = $size - 1;

        if ($range && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            if ($m[1] !== '') {
                $start = (int) $m[1];
            } elseif ($m[2] !== '') {
                // Suffix range (bytes=-500): the LAST N bytes.
                $start = max(0, $size - (int) $m[2]);
            }
            if ($m[2] !== '') {
                $end = min((int) $m[2], $size - 1);
            }

            if ($start > $end || $start >= $size) {
                return response('', 416)
                    ->header('Content-Range', "bytes */{$size}");
            }
        }

        $length = $end - $start + 1;
        $stream = $disk->readStream($video->storage_path);

        if (! $stream) {
            return response()->json(['message' => 'Video file unreadable.'], 500);
        }

        $headers = $baseHeaders + [
            'Content-Length' => (string) $length,
            'Content-Range' => "bytes {$start}-{$end}/{$size}",
        ];

        $status = $range ? 206 : 200;

        if ($start > 0) {
            fseek($stream, $start);
        }

        return response()->stream(function () use ($stream, $length) {
            $remaining = $length;

            while ($remaining > 0 && ! feof($stream)) {
                $chunk = fread($stream, (int) min(1048576, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
                flush();
            }

            fclose($stream);
        }, $status, $headers);
    }
}
