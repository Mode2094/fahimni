<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Media\MediaProviderManager;
use App\Media\MediaService;
use App\Models\Assignment;
use App\Models\FileContent;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\MediaAsset;
use App\Models\VideoContent;
use App\Plans\Exceptions\FeatureUnavailableException;
use App\Plans\Exceptions\QuotaExceededException;
use App\Plans\QuotaService;
use App\Services\FeatureService;
use App\Support\UploadValidator;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Uid\UuidV7;

class FileUploadController extends Controller
{
    use AuthorizesTenantAccess;

    /**
     * Protected video upload — stored in private storage (never served directly by
     * nginx). Playback only happens through the signed-token streaming endpoint.
     */
    public function uploadTicket(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'nullable|string|max:255',
            'lesson_id' => 'nullable|string',
            'file_size' => 'nullable|integer|min:1',
            'watermark_enabled' => 'nullable|boolean',
            'allow_download' => 'nullable|boolean',
            'pause_on_hidden' => 'nullable|boolean',
            'block_picture_in_picture' => 'nullable|boolean',
            'idempotency_key' => 'nullable|string|max:100',
        ]);

        $tenantId = $request->header('X-Tenant-Id');

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant ID is required.'], 400);
        }

        if (! $this->isTenantStaff($request, $tenantId)) {
            return response()->json(['message' => 'Unauthorized to upload videos for this tenant.'], 403);
        }

        $this->assertLessonInTenant($request, $tenantId);

        $size = (int) $request->input('file_size', 0);

        try {
            // Media Engine: خطة + حجز ذري للمساحة + تذكرة رفع مباشر + أصل مرجعي.
            $media = app(MediaService::class);
            $ticket = $media->createVideoUploadTicket($tenantId, $request->user()?->id, [
                'title' => $request->input('title') ?? 'Untitled video',
                'file_size' => $size,
                'original_filename' => $request->input('original_filename'),
                'mime_type' => $request->input('mime_type'),
                'watermark' => $request->boolean('watermark_enabled', true),
                'idempotency_key' => $request->input('idempotency_key'),
            ]);

            // ربط الأصل بالدرس (LessonContent + VideoContent بنفس شكل الرد القديم).
            $asset = $media->attachToLesson($ticket['asset_id'], $request->input('lesson_id'));

            $video = VideoContent::withoutTenant()
                ->where('lesson_content_id', $asset->lesson_content_id)
                ->firstOrFail();
        } catch (QuotaExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (FeatureUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'upload' => [
                'upload_url' => $ticket['upload_url'],
                'video_id' => $video->id,
                'asset_id' => $ticket['asset_id'],
                'expires_in' => $ticket['expires_in'],
            ],
        ], 201);
    }

    /**
     * رفع فيديو عبر الخادم (آمن): الملف يصل للسيرفر ثم يُرفع إلى Bunny
     * بمفتاح AccessKey في الخادم فقط — لا يخرج المفتاح للمتصفح أبداً.
     * يمر عبر نفس الحصص والحجز الذري (createVideoUploadTicket).
     */
    public function uploadDirect(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:5242880', // 5GB
            'lesson_id' => 'nullable|string',
            'title' => 'nullable|string|max:255',
            'asset_id' => 'nullable|string|max:64',
            'idempotency_key' => 'nullable|string|max:100',
        ]);

        $tenantId = $request->header('X-Tenant-Id');

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant ID is required.'], 400);
        }

        if (! $this->isTenantStaff($request, $tenantId)) {
            return response()->json(['message' => 'Unauthorized to upload videos for this tenant.'], 403);
        }

        $this->assertLessonInTenant($request, $tenantId);

        $file = $request->file('file');

        try {
            $media = app(MediaService::class);

            // ── Idempotency: إن وُجد asset_id (من خطوة upload-ticket) نعيد
            // استخدام نفس الفيديو في Bunny — لا تذكرة جديدة ولا فيديو ثانٍ.
            // إن وُجد idempotency_key فقط — نفس المعاملة (double click/retry).
            $assetId = $request->input('asset_id');

            // A client-supplied asset id is only a reference: it MUST belong
            // to the current tenant. TenantMiddleware already validated the
            // context, so the model's tenant scope is authoritative here.
            if ($assetId) {
                $asset = MediaAsset::find($assetId);

                if (! $asset || $asset->tenant_id !== $tenantId) {
                    return response()->json(['message' => 'Media asset not found.'], 404);
                }
            }

            if (! $assetId && $request->input('idempotency_key')) {
                $existingAsset = MediaAsset::where('idempotency_key', $request->input('idempotency_key'))
                    ->where('tenant_id', $tenantId)
                    ->first();
                $assetId = $existingAsset?->id;
            }

            if ($assetId) {
                $ticket = ['asset_id' => $assetId, 'upload_url' => null];
            } else {
                $ticket = $media->createVideoUploadTicket($tenantId, $request->user()?->id, [
                    'title' => $request->input('title') ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                    'file_size' => $file->getSize(),
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'watermark' => $request->boolean('watermark_enabled', true),
                    'idempotency_key' => $request->input('idempotency_key'),
                ]);
            }

            // الرفع إلى Bunny بمفتاح الخادم (Stream عبر Http مع Body تدفقي).
            $model = app(MediaProviderManager::class)->model('bunny');
            $apiKey = (string) ($model?->credentialArray()['api_key'] ?? '');

            if ($apiKey === '') {
                throw new \RuntimeException('Bunny API key is not configured.');
            }

            $libraryId = (string) ($model?->credentialArray()['library_id'] ?? '');
            $guid = (string) (MediaAsset::where('tenant_id', $tenantId)->find($ticket['asset_id'])?->provider_asset_id ?? '');

            if ($guid === '') {
                throw new \RuntimeException('Upload asset has no provider video id.');
            }

            $uploadUrl = $ticket['upload_url']
                ?: 'https://video.bunnycdn.com/library/'.$libraryId.'/videos/'.$guid;

            $response = Http::withHeaders([
                'AccessKey' => $apiKey,
                'Content-Type' => $file->getMimeType(),
            ])->timeout(3600)->send('PUT', $uploadUrl, [
                'body' => fopen($file->getRealPath(), 'r'),
            ]);

            if (! $response->successful()) {
                // 400/409 على أصل موجود = الملف سبق رفعه (retry) — نكمل
                // بلا إنشاء فيديو جديد. أي فشل آخر → تحرير الحجز والأصل.
                if (in_array($response->status(), [400, 409], true) && $assetId) {
                    Log::info('media.upload_repeat_ignored', [
                        'asset' => $assetId,
                        'status' => $response->status(),
                    ]);
                } else {
                    $media->deleteAsset($ticket['asset_id']);

                    return response()->json(['message' => 'Bunny upload failed (HTTP '.$response->status().').'], 502);
                }
            }

            // ربط الأصل بالدرس (LessonContent + VideoContent).
            $asset = $media->attachToLesson($ticket['asset_id'], $request->input('lesson_id'));

            $video = VideoContent::withoutTenant()
                ->where('lesson_content_id', $asset->lesson_content_id)
                ->firstOrFail();

            return response()->json([
                'video' => [
                    'id' => $video->id,
                    'lesson_id' => $video->lesson_id,
                    'status' => 'processing',
                ],
                'message' => 'Video uploaded — processing now.',
            ], 201);
        } catch (QuotaExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (FeatureUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Throwable $e) {
            Log::warning('media.upload_direct_failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Video upload failed: '.$e->getMessage()], 500);
        }
    }

    public function upload(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-Tenant-Id');

        // Platform-branding uploads (no tenant + super admin) may carry an
        // SVG logo; it is sanitized in UploadValidator before touching disk.
        $isBranding = ! $tenantId && (bool) ($request->user()?->is_super_admin);

        // Unbound uploads (no tenant context) are only for platform branding
        // or users who belong to at least one active tenant — never an open
        // public file host for arbitrary accounts.
        if (! $tenantId && ! $isBranding) {
            $user = $request->user();
            $isMember = $user && $user->tenants()->wherePivot('status', 'active')->exists();

            if (! $isMember) {
                return response()->json(['message' => 'Tenant context is required for uploads.'], 403);
            }
        }

        $request->validate([
            'file' => 'required|file|max:102400|mimes:png,jpg,jpeg,webp,ico'
                .($isBranding ? ',svg' : '').',pdf,doc,docx,ppt,pptx,xls,xlsx,csv,txt,zip,mp3,wav,ogg,m4a,aac,mp4,webm,mov',
        ]);

        $file = $request->file('file');
        $lessonId = $request->input('lesson_id');

        $isLessonFile = $tenantId && $lessonId;

        if ($tenantId) {
            if (! $this->isTenantStaff($request, $tenantId)) {
                return response()->json(['message' => 'Unauthorized to upload files for this tenant.'], 403);
            }

            $this->assertLessonInTenant($request, $tenantId);
        }

        // Server-side content sniffing: reject HTML/script payloads no
        // matter what extension or content-type the client claims.
        try {
            UploadValidator::generic($file, $isBranding);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($tenantId) {
            // Plan enforcement: file feature gate + file count + storage.
            $quota = app(QuotaService::class);
            $quota->assertCan($tenantId, 'files');
            $quota->assertCan($tenantId, 'pdfs');
            $quota->assertCan($tenantId, 'storage', $file->getSize());
        }

        // Lesson-bound files are private course material: they live on the
        // private disk and are only reachable through a signed, short-lived,
        // user-bound ticket. Generic uploads (images, branding, question
        // media) stay on the public disk for direct browser rendering.
        $path = $isLessonFile
            ? $file->store('lesson-files/'.$tenantId, 'local')
            : $file->store('uploads', 'public');

        $url = $isLessonFile
            ? '/api/v1/lessons/'.$lessonId.'/file-ticket'
            : '/storage/'.$path;

        if ($isLessonFile) {
            $content = LessonContent::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenantId,
                'lesson_id' => $lessonId,
                'content_type' => 'file',
                'sort_order' => 0,
                'is_required' => true,
                'title' => $file->getClientOriginalName(),
                'metadata' => [],
            ]);

            FileContent::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenantId,
                'lesson_content_id' => $content->id,
                'original_name' => $file->getClientOriginalName(),
                'storage_path' => $path,
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'extension' => $file->getClientOriginalExtension(),
                'is_downloadable' => true,
                'metadata' => [],
            ]);
        }

        if ($tenantId) {
            $features = app(FeatureService::class);
            $usedBytes = FileContent::where('tenant_id', $tenantId)->sum('size_bytes');
            $limitBytes = $features->isUnlimited('storage_limit_mb')
                ? null
                : $features->getIntFeature('storage_limit_mb') * 1024 * 1024;

            return response()->json([
                'url' => $url,
                'name' => $file->getClientOriginalName(),
                'storage' => [
                    'used_mb' => round($usedBytes / 1024 / 1024, 1),
                    'limit_mb' => $limitBytes ? (int) ($limitBytes / 1024 / 1024) : null,
                ],
            ], 201);
        }

        return response()->json([
            'url' => $url,
            'name' => $file->getClientOriginalName(),
        ], 201);
    }

    /**
     * Assignment / submission file upload — stored like any other upload but
     * without binding to a lesson (assignments and student submissions attach
     * the returned URL directly).
     */
    public function assignmentUpload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:102400|mimes:png,jpg,jpeg,webp,ico,pdf,doc,docx,ppt,pptx,xls,xlsx,csv,txt,zip,mp3,wav,ogg,m4a,aac,mp4,webm,mov',
        ]);

        $tenantId = $request->header('X-Tenant-Id');

        if (! $tenantId) {
            return response()->json(['message' => 'Tenant ID is required.'], 400);
        }

        if (! $this->isTenantMember($request, $tenantId)) {
            return response()->json(['message' => 'Unauthorized to upload for this tenant.'], 403);
        }

        $file = $request->file('file');

        // Server-side content sniffing — never trust the client's claims.
        try {
            UploadValidator::generic($file);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Storage quota enforcement (students submit too).
        app(QuotaService::class)->assertCan($tenantId, 'storage', $file->getSize());

        $path = $file->store('uploads', 'public');

        return response()->json([
            'url' => '/storage/'.$path,
            'name' => $file->getClientOriginalName(),
        ], 201);
    }

    private function assertLessonInTenant(Request $request, string $tenantId): void
    {
        $lessonId = $request->input('lesson_id');

        if ($lessonId) {
            Lesson::where('tenant_id', $tenantId)->findOrFail($lessonId);
        }
    }
}
