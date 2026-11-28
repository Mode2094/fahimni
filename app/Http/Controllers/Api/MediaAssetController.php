<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Media\MediaQuotaService;
use App\Media\MediaSecurityService;
use App\Media\MediaService;
use App\Media\MediaUsageService;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\MediaAsset;
use App\Models\UsageWarningRule;
use App\Models\VideoContent;
use App\Plans\Exceptions\FeatureUnavailableException;
use App\Plans\Exceptions\QuotaExceededException;
use App\Services\SaasCostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * لوحة الوسائط للمعلم: الأصول، الاستخدام، الرفع، البث، الحذف.
 */
class MediaAssetController extends Controller
{
    public function __construct(
        private readonly MediaService $media,
        private readonly MediaQuotaService $quota,
        private readonly MediaUsageService $usage,
        private readonly MediaSecurityService $security,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id');

        return response()->json([
            'assets' => $this->media->teacherAssets($tenantId, $request->only(['kind', 'status'])),
            'usage' => $this->quota->usageStatus($tenantId),
        ]);
    }

    /**
     * تذكرة رفع مباشر للفيديو (مع حجز المساحة).
     */
    public function uploadTicket(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id');

        $validated = $request->validate([
            'title' => 'nullable|string|max:190',
            'lesson_id' => 'required|string|exists:lessons,id',
            'file_size' => 'required|integer|min:1|max:5368709120',
            'original_filename' => 'nullable|string|max:255',
            'mime_type' => 'nullable|string|max:100',
            'watermark' => 'nullable|boolean',
            'idempotency_key' => 'nullable|string|max:100',
        ]);

        // The lesson must belong to the validated tenant — `exists` alone is
        // not tenant-aware.
        \App\Models\Lesson::where('tenant_id', $tenantId)->findOrFail($validated['lesson_id']);

        try {
            $ticket = $this->media->createVideoUploadTicket($tenantId, $request->user()?->id, [
                'title' => $validated['title'] ?? $validated['original_filename'] ?? 'Untitled video',
                'file_size' => $validated['file_size'],
                'lesson_id' => $validated['lesson_id'],
                'original_filename' => $validated['original_filename'] ?? null,
                'mime_type' => $validated['mime_type'] ?? null,
                'watermark' => $validated['watermark'] ?? true,
                'expires_in' => (int) config('stream.upload_ticket_ttl', 3600),
                'idempotency_key' => $validated['idempotency_key'] ?? null,
            ]);

            // لا نربط الأصل بالدرس هنا: التذكرة قد لا تُستخدم (فشل رفع/إغلاق
            // المتصفح) — الربط يتم حصرياً في uploadDirect بعد نجاح الرفع الفعلي
            // حتى لا يبقى محتوى يتيم بمعرّف بلا ملف.
            $asset = MediaAsset::withoutTenant()->find($ticket['asset_id']);

            return response()->json([
                'message' => 'Upload ticket created. Upload directly to the provided URL.',
                'upload' => [
                    'upload_url' => $ticket['upload_url'],
                    'asset_id' => $asset?->id,
                    'reservation_id' => $ticket['reservation_id'],
                    'expires_in' => $ticket['expires_in'],
                ],
            ], 201);
        } catch (QuotaExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (FeatureUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'تعذر إنشاء تذكرة الرفع. يرجى المحاولة لاحقاً.'], 502);
        }
    }

    /**
     * تذكرة رفع مباشر قابل للاستئناف (TUS) لرفع جماعي.
     *
     * تُنشئ الأصل + تحجز المساحة عبر MediaService (بلا ربط بالدرس بعد)، ثم
     * تُعيد بيانات اعتماد TUS ليرفع المتصفح الملف مباشرة إلى Bunny — بلا
     * مرور الفيديو عبر السيرفر. الربط بالدرس يتم في resumableComplete بعد
     * نجاح الرفع. Idempotency: نفس المفتاح = نفس الأصل ونفس الفيديو.
     */
    public function resumableTicket(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id');

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant ID is required.'], 400);
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:190',
            'lesson_id' => 'nullable|string',
            'replace_content_id' => 'nullable|string|max:64',
            'file_size' => 'required|integer|min:1|max:5368709120',
            'original_filename' => 'nullable|string|max:255',
            'mime_type' => 'nullable|string|max:100',
            'idempotency_key' => 'nullable|string|max:100',
        ]);

        if (! empty($validated['replace_content_id']) && empty($validated['lesson_id'])) {
            return response()->json(['message' => 'A lesson is required when replacing its video.'], 422);
        }

        if (! empty($validated['lesson_id'])) {
            Lesson::where('tenant_id', $tenantId)->findOrFail($validated['lesson_id']);
        }

        if (! empty($validated['replace_content_id'])) {
            LessonContent::withoutTenant()
                ->where('tenant_id', $tenantId)
                ->where('lesson_id', $validated['lesson_id'])
                ->where('content_type', 'video')
                ->findOrFail($validated['replace_content_id']);
        }

        try {
            $ticket = $this->media->createResumableUploadTicket($tenantId, $request->user()?->id, [
                'title' => $validated['title'] ?? $validated['original_filename'] ?? 'Untitled video',
                'file_size' => $validated['file_size'],
                'original_filename' => $validated['original_filename'] ?? null,
                'mime_type' => $validated['mime_type'] ?? null,
                'idempotency_key' => $validated['idempotency_key'] ?? null,
                'replacement_lesson_content_id' => $validated['replace_content_id'] ?? null,
                'replacement_lesson_id' => $validated['lesson_id'] ?? null,
            ]);

            return response()->json([
                'upload' => [
                    'asset_id' => $ticket['asset_id'],
                    // بيانات اعتماد TUS (المتصفح يرفع مباشرة إلى هذا العنوان).
                    'endpoint' => $ticket['resumable']['endpoint'],
                    'signature' => $ticket['resumable']['signature'],
                    'expires' => $ticket['resumable']['expires'],
                    'library_id' => $ticket['resumable']['library_id'],
                    'video_guid' => $ticket['resumable']['video_id'],
                    'reservation_id' => $ticket['reservation_id'],
                    'expires_in' => $ticket['expires_in'],
                    'reused' => (bool) ($ticket['reused'] ?? false),
                ],
            ], 201);
        } catch (QuotaExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (FeatureUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('media.resumable_ticket_failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'تعذر إنشاء تذكرة الرفع. يرجى المحاولة لاحقاً.'], 502);
        }
    }

    /**
     * إتمام رفع مباشر: ربط الأصل بالدرس بعد نجاح رفع المتصفح إلى المزود.
     *
     * لا نعتبر الفيديو جاهزاً هنا — الحالة تبقى `processing` حتى يؤكد المزود
     * اكتمال الرفع والمعالجة (Webhook أو المصالحة الدورية). idempotent:
     * إعادة النداء تُعيد نفس المحتوى بلا تكرار.
     */
    public function resumableComplete(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id');

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant ID is required.'], 400);
        }

        $validated = $request->validate([
            'asset_id' => 'required|string|max:64',
            'lesson_id' => 'required|string',
        ]);

        $asset = MediaAsset::withoutTenant()->find($validated['asset_id']);

        if (! $asset || (string) $asset->tenant_id !== (string) $tenantId) {
            return response()->json(['message' => 'Media asset not found.'], 404);
        }

        $lesson = Lesson::where('tenant_id', $tenantId)->find($validated['lesson_id']);

        if (! $lesson) {
            return response()->json(['message' => 'Lesson not found.'], 404);
        }

        $asset = $this->media->attachToLesson($asset->id, $lesson->id);
        $replacementPending = ! empty($asset->metadata['replacement_lesson_content_id']);

        $video = $asset->lesson_content_id
            ? VideoContent::withoutTenant()->where('lesson_content_id', $asset->lesson_content_id)->first()
            : null;

        return response()->json([
            'video' => [
                'id' => $video?->id,
                'lesson_id' => $video?->lesson_id ?? $lesson->id,
                'asset_id' => $asset->id,
                'status' => $video?->status ?? $asset->status,
            ],
            'message' => $asset->status === MediaAsset::STATUS_PROCESSING
                ? ($replacementPending
                    ? 'Replacement uploaded — processing now; the current lesson video remains active until ready.'
                    : 'Upload complete — processing now.')
                : 'Upload complete.',
        ], 201);
    }

    /**
     * ربط فيديو موجود من المكتبة بدرس آخر — بلا إعادة رفع ولا نسخة مزوّد ثانية.
     * المساحة تُحتسب مرة واحدة (سجل الأصل)، فالاستخدام المتعدد لا يضاعفها.
     */
    public function attachExisting(Request $request, string $assetId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id');

        $validated = $request->validate([
            'lesson_id' => 'required|string',
        ]);

        $asset = MediaAsset::withoutTenant()->find($assetId);

        if (! $asset || (string) $asset->tenant_id !== (string) $tenantId) {
            return response()->json(['message' => 'Media asset not found.'], 404);
        }

        $lesson = Lesson::where('tenant_id', $tenantId)->find($validated['lesson_id']);

        if (! $lesson) {
            return response()->json(['message' => 'Lesson not found.'], 404);
        }

        try {
            $asset = $this->media->attachExistingToLesson($asset->id, $lesson->id);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not link the video to this lesson.'], 500);
        }

        $video = VideoContent::withoutTenant()
            ->where('lesson_id', $lesson->id)
            ->where('provider_video_id', $asset->provider_asset_id)
            ->first();

        return response()->json([
            'video' => [
                'id' => $video?->id,
                'lesson_id' => $lesson->id,
                'asset_id' => $asset->id,
                'status' => $video?->status ?? $asset->status,
                'reused' => true,
            ],
            'message' => 'Video linked to the lesson (no re-upload).',
        ], 201);
    }

    /**
     * حذف أصل (فيديو/تسجيل) + تحرير الاستخدام.
     */
    public function destroy(Request $request, string $assetId): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id');
        $asset = MediaAsset::withoutTenant()->find($assetId);

        if (! $asset || (string) $asset->tenant_id !== (string) $tenantId) {
            return response()->json(['message' => 'Media asset not found.'], 404);
        }

        $this->media->deleteAsset($assetId);

        return response()->json(['message' => 'Asset deleted.']);
    }

    /**
     * رابط تشغيل موقّع لأصل جاهز (للطالب داخل درس بعد التحقق من التسجيل).
     */
    public function playback(Request $request, string $assetId): JsonResponse
    {
        $asset = MediaAsset::withoutTenant()->find($assetId);

        if (! $asset) {
            return response()->json(['message' => 'Media asset not found.'], 404);
        }

        if (! $this->security->canAccess($asset, $request->user())) {
            return response()->json(['message' => 'You do not have access to this video.'], 403);
        }

        return response()->json([
            'asset' => $this->security->signedPlayback($asset, $request->user(), (int) config('stream.signed_url_ttl', 900)),
        ]);
    }

    /**
     * استخدام المعلم (للوحة والتحذيرات).
     */
    public function usage(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id');

        $status = $this->quota->usageStatus($tenantId);
        $warnings = [];

        foreach (['storage', 'videos', 'students', 'watch_minutes', 'live_minutes'] as $key) {
            $entry = $status[$key] ?? null;
            if (! $entry || $entry['unlimited']) {
                continue;
            }

            // العتبات من جدول usage_warning_rules (قابلة للتعديل من DB) —
            // لا قيم ثابتة في الكود.
            $rules = UsageWarningRule::forResource($key);
            $pct = $entry['pct'];

            foreach ($rules as $rule) {
                if ($pct >= $rule['threshold']) {
                    $warnings[] = [
                        'resource' => $key,
                        'level' => $rule['level'],
                        'threshold' => $rule['threshold'],
                        'severity' => $rule['severity'],
                        'message' => $request->user()?->locale === 'en'
                            ? ($rule['message_en'] ?? '')
                            : ($rule['message_ar'] ?? ''),
                    ];
                    break; // أعلى عتبة محققة فقط
                }
            }
        }

        return response()->json([
            'usage' => $status,
            'warnings' => $warnings,
            'ledger' => $this->usage->monthlyAll($tenantId, now()->format('Y-m')),
            'estimated_cost' => app(SaasCostService::class)->usageCost($tenantId),
        ]);
    }
}
