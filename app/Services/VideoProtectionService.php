<?php

namespace App\Services;

use App\Media\MediaProviderManager;
use App\Models\Enrollment;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\VideoContent;
use App\Plans\PlanEngine;
use App\Support\TokenSigner;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Uid\UuidV7;

class VideoProtectionService
{
    public const DEFAULT_TTL = 900; // 15 minutes

    /**
     * إعدادات العلامة المائية على مستوى المعلم (tenant_settings.metadata).
     * الافتراضيات: معطلة، شفافية متوسطة، حركة بطيئة، حجم متوسط.
     */
    public function teacherWatermarkConfig(string $tenantId): array
    {
        $setting = TenantSetting::where('tenant_id', $tenantId)->first();
        $metadata = $setting?->metadata ?? [];

        return [
            'enabled' => (bool) ($metadata['video_watermark_enabled'] ?? false),
            'show_id' => (bool) ($metadata['video_watermark_show_id'] ?? false),
            'opacity' => (string) ($metadata['video_watermark_opacity'] ?? 'medium'),
            'speed' => (string) ($metadata['video_watermark_speed'] ?? 'slow'),
            'size' => (string) ($metadata['video_watermark_size'] ?? 'medium'),
        ];
    }

    public function saveTeacherWatermarkConfig(string $tenantId, array $values): array
    {
        $setting = TenantSetting::firstOrCreate(
            ['tenant_id' => $tenantId],
            ['id' => UuidV7::generate(), 'tenant_id' => $tenantId]
        );
        $metadata = $setting->metadata ?? [];
        $metadata['video_watermark_enabled'] = (bool) ($values['enabled'] ?? false);
        $metadata['video_watermark_show_id'] = (bool) ($values['show_id'] ?? false);
        $metadata['video_watermark_opacity'] = in_array($values['opacity'] ?? null, ['low', 'medium', 'high'], true) ? $values['opacity'] : 'medium';
        $metadata['video_watermark_speed'] = in_array($values['speed'] ?? null, ['slow', 'medium', 'fast'], true) ? $values['speed'] : 'slow';
        $metadata['video_watermark_size'] = in_array($values['size'] ?? null, ['small', 'medium', 'large'], true) ? $values['size'] : 'medium';
        $setting->metadata = $metadata;
        $setting->save();

        Log::info('video.watermark_config_saved', [
            'tenant_id' => $tenantId,
            'enabled' => $metadata['video_watermark_enabled'],
            'event' => $metadata['video_watermark_enabled'] ? 'watermark_enabled' : 'watermark_disabled',
        ]);

        return $this->teacherWatermarkConfig($tenantId);
    }

    /**
     * هل الميزة متاحة في خطة المعلم؟ المصدر الوحيد: قاعدة بيانات الخطط.
     */
    public function watermarkFeatureEnabled(string $tenantId): bool
    {
        return app(PlanEngine::class)->hasFeature($tenantId, 'video_watermark');
    }

    /**
     * القرار النهائي للعلامة المائية:
     * ميزة الخطة && إعداد المعلم && إعداد الفيديو (لا يتجاوز الخطة أبداً).
     */
    public function watermarkFor(VideoContent $video): array
    {
        $featureOn = $this->watermarkFeatureEnabled((string) $video->tenant_id);

        if (! $featureOn) {
            return ['enabled' => false];
        }

        $teacher = $this->teacherWatermarkConfig((string) $video->tenant_id);

        if (! $teacher['enabled']) {
            return ['enabled' => false];
        }

        $videoConfig = $video->config ?? [];
        if (array_key_exists('watermark_enabled', $videoConfig) && (bool) $videoConfig['watermark_enabled'] === false) {
            return ['enabled' => false];
        }

        $opacityMap = ['low' => 0.2, 'medium' => 0.35, 'high' => 0.5];
        $sizeMap = ['small' => '0.75rem', 'medium' => '1rem', 'large' => '1.3rem'];

        return [
            'enabled' => true,
            'opacity' => $opacityMap[$teacher['opacity']] ?? 0.35,
            'speed' => $teacher['speed'],
            'size' => $sizeMap[$teacher['size']] ?? '1rem',
            'show_id' => $teacher['show_id'],
        ];
    }

    public function playbackPayload(VideoContent $video, User $user, ?string $deviceHash = null): array
    {
        $protection = $this->protectionConfig($video);
        $watermark = $this->watermarkFor($video);

        if (! $video->is_uploaded || ! $video->storage_path) {
            return [
                'id' => $video->id,
                'type' => 'external',
                'provider' => $video->provider,
                'provider_video_id' => $video->provider_video_id,
                'title' => $video->title,
                'duration' => (int) $video->duration,
                'protection' => $protection,
            ];
        }

        return [
            'id' => $video->id,
            'type' => 'stream',
            'title' => $video->title,
            'duration' => (int) $video->duration,
            'mime_type' => $video->mime_type,
            'file_size_bytes' => $video->file_size_bytes,
            'stream_url' => $this->streamUrl($video, $user, deviceHash: $deviceHash),
            'expires_in' => self::DEFAULT_TTL,
            'watermark' => $watermark['enabled'] ? [
                'enabled' => true,
                'text' => $this->watermarkText($user, (bool) ($watermark['show_id'] ?? false)),
                'opacity' => $watermark['opacity'],
                'speed' => $watermark['speed'],
                'size' => $watermark['size'],
            ] : ['enabled' => false],
            'protection' => $protection,
        ];
    }

    public function protectionConfig(VideoContent $video): array
    {
        $config = $video->config ?? [];

        return [
            'allow_download' => (bool) ($config['allow_download'] ?? false),
            'watermark_enabled' => (bool) ($config['watermark_enabled'] ?? true),
            'pause_on_hidden' => (bool) ($config['pause_on_hidden'] ?? true),
            'block_picture_in_picture' => (bool) ($config['block_picture_in_picture'] ?? true),
        ];
    }

    /**
     * نص العلامة المائية: اسم الطالب فقط (+ معرف قصير اختيارياً).
     * لا يُعرض البريد الإلكتروني أو بيانات حساسة.
     */
    public function watermarkText(User $user, bool $withId = false): string
    {
        $name = trim((string) $user->name);

        if (! $withId) {
            return $name !== '' ? $name : 'Student';
        }

        $shortId = strtoupper(substr((string) $user->id, 0, 8));

        return $name !== '' ? "{$name} · ID: {$shortId}" : "ID: {$shortId}";
    }

    /**
     * Build a short-lived, HMAC-signed stream URL bound to a specific user,
     * video and device. The URL is useless after expiry and cannot be
     * replayed by another user or device.
     */
    public function streamUrl(VideoContent $video, User $user, int $ttl = self::DEFAULT_TTL, ?string $deviceHash = null): string
    {
        $payload = $this->signPayload($video->id, $user->id, $ttl, $deviceHash);

        $url = URL::to('/api/v1/stream/'.$video->id.'?token='.$payload);

        if ($deviceHash !== null) {
            $url .= '&d='.urlencode($deviceHash);
        }

        return $url;
    }

    public function signPayload(string $videoId, string $userId, int $ttl = self::DEFAULT_TTL, ?string $deviceHash = null): string
    {
        $payload = [
            'v' => $videoId,
            'u' => $userId,
            'exp' => time() + $ttl,
            'n' => bin2hex(random_bytes(8)),
        ];

        if ($deviceHash !== null) {
            $payload['d'] = $deviceHash;
        }

        $encoded = TokenSigner::base64UrlEncode(json_encode($payload));

        return $encoded.'.'.TokenSigner::sign($encoded);
    }

    /**
     * Verify a signed token against a video + user.
     * Returns true only if signature is valid, not expired, and bound to this user + video.
     */
    public function verifyStreamToken(string $token, VideoContent $video, User $user): bool
    {
        $payload = $this->decodeToken($token, $video);

        if (! $payload) {
            return false;
        }

        if (($payload['u'] ?? null) !== $user->id) {
            return false;
        }

        return true;
    }

    /**
     * Verify a signed token for a video and return the bound user, or null.
     * Used by the media endpoint where no Authorization header is available.
     */
    public function resolveUserFromToken(string $token, VideoContent $video, ?string $deviceHash = null): ?User
    {
        $payload = $this->decodeToken($token, $video);

        if (! $payload) {
            return null;
        }

        // رابط مربوط بجهاز معين يجب أن يُستهلك من نفس الجهاز.
        if (array_key_exists('d', $payload)) {
            if ($deviceHash === null || ! hash_equals((string) $payload['d'], (string) $deviceHash)) {
                return null;
            }
        }

        return User::find($payload['u']);
    }

    private function decodeToken(string $token, VideoContent $video): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;

        if (! TokenSigner::matches($encoded, $signature)) {
            return null;
        }

        $decoded = json_decode(TokenSigner::base64UrlDecode($encoded), true);

        if (! is_array($decoded)) {
            return null;
        }

        if (($decoded['v'] ?? null) !== $video->id) {
            return null;
        }

        if (($decoded['exp'] ?? 0) < time()) {
            return null;
        }

        return $decoded;
    }

    /**
     * Access rule: super admin, tenant staff, enrolled student, or free-preview lesson.
     * Also enforces deactivated users, enrollment expiry and drip unlocking.
     */
    public function canAccess(VideoContent $video, User $user): bool
    {
        if ($user->is_active === false) {
            return false;
        }

        if ($user->is_super_admin) {
            return true;
        }

        $isTenantStaff = $user->tenants()
            ->where('tenant_id', $video->tenant_id)
            ->wherePivotIn('role', ['teacher', 'owner', 'admin'])
            ->wherePivot('status', 'active')
            ->exists();

        if ($isTenantStaff) {
            return true;
        }

        $content = $video->lessonContent()->with(['lesson.section'])->first();

        if (! $content || ! $content->lesson || ! $content->lesson->section) {
            return false;
        }

        $lesson = $content->lesson;

        if ($lesson->is_free_preview) {
            return true;
        }

        $enrollment = Enrollment::where('course_id', $lesson->section->course_id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'completed'])
            ->first();

        if (! $enrollment) {
            return false;
        }

        // Drip and expiry enforcement must match the progress/lesson paths.
        return app(CourseAccessService::class)->lessonUnlocked($enrollment, $lesson);
    }

    /**
     * Payload تشغيل لفيديو مستضاف لدى مزود وسائط (Bunny Stream):
     * يعيد رابط HLS مباشر + صورة مصغرة + إعدادات الحماية — للطلاب المصرح لهم.
     */
    public function providerPlaybackPayload(VideoContent $video, User $user, ?string $deviceHash = null): array
    {
        $protection = $this->protectionConfig($video);

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

        // مزود قديم محذوف (cloudflare): لا روابط — الأصل غير قابل للتشغيل.
        if (! app(MediaProviderManager::class)->supports($video->provider)) {
            return [
                'id' => $video->id,
                'type' => 'provider',
                'provider' => $video->provider,
                'status' => 'ready',
                'title' => $video->title,
                'protection' => $protection,
            ];
        }

        $driver = app(MediaProviderManager::class)->provider($video->provider);
        $ttl = (int) config('stream.signed_url_ttl', 900);

        $payload = [
            'id' => $video->id,
            'type' => 'provider',
            'provider' => $video->provider,
            'status' => 'ready',
            'title' => $video->title,
            'duration' => (int) $video->duration,
            'stream_url' => $driver->signedPlaybackUrl($video->provider_video_id, (string) $user->id, $ttl),
            'thumbnail_url' => $driver->thumbnailUrl($video->provider_video_id, (string) $user->id, $ttl),
            'expires_in' => $ttl,
            'protection' => $protection,
        ];

        return $payload;
    }
}
