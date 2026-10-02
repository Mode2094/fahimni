<?php

namespace App\Media;

use App\Media\Contracts\MediaProviderInterface;
use App\Models\FileContent;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\LiveSession;
use App\Models\MediaAsset;
use App\Models\TextContent;
use App\Models\VideoContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Uid\UuidV7;

/**
 * Media Service — المنسق المركزي. كل عمليات الوسائط تمر هنا:
 * رفع، معالجة، حذف، بث، تسجيل، استخدام. لا يعرف أي شيء عن تفاصيل المزود.
 */
class MediaService
{
    public function __construct(
        private readonly MediaProviderManager $manager,
        private readonly MediaQuotaService $quota,
        private readonly MediaUsageService $usage,
    ) {}

    // ─── Video: Upload ─────────────────────────────────

    /**
     * تذكرة رفع مباشر: تحقق الخطة + حجز المساحة + تسجيل الأصل محلياً.
     *
     * @param  array<string, mixed>  $params
     * @return array{asset_id: string, upload_url: string, reservation_id: string, expires_in: int}
     */
    public function createVideoUploadTicket(string $tenantId, ?string $userId, array $params): array
    {
        $size = (int) ($params['file_size'] ?? 0);
        $idempotencyKey = (string) ($params['idempotency_key'] ?? '');

        // ── Idempotency: نفس المفتاح = نفس عملية الرفع = نفس الفيديو ──
        // (double click / retry / network replay / StrictMode) لا ينشئ
        // فيديو ثانياً في Bunny — بل يعيد نفس الأصل والتذكرة.
        if ($idempotencyKey !== '') {
            $existing = MediaAsset::withoutTenant()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                $guid = $existing->provider_asset_id;
                $libraryId = (string) ($this->manager->model($existing->provider)?->credentialArray()['library_id'] ?? '');

                return [
                    'asset_id' => $existing->id,
                    // null = الرفع الفعلي (uploadDirect) يبني الرابط من المكتبة
                    // ولا ينشئ فيديو جديداً أبداً.
                    'upload_url' => $libraryId !== ''
                        ? 'https://video.bunnycdn.com/library/'.$libraryId.'/videos/'.$guid
                        : null,
                    'reservation_id' => (string) ($existing->metadata['reservation_id'] ?? ''),
                    'expires_in' => 3600,
                    'reused' => true,
                ];
            }
        }

        // 1) حصص: عدد الفيديوهات + مساحة (حجز ذري ضد الـ race).
        $this->quota->assertCanUploadVideo($tenantId);
        $reservation = $this->quota->reserveStorage($tenantId, $size, $userId);

        try {
            $driver = $this->manager->requireActiveProvider();

            // 2) تذكرة من المزود (لا يمر الملف عبر السيرفر).
            $ticket = $driver->createUploadTicket([
                'title' => $params['title'] ?? 'Untitled video',
                'expires_in' => (int) ($params['expires_in'] ?? config('stream.upload_ticket_ttl', 3600)),
                'watermark' => $params['watermark'] ?? true,
            ]);

            if (empty($ticket['upload_url']) || empty($ticket['asset_id'])) {
                throw new RuntimeException('Provider returned an invalid upload ticket.');
            }

            // 3) الأصل المرجعي في قاعدة البيانات.
            $asset = MediaAsset::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'course_id' => $params['course_id'] ?? null,
                'lesson_content_id' => $params['lesson_content_id'] ?? null,
                'provider' => $driver->name(),
                'provider_asset_id' => $ticket['asset_id'],
                'kind' => 'video',
                'status' => MediaAsset::STATUS_PROCESSING,
                'title' => $params['title'] ?? 'Untitled video',
                'original_filename' => $params['original_filename'] ?? null,
                'mime_type' => $params['mime_type'] ?? null,
                'size_bytes' => $size,
                'storage_bytes' => 0,
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                'metadata' => [
                    'reservation_id' => $reservation['reservation_id'],
                    'upload_started_at' => now()->toIso8601String(),
                ],
            ]);

            // 4) عدد الفيديوهات يزيد فوراً (الحد يطبّق على العدد).
            $this->usage->applyDelta(
                $tenantId, 'video_count', 1, 'upload_started',
                $userId, 'media_asset', $asset->id,
            );

            return [
                'asset_id' => $asset->id,
                'upload_url' => $ticket['upload_url'],
                'reservation_id' => $reservation['reservation_id'],
                'expires_in' => $ticket['expires_in'],
            ];
        } catch (\Throwable $e) {
            // فشل إنشاء التذكرة → نحرر الحجز فوراً.
            $this->quota->releaseStorage($tenantId, $reservation['reservation_id'], $size);
            throw $e;
        }
    }

    /**
     * ربط أصل بدرس (إنشاء LessonContent + VideoContent) — بعد الرفع أو مسبقاً.
     */
    public function attachToLesson(string $assetId, string $lessonId): MediaAsset
    {
        $asset = MediaAsset::withoutTenant()->findOrFail($assetId);
        $lesson = Lesson::withoutTenant()->findOrFail($lessonId);

        // Cross-tenant binding is never allowed: an asset may only be attached
        // to a lesson that belongs to the same tenant.
        if ($asset->tenant_id !== $lesson->tenant_id) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(403, 'Media asset does not belong to this tenant.');
        }

        // ── Idempotency: الأصل مربوط مسبقاً بالدرس (retry/تكرار الطلب)
        // لا ينشئ LessonContent + VideoContent ثانيين أبداً.
        if ($asset->lesson_content_id) {
            return $asset;
        }

        // ── درس واحد = محتوى رئيسي واحد ──
        // إن كان للدرس محتوى رئيسي آخر (نوع آخر أو محتوى قديم) نستبدله:
        // حذف المحتوى القديم وملحقاته قبل ربط الجديد — لا محتويين أبداً.
        $existing = LessonContent::withoutTenant()
            ->where('lesson_id', $lesson->id)
            ->whereIn('content_type', ['video', 'text', 'file', 'live'])
            ->first();

        if ($existing) {
            VideoContent::withoutTenant()->where('lesson_content_id', $existing->id)->delete();
            TextContent::withoutTenant()->where('lesson_content_id', $existing->id)->delete();
            FileContent::withoutTenant()->where('lesson_content_id', $existing->id)->delete();
            LiveSession::withoutTenant()->where('lesson_content_id', $existing->id)->update(['lesson_content_id' => null]);

            $oldAsset = MediaAsset::withoutTenant()->where('lesson_content_id', $existing->id)->first();
            if ($oldAsset) {
                $oldAsset->update(['lesson_content_id' => null]);
            }

            $existing->delete();
        }

        DB::transaction(function () use ($asset, $lesson) {
            $lessonContent = LessonContent::withoutTenant()->create([
                'id' => UuidV7::generate(),
                'tenant_id' => $lesson->tenant_id,
                'lesson_id' => $lesson->id,
                'content_type' => 'video',
                'sort_order' => 0,
                'is_required' => true,
                'metadata' => ['media_asset_id' => $asset->id],
            ]);

            VideoContent::withoutTenant()->create([
                'id' => UuidV7::generate(),
                'lesson_content_id' => $lessonContent->id,
                'lesson_id' => $lesson->id,
                'tenant_id' => $lesson->tenant_id,
                'provider' => $asset->provider,
                'provider_video_id' => $asset->provider_asset_id,
                'title' => $asset->title,
                'status' => $asset->status,
                'file_size_bytes' => $asset->size_bytes,
                'is_uploaded' => false,
                'config' => [
                    'allow_download' => false,
                    'watermark_enabled' => true,
                    'pause_on_hidden' => true,
                    'block_picture_in_picture' => true,
                ],
            ]);

            $asset->update([
                'lesson_content_id' => $lessonContent->id,
                'course_id' => $lesson->course_id,
                'metadata' => array_merge($asset->metadata ?? [], ['lesson_id' => $lesson->id]),
            ]);
        });

        return $asset->fresh();
    }

    // ─── Video: Status transitions (من الـ Webhook) ─────

    public function markAssetReady(MediaProviderInterface $driver, string $providerAssetId, string $eventId): void
    {
        $asset = $this->findByProviderAsset($driver, $providerAssetId);

        // توافق: أحداث على سجلات قديمة (video_contents بدون media_assets).
        if ($asset === null) {
            $this->legacyVideoContentReady($driver, $providerAssetId);

            return;
        }

        $info = $driver->getVideo($providerAssetId);

        DB::transaction(function () use ($asset, $info, $eventId) {
            $wasReady = $asset->status === MediaAsset::STATUS_READY;

            $asset->update([
                'status' => MediaAsset::STATUS_READY,
                'duration_seconds' => (int) ($info['duration_seconds'] ?? $asset->duration_seconds),
                'thumbnail_url' => $info['thumbnail_url'] ?? $asset->thumbnail_url,
                'playback_id' => $info['playback_id'] ?? $asset->playback_id,
                'storage_bytes' => (int) ($info['size_bytes'] ?? $asset->size_bytes),
                'metadata' => array_merge($asset->metadata ?? [], ['ready_event' => $eventId, 'ready_at' => now()->toIso8601String()]),
            ]);

            // تحديث VideoContent المرتبط إن وُجد (التوافق مع نظام التشغيل القائم).
            if ($asset->lesson_content_id) {
                $content = VideoContent::withoutTenant()
                    ->where('lesson_content_id', $asset->lesson_content_id)
                    ->first();

                if ($content) {
                    $content->update([
                        'status' => 'ready',
                        'duration' => (int) ($info['duration_seconds'] ?? 0),
                        'thumbnail_url' => $info['thumbnail_url'] ?? null,
                        'file_size_bytes' => (int) ($info['size_bytes'] ?? 0),
                    ]);
                }
            }

            // 5) إتمام حجز المساحة → استخدام فعلي (مرة واحدة فقط).
            if (! $wasReady) {
                $reservationId = (string) ($asset->metadata['reservation_id'] ?? '');
                $bytes = (int) ($asset->storage_bytes ?: $asset->size_bytes);

                if ($bytes > 0) {
                    $this->quota->commitStorage(
                        $asset->tenant_id,
                        $reservationId,
                        $bytes,
                        $asset->user_id,
                        'upload_completed',
                        'media_asset',
                        $asset->id,
                    );
                }
            }
        });
    }

    public function markAssetError(MediaProviderInterface $driver, string $providerAssetId, string $eventId): void
    {
        $asset = $this->findByProviderAsset($driver, $providerAssetId);

        if ($asset === null || $asset->status === MediaAsset::STATUS_ERROR) {
            return;
        }

        $this->failAsset($asset, "Provider error event [{$eventId}]");
    }

    public function markAssetDeleted(MediaProviderInterface $driver, string $providerAssetId, string $eventId): void
    {
        $asset = $this->findByProviderAsset($driver, $providerAssetId);

        if ($asset === null) {
            return;
        }

        $this->deleteAssetRecord($asset, "provider_deleted:{$eventId}");
    }

    /**
     * حذف معلمي/إداري: حذف من المزود + تحرير المساحة + حذف السجل.
     */
    public function deleteAsset(string $assetId): void
    {
        $asset = MediaAsset::withoutTenant()->findOrFail($assetId);

        $driver = $this->manager->provider($asset->provider);

        try {
            $driver->deleteVideo($asset->provider_asset_id);
        } catch (\Throwable $e) {
            // حذف غير متزامن عند بعض المزودين: نسجل ونكمل محلياً.
            Log::warning('media.asset.provider_delete_failed', ['asset' => $asset->id, 'error' => $e->getMessage()]);
        }

        $this->deleteAssetRecord($asset, 'teacher_deleted');
    }

    private function deleteAssetRecord(MediaAsset $asset, string $reason): void
    {
        DB::transaction(function () use ($asset, $reason) {
            $wasCommitted = $asset->status === MediaAsset::STATUS_READY && $asset->storage_bytes > 0;

            $asset->update([
                'status' => MediaAsset::STATUS_DELETED,
                'metadata' => array_merge($asset->metadata ?? [], ['deleted_reason' => $reason, 'deleted_at' => now()->toIso8601String()]),
            ]);
            $asset->delete();

            if ($asset->kind === 'video') {
                $this->usage->applyDelta(
                    $asset->tenant_id, 'video_count', -1, 'delete',
                    $asset->user_id, 'media_asset', $asset->id,
                );
            } elseif ($asset->kind === 'live_recording') {
                $this->usage->applyDelta(
                    $asset->tenant_id, 'recording_count', -1, 'delete',
                    $asset->user_id, 'media_asset', $asset->id,
                );
            }

            if ($wasCommitted) {
                $this->usage->applyDelta(
                    $asset->tenant_id, 'storage_bytes', -1 * $asset->storage_bytes, 'delete',
                    $asset->user_id, 'media_asset', $asset->id,
                    -1 * $asset->storage_bytes,
                );
            }
        });
    }

    // ─── Recording ─────────────────────────────────────

    // ─── Helpers ───────────────────────────────────────

    private function findByProviderAsset(MediaProviderInterface $driver, string $providerAssetId): ?MediaAsset
    {
        return MediaAsset::withoutTenant()
            ->where('provider', $driver->name())
            ->where('provider_asset_id', $providerAssetId)
            ->first();
    }

    /**
     * سجلات قديمة: video_contents بدون أصل وسائط — نحدّث الحالة ونسجل
     * الاستخدام حتى تبقى العدادات صحيحة.
     */
    private function legacyVideoContentReady(MediaProviderInterface $driver, string $providerAssetId): void
    {
        $video = VideoContent::withoutTenant()
            ->where('provider', $driver->name())
            ->where('provider_video_id', $providerAssetId)
            ->first();

        if ($video === null) {
            Log::warning('media.asset.unknown', ['provider_asset' => $providerAssetId]);

            return;
        }

        try {
            $info = $driver->getVideo($providerAssetId);
        } catch (\Throwable) {
            $info = [];
        }

        $wasReady = $video->status === 'ready';

        $video->update([
            'status' => 'ready',
            'duration' => (int) ($info['duration_seconds'] ?? $video->duration),
            'thumbnail_url' => $info['thumbnail_url'] ?? $video->thumbnail_url,
        ]);

        if (! $wasReady && $video->tenant_id) {
            $this->usage->applyDelta(
                $video->tenant_id, 'video_count', 1, 'upload_completed_legacy',
                $video->lessonContent?->lesson?->user_id,
                'video_content', $video->id,
            );

            $bytes = (int) ($info['size_bytes'] ?? $video->file_size_bytes);

            if ($bytes > 0) {
                $this->usage->applyDelta(
                    $video->tenant_id, 'storage_bytes', $bytes, 'upload_completed_legacy',
                    $video->lessonContent?->lesson?->user_id,
                    'video_content', $video->id,
                    $bytes,
                );
            }
        }
    }

    private function failAsset(MediaAsset $asset, string $reason): void
    {
        $asset->update([
            'status' => MediaAsset::STATUS_ERROR,
            'metadata' => array_merge($asset->metadata ?? [], ['error_reason' => $reason]),
        ]);

        // تحرير الحجز المعلق إن وُجد.
        $reservationId = (string) ($asset->metadata['reservation_id'] ?? '');

        if ($reservationId !== '') {
            $this->quota->releaseStorage($asset->tenant_id, $reservationId, (int) $asset->size_bytes);
        }
    }

    /**
     * أصول الوسائط لمعلم (لللوحة).
     */
    public function teacherAssets(string $tenantId, array $filters = [], int $limit = 50): array
    {
        $query = MediaAsset::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at');

        if (! empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->limit($limit)->get()->map(fn (MediaAsset $a) => [
            'id' => $a->id,
            'kind' => $a->kind,
            'status' => $a->status,
            'title' => $a->title,
            'provider' => $a->provider,
            'size_bytes' => $a->size_bytes,
            'storage_bytes' => $a->storage_bytes,
            'duration_seconds' => $a->duration_seconds,
            'thumbnail_url' => $a->thumbnail_url,
            'created_at' => $a->created_at,
        ])->all();
    }
}
