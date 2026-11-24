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
        $clientIdempotencyKey = (string) ($params['idempotency_key'] ?? '');
        // The DB key is globally unique. Namespace the browser's stable key by
        // tenant before storing or looking it up so equal filenames in two
        // workspaces can never reuse each other's provider asset.
        $idempotencyKey = $clientIdempotencyKey !== ''
            ? hash('sha256', $tenantId."\0".$clientIdempotencyKey)
            : null;

        // ── Idempotency: نفس المفتاح = نفس عملية الرفع = نفس الفيديو ──
        // (double click / retry / network replay / StrictMode) لا ينشئ
        // فيديو ثانياً في Bunny — بل يعيد نفس الأصل والتذكرة.
        if ($idempotencyKey !== null) {
            $existing = MediaAsset::withoutTenant()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            // Migrate a same-tenant key created by an older release in place.
            if (! $existing) {
                $existing = MediaAsset::withoutTenant()
                    ->where('tenant_id', $tenantId)
                    ->where('idempotency_key', $clientIdempotencyKey)
                    ->first();
                $existing?->update(['idempotency_key' => $idempotencyKey]);
            }

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
                'idempotency_key' => $idempotencyKey,
                'metadata' => [
                    'reservation_id' => $reservation['reservation_id'],
                    'upload_started_at' => now()->toIso8601String(),
                    ...(! empty($params['replacement_lesson_content_id']) ? [
                        'replacement_lesson_content_id' => (string) $params['replacement_lesson_content_id'],
                        'replacement_lesson_id' => (string) ($params['replacement_lesson_id'] ?? ''),
                    ] : []),
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
     * تذكرة رفع مباشر قابل للاستئناف (TUS).
     *
     * نفس مسار createVideoUploadTicket بالكامل — الحصص، الحجز الذري، الأصل
     * المرجعي، والـIdempotency — مع بيانات اعتماد TUS ليَرْفع المتصفح الملف
     * مباشرة إلى المزود (لا يمر الفيديو عبر السيرفر)، ويستأنف بعد انقطاع
     * الاتصال بنفس الفيديو دون تكرار.
     *
     * @param  array<string, mixed>  $params
     * @return array{asset_id: string, upload_url: ?string, reservation_id: string, expires_in: int, reused?: bool, resumable: array{endpoint: string, signature: string, expires: int, library_id: string, video_id: string}}
     */
    public function createResumableUploadTicket(string $tenantId, ?string $userId, array $params): array
    {
        $ticket = $this->createVideoUploadTicket($tenantId, $userId, $params);

        $asset = MediaAsset::withoutTenant()->findOrFail($ticket['asset_id']);
        $driver = $this->manager->provider($asset->provider);

        if (! $driver->supportsResumableUpload()) {
            throw new RuntimeException('The active media provider does not support resumable uploads.');
        }

        $ticket['resumable'] = $driver->createResumableUploadTicket(
            (string) $asset->provider_asset_id,
            (int) ($params['resumable_ttl'] ?? 86400),
        );

        return $ticket;
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

        // ── Idempotency: الأصل مربوط مسبقاً بالدرس (retry/تكرار الطلب).
        if ($asset->lesson_content_id) {
            $linkedContent = LessonContent::withoutTenant()->find($asset->lesson_content_id);
            if (! $linkedContent || $linkedContent->lesson_id !== $lesson->id) {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(409, 'Media asset is already attached to another lesson.');
            }

            return $asset;
        }

        if (in_array($asset->status, [MediaAsset::STATUS_ERROR, MediaAsset::STATUS_DELETED], true)) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(409, 'This video upload did not complete successfully.');
        }

        // ── درس واحد = محتوى رئيسي واحد ──
        // An upload targeting an existing video is staged. The previous VideoContent
        // remains playable until the provider confirms the new asset is ready.
        $replacementContentId = (string) ($asset->metadata['replacement_lesson_content_id'] ?? '');
        $replacementLessonId = (string) ($asset->metadata['replacement_lesson_id'] ?? '');
        if ($replacementContentId !== '' && $replacementLessonId !== '' && $replacementLessonId !== $lesson->id) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(409, 'This upload targets a different lesson.');
        }

        $existing = $replacementContentId !== ''
            ? LessonContent::withoutTenant()
                ->where('tenant_id', $lesson->tenant_id)
                ->where('lesson_id', $lesson->id)
                ->where('content_type', 'video')
                ->whereKey($replacementContentId)
                ->first()
            : LessonContent::withoutTenant()
                ->where('tenant_id', $lesson->tenant_id)
                ->where('lesson_id', $lesson->id)
                ->whereIn('content_type', ['video', 'text', 'file', 'live'])
                ->first();

        if ($replacementContentId !== '' && ! $existing) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(409, 'The video being replaced is no longer available.');
        }

        if ($existing && $existing->content_type === 'video') {
            if ($asset->status === MediaAsset::STATUS_PROCESSING) {
                $this->stageVideoReplacement($asset, $lesson, $existing);

                return $asset->fresh();
            }

            if ($asset->status === MediaAsset::STATUS_READY) {
                $oldAssetId = DB::transaction(fn () => $this->activateVideoReplacement(
                    $asset,
                    $lesson->id,
                    $existing->id,
                    [
                        'duration_seconds' => $asset->duration_seconds,
                        'size_bytes' => $asset->storage_bytes ?: $asset->size_bytes,
                        'thumbnail_url' => $asset->thumbnail_url,
                    ],
                ));

                if ($oldAssetId) {
                    $this->deleteAsset($oldAssetId);
                }

                return $asset->fresh();
            }

            throw new \Symfony\Component\HttpKernel\Exception\HttpException(409, 'This video upload is not ready to replace lesson content.');
        }

        if ($replacementContentId !== '') {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(409, 'The lesson content is no longer a video.');
        }

        if ($asset->status !== MediaAsset::STATUS_PROCESSING && $asset->status !== MediaAsset::STATUS_READY) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(409, 'This video upload is not available.');
        }

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

    private function stageVideoReplacement(MediaAsset $asset, Lesson $lesson, LessonContent $content): void
    {
        DB::transaction(function () use ($asset, $lesson, $content) {
            $lockedAsset = MediaAsset::withoutTenant()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $lockedContent = LessonContent::withoutTenant()
                ->where('tenant_id', $lesson->tenant_id)
                ->where('lesson_id', $lesson->id)
                ->where('content_type', 'video')
                ->whereKey($content->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedAsset->lesson_content_id) {
                if ($lockedAsset->lesson_content_id !== $lockedContent->id) {
                    throw new \Symfony\Component\HttpKernel\Exception\HttpException(409, 'Media asset is already attached to another lesson.');
                }

                return;
            }

            $metadata = $lockedAsset->metadata ?? [];
            $targetContentId = (string) ($metadata['replacement_lesson_content_id'] ?? '');
            $targetLessonId = (string) ($metadata['replacement_lesson_id'] ?? '');

            if (($targetContentId !== '' && $targetContentId !== $lockedContent->id)
                || ($targetLessonId !== '' && $targetLessonId !== $lesson->id)) {
                throw new \Symfony\Component\HttpKernel\Exception\HttpException(409, 'This upload already targets another lesson video.');
            }

            $metadata['replacement_lesson_content_id'] = $lockedContent->id;
            $metadata['replacement_lesson_id'] = $lesson->id;

            $lockedAsset->update([
                'course_id' => $lesson->course_id,
                'metadata' => $metadata,
            ]);
        });
    }

    /**
     * Activate a processed replacement on the existing LessonContent/VideoContent
     * rows so sort order, title, exercise links, and student progress stay intact.
     * Caller must hold a DB transaction.
     */
    private function activateVideoReplacement(MediaAsset $asset, string $lessonId, string $contentId, array $info): ?string
    {
        $lesson = Lesson::withoutTenant()
            ->where('tenant_id', $asset->tenant_id)
            ->find($lessonId);
        $content = LessonContent::withoutTenant()
            ->where('tenant_id', $asset->tenant_id)
            ->where('lesson_id', $lessonId)
            ->where('content_type', 'video')
            ->whereKey($contentId)
            ->lockForUpdate()
            ->first();

        if (! $lesson || ! $content) {
            $metadata = $asset->metadata ?? [];
            $metadata['replacement_failed_at'] = now()->toIso8601String();
            $metadata['replacement_failure'] = 'target_not_available';
            $asset->update(['metadata' => $metadata]);

            return null;
        }

        $oldAsset = MediaAsset::withoutTenant()
            ->where('tenant_id', $asset->tenant_id)
            ->where('lesson_content_id', $content->id)
            ->where('id', '!=', $asset->id)
            ->lockForUpdate()
            ->first();
        $video = VideoContent::withoutTenant()
            ->where('lesson_content_id', $content->id)
            ->lockForUpdate()
            ->first();

        if ($oldAsset) {
            $oldAsset->update(['lesson_content_id' => null]);
        }

        $videoData = [
            'lesson_id' => $lesson->id,
            'tenant_id' => $lesson->tenant_id,
            'provider' => $asset->provider,
            'provider_video_id' => $asset->provider_asset_id,
            'title' => $asset->title,
            'status' => MediaAsset::STATUS_READY,
            'duration' => (int) ($info['duration_seconds'] ?? $asset->duration_seconds),
            'thumbnail_url' => $info['thumbnail_url'] ?? $asset->thumbnail_url,
            'file_size_bytes' => (int) ($info['size_bytes'] ?? ($asset->storage_bytes ?: $asset->size_bytes)),
            'storage_path' => null,
            'original_name' => $asset->original_filename,
            'mime_type' => $asset->mime_type,
            'is_uploaded' => false,
            'is_shared_reference' => false,
        ];

        if ($video) {
            // Keep the lesson's existing video row and its non-provider settings.
            $video->update($videoData);
        } else {
            VideoContent::withoutTenant()->create([
                'id' => UuidV7::generate(),
                'lesson_content_id' => $content->id,
                'config' => [
                    'allow_download' => false,
                    'watermark_enabled' => true,
                    'pause_on_hidden' => true,
                    'block_picture_in_picture' => true,
                ],
                ...$videoData,
            ]);
        }

        $metadata = $asset->metadata ?? [];
        unset($metadata['replacement_lesson_content_id'], $metadata['replacement_lesson_id'], $metadata['replacement_failure']);
        $metadata['lesson_id'] = $lesson->id;
        $metadata['replacement_activated_at'] = now()->toIso8601String();

        $asset->update([
            'lesson_content_id' => $content->id,
            'course_id' => $lesson->course_id,
            'metadata' => $metadata,
        ]);

        return $oldAsset?->id;
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
        $oldAssetId = null;

        DB::transaction(function () use ($asset, $info, $eventId, &$oldAssetId) {
            $asset = MediaAsset::withoutTenant()->whereKey($asset->id)->lockForUpdate()->first();
            if (! $asset) {
                return;
            }

            $wasReady = $asset->status === MediaAsset::STATUS_READY;

            $asset->update([
                'status' => MediaAsset::STATUS_READY,
                'duration_seconds' => (int) ($info['duration_seconds'] ?? $asset->duration_seconds),
                'thumbnail_url' => $info['thumbnail_url'] ?? $asset->thumbnail_url,
                'playback_id' => $info['playback_id'] ?? $asset->playback_id,
                'storage_bytes' => (int) ($info['size_bytes'] ?? $asset->size_bytes),
                'metadata' => array_merge($asset->metadata ?? [], ['ready_event' => $eventId, 'ready_at' => now()->toIso8601String()]),
            ]);

            $replacementContentId = (string) ($asset->metadata['replacement_lesson_content_id'] ?? '');
            $replacementLessonId = (string) ($asset->metadata['replacement_lesson_id'] ?? '');

            if ($replacementContentId !== '' && $replacementLessonId !== '') {
                $oldAssetId = $this->activateVideoReplacement(
                    $asset,
                    $replacementLessonId,
                    $replacementContentId,
                    $info,
                );
            } elseif ($asset->lesson_content_id) {
                // Existing/non-replacement uploads keep the original lifecycle.
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

        // Provider I/O happens only after the database has switched the stable
        // lesson/video rows to the confirmed-ready replacement.
        if ($oldAssetId) {
            $this->deleteAsset($oldAssetId);
        }
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

        // A duplicated lesson/course reuses the SAME provider video (no
        // re-upload, no extra Bunny copy), so destroying it here would silently
        // break every other lesson that still plays it. When another video
        // content references the asset, detach it from this lesson and keep the
        // provider video alive.
        if ($this->hasOtherVideoReferences($asset)) {
            $asset->update(['lesson_content_id' => null]);

            Log::info('media.asset.kept_for_references', [
                'asset' => $asset->id,
                'provider_asset' => $asset->provider_asset_id,
            ]);

            return;
        }

        $driver = $this->manager->provider($asset->provider);

        try {
            $driver->deleteVideo($asset->provider_asset_id);
        } catch (\Throwable $e) {
            // حذف غير متزامن عند بعض المزودين: نسجل ونكمل محلياً.
            Log::warning('media.asset.provider_delete_failed', ['asset' => $asset->id, 'error' => $e->getMessage()]);
        }

        $this->deleteAssetRecord($asset, 'teacher_deleted');
    }

    /**
     * True when some OTHER video content still points at the same provider
     * video. Mirrors created by duplication are the usual reason — but any
     * legitimate second use must be honoured, never deleted underneath.
     */
    private function hasOtherVideoReferences(MediaAsset $asset): bool
    {
        if (! $asset->provider_asset_id) {
            return false;
        }

        return VideoContent::withoutTenant()
            ->where('provider', $asset->provider)
            ->where('provider_video_id', $asset->provider_asset_id)
            ->when(
                $asset->lesson_content_id !== null,
                // Exclude the row this deletion belongs to; anything else counts.
                fn ($q) => $q->where('lesson_content_id', '!=', $asset->lesson_content_id),
            )
            ->exists();
    }

    private function deleteAssetRecord(MediaAsset $asset, string $reason): void
    {
        DB::transaction(function () use ($asset, $reason) {
            $wasCommitted = $asset->status === MediaAsset::STATUS_READY && $asset->storage_bytes > 0;

            // أصل ما زال قيد الرفع/المعالجة (مثلاً أُلغيت رفعة جماعية): مساحة
            // الحجز لم تُحتسب بعد، فنحرّر الحجز المعلّق فوراً حتى لا تبقى
            // مساحة محجوزة بلا داعٍ (cleanup للأصول غير المكتملة).
            if ($asset->status === MediaAsset::STATUS_PROCESSING) {
                $reservationId = (string) ($asset->metadata['reservation_id'] ?? '');

                if ($reservationId !== '') {
                    $this->quota->releaseStorage($asset->tenant_id, $reservationId, (int) $asset->size_bytes);
                }
            }

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
