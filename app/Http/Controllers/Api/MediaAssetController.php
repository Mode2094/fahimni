<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Media\MediaQuotaService;
use App\Media\MediaSecurityService;
use App\Media\MediaService;
use App\Media\MediaUsageService;
use App\Models\MediaAsset;
use App\Models\UsageWarningRule;
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
