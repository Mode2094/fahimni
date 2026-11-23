<?php

namespace App\Media\Contracts;

/**
 * عقد مزود الوسائط — أي مزود جديد (Mux، Bunny، AWS...) ينفذ هذا
 * العقد فقط، وكل منطق الأعمال يتكلم مع MediaService ولا يعرف المزود.
 *
 * الفيديوهات المسجلة فقط — لا بث مباشر.
 */
interface MediaProviderInterface
{
    /**
     * القدرات المدعومة من المزود.
     *
     * @return array{direct_upload: bool, resumable_upload: bool, recordings: bool, signed_urls: bool, webhooks: bool, thumbnails: bool}
     */
    public function capabilities(): array;

    // ─── Video ─────────────────────────────────────────

    /**
     * تذكرة رفع مباشر (لا يمر الملف عبر سيرفر Laravel).
     *
     * @return array{upload_url: string, asset_id: string, expires_in: int}
     */
    public function createUploadTicket(array $params): array;

    /**
     * بيانات اعتماد رفع مباشر قابل للاستئناف (TUS) لملف واحد — الملف يرفع
     * من المتصفح إلى المزود مباشرة، بلا مرور عبر سيرفر Laravel. التوقيع
     * يُنتَج على السيرفر ولا يكشف مفتاح الـAPI أبداً.
     *
     * @return array{endpoint: string, signature: string, expires: int, library_id: string, video_id: string}
     */
    public function createResumableUploadTicket(string $assetId, int $ttlSeconds = 86400): array;

    /**
     * هل يدعم المزود الرفع المباشر القابل للاستئناف؟
     */
    public function supportsResumableUpload(): bool;

    /**
     * تحقق من حالة الأصل في المزود.
     *
     * @return array{status: string, duration_seconds: int, thumbnail_url: ?string, playback_id: ?string, size_bytes: int}
     */
    public function getVideo(string $assetId): array;

    public function deleteVideo(string $assetId): bool;

    // ─── Playback ──────────────────────────────────────

    /**
     * رابط تشغيل موقّع ومنتهي الصلاحية (HLS/MP4) حسب قدرات المزود.
     */
    public function signedPlaybackUrl(string $assetId, ?string $userId = null, int $ttlSeconds = 900): string;

    public function thumbnailUrl(string $assetId, ?string $userId = null, int $ttlSeconds = 900): ?string;

    // ─── Usage / Health / Webhooks ─────────────────────

    /**
     * استهلاك على مستوى المزود (إن توفرت واجهة رسمية).
     *
     * @return array{storage_bytes?: int, delivery_minutes?: int}
     */
    public function getUsage(): array;

    /**
     * معالجة حدث ويبهوك من المزود. يجب أن يكون التطبيق Idempotent.
     *
     * @return array{handled: bool, event_type: string, asset_id: ?string}
     */
    public function handleWebhook(array $event): array;

    /**
     * فحص اتصال المزود.
     *
     * @return array{ok: bool, message: string, latency_ms: int}
     */
    public function healthCheck(): array;

    public function name(): string;
}