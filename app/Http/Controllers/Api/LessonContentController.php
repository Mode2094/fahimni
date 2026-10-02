<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lesson\StoreLessonContentRequest;
use App\Http\Resources\LessonContentResource;
use App\Media\MediaService;
use App\Models\FileContent;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\LiveSession;
use App\Models\MediaAsset;
use App\Models\TextContent;
use App\Models\VideoContent;
use App\Support\StoragePathGuard;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Uid\UuidV7;

class LessonContentController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request, string $lessonId): JsonResponse
    {
        $lesson = Lesson::findOrFail($lessonId);

        $this->authorize('view', $lesson);

        $contents = LessonContent::where('lesson_id', $lessonId)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'contents' => LessonContentResource::collection($contents),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $content = LessonContent::with(['video', 'text', 'file', 'liveSession'])->findOrFail($id);

        $this->authorize('view', $content);

        return response()->json([
            'content' => new LessonContentResource($content),
        ]);
    }

    public function store(StoreLessonContentRequest $request): JsonResponse
    {
        $this->authorize('create', LessonContent::class);

        $lesson = Lesson::withoutTenant()->where('tenant_id', $this->tenantId($request))
            ->findOrFail($request->lesson_id);

        // ── درس واحد = محتوى رئيسي واحد ──
        // أي محتوى رئيسي جديد يستبدل القديم (لا محتويين لنفس الدرس).
        if (in_array($request->content_type, ['video', 'text', 'file', 'live'], true)) {
            $existing = LessonContent::withoutTenant()
                ->where('lesson_id', $lesson->id)
                ->whereIn('content_type', ['video', 'text', 'file', 'live']);

            if ($excludeId = $request->route('content')) {
                $existing->where('id', '!=', $excludeId);
            }

            $existing = $existing->first();

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
        }

        $content = LessonContent::create([
            'id' => UuidV7::generate(),
            'lesson_id' => $request->lesson_id,
            'content_type' => $request->content_type,
            'sort_order' => $request->input('sort_order', 0),
            'is_required' => $request->input('is_required', true),
            'title' => $request->input('title'),
            'metadata' => $request->input('metadata', []),
        ]);

        $this->createChildContent($content, $request->input('content_data', []));

        $content->load(['video', 'text', 'file', 'liveSession']);

        return response()->json([
            'content' => new LessonContentResource($content),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $content = LessonContent::with(['video', 'text', 'file', 'liveSession'])->findOrFail($id);

        $this->authorize('update', $content);

        $validated = $request->validate([
            'content_type' => 'sometimes|string|in:video,text,file,live',
            'sort_order' => 'nullable|integer|min:0',
            'is_required' => 'nullable|boolean',
            'title' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
            'content_data' => 'nullable|array',
        ]);

        $contentType = $validated['content_type'] ?? $content->content_type;

        if ($request->has('content_data')) {
            $request->validate($this->contentDataRules($contentType));

            // Delete ALL child rows before recreating the new type — a
            // video→text switch must never orphan the old VideoContent row
            // (and vice versa).
            VideoContent::where('lesson_content_id', $content->id)->delete();
            TextContent::where('lesson_content_id', $content->id)->delete();
            FileContent::where('lesson_content_id', $content->id)->delete();
            LiveSession::where('lesson_content_id', $content->id)->delete();
        }

        $content->update($validated);

        if ($request->has('content_data')) {
            $this->createChildContent($content, $request->input('content_data', []));
        }

        $content->load(['video', 'text', 'file', 'liveSession']);

        return response()->json([
            'content' => new LessonContentResource($content),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $content = LessonContent::findOrFail($id);

        $this->authorize('delete', $content);

        // تنظيف متدرّج: حذف المحتوى يسحب معه سجل الفيديو والأصل المرجعي
        // (وأي حجز مساحة) — لا أيتام في قاعدة البيانات بعد الحذف.
        if ($content->content_type === 'video') {
            VideoContent::withoutTenant()
                ->where('lesson_content_id', $content->id)
                ->each(function ($video) {
                    $asset = MediaAsset::withoutTenant()
                        ->where('provider_asset_id', $video->provider_video_id)
                        ->first();

                    if ($asset) {
                        try {
                            app(MediaService::class)->deleteAsset($asset->id);
                        } catch (\Throwable $e) {
                            Log::warning('media.content_delete_asset_failed', [
                                'asset' => $asset->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    $video->delete();
                });
        }

        $content->delete();

        return response()->json(['message' => 'Content deleted successfully.']);
    }

    private function contentDataRules(string $contentType): array
    {
        return match ($contentType) {
            'video' => [
                'content_data.provider' => 'required|string|max:50',
                'content_data.provider_video_id' => 'required|string|max:500',
                'content_data.title' => 'nullable|string|max:255',
                'content_data.description' => 'nullable|string',
                'content_data.thumbnail_url' => 'nullable|string|max:500',
                'content_data.duration' => 'nullable|integer|min:0',
                'content_data.file_size_bytes' => 'nullable|integer|min:0',
                'content_data.config' => 'nullable|array',
            ],
            'text' => [
                'content_data.body' => 'required|string',
                'content_data.format' => 'nullable|string|in:html,markdown,plain|max:20',
                'content_data.word_count' => 'nullable|integer|min:0',
                'content_data.reading_time_minutes' => 'nullable|integer|min:0',
            ],
            'file' => [
                'content_data.original_name' => 'required|string|max:255',
                'content_data.storage_path' => 'required|string|max:500',
                'content_data.mime_type' => 'required|string|max:100',
                'content_data.size_bytes' => 'required|integer|min:0',
                'content_data.extension' => 'required|string|max:20',
                'content_data.is_downloadable' => 'nullable|boolean',
                'content_data.thumbnail_url' => 'nullable|string|max:500',
                'content_data.metadata' => 'nullable|array',
            ],
            'live' => [
                'content_data.title' => 'required|string|max:255',
                'content_data.description' => 'nullable|string',
                'content_data.provider' => 'nullable|string|max:50',
                'content_data.room_name' => 'required|string|max:255',
                'content_data.scheduled_at' => 'nullable|date',
                'content_data.max_participants' => 'nullable|integer|min:0',
                'content_data.record_enabled' => 'nullable|boolean',
                'content_data.config' => 'nullable|array',
            ],
        };
    }

    private function createChildContent(LessonContent $content, array $data): void
    {
        $tenantId = $content->tenant_id;

        // Client-supplied storage paths must stay inside the tenant's upload
        // directories — otherwise a teacher could point a content row at any
        // file on the private disk (e.g. .env) and download it through the
        // signed-file endpoint.
        if (($content->content_type === 'file' || $content->content_type === 'video')
            && isset($data['storage_path'])
            && ! StoragePathGuard::isSafe($data['storage_path'], ['lesson-files', 'videos', 'uploads'])) {
            throw ValidationException::withMessages([
                'content_data.storage_path' => ['Invalid storage path.'],
            ]);
        }

        // Tenant-owned roots must resolve inside THIS tenant's directory —
        // otherwise a teacher could point at another tenant's private file.
        if (($content->content_type === 'file' || $content->content_type === 'video')
            && isset($data['storage_path'])
            && str_starts_with((string) $data['storage_path'], 'lesson-files/')
            && ! str_starts_with((string) $data['storage_path'], 'lesson-files/'.$tenantId.'/')) {
            throw ValidationException::withMessages([
                'content_data.storage_path' => ['Invalid storage path.'],
            ]);
        }

        match ($content->content_type) {
            'video' => VideoContent::create([
                'id' => UuidV7::generate(),
                'lesson_content_id' => $content->id,
                'tenant_id' => $tenantId,
                'provider' => $data['provider'] ?? 'youtube',
                'provider_video_id' => $data['provider_video_id'],
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'thumbnail_url' => $data['thumbnail_url'] ?? null,
                'duration' => $data['duration'] ?? 0,
                'file_size_bytes' => $data['file_size_bytes'] ?? null,
                'storage_path' => $data['storage_path'] ?? null,
                'original_name' => $data['original_name'] ?? null,
                'mime_type' => $data['mime_type'] ?? null,
                'is_uploaded' => (bool) ($data['is_uploaded'] ?? false),
                'config' => $data['config'] ?? [],
            ]),
            'text' => TextContent::create([
                'id' => UuidV7::generate(),
                'lesson_content_id' => $content->id,
                'tenant_id' => $tenantId,
                'body' => $data['body'],
                'format' => $data['format'] ?? 'html',
                'word_count' => $data['word_count'] ?? 0,
                'reading_time_minutes' => $data['reading_time_minutes'] ?? 0,
            ]),
            'file' => FileContent::create([
                'id' => UuidV7::generate(),
                'lesson_content_id' => $content->id,
                'tenant_id' => $tenantId,
                'original_name' => $data['original_name'],
                'storage_path' => $data['storage_path'],
                'mime_type' => $data['mime_type'],
                'size_bytes' => $data['size_bytes'],
                'extension' => $data['extension'],
                'is_downloadable' => $data['is_downloadable'] ?? false,
                'thumbnail_url' => $data['thumbnail_url'] ?? null,
                'metadata' => $data['metadata'] ?? [],
            ]),
            'live' => LiveSession::create([
                'id' => UuidV7::generate(),
                'lesson_content_id' => $content->id,
                'tenant_id' => $tenantId,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'provider' => $data['provider'] ?? 'livekit',
                'room_name' => $data['room_name'],
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'max_participants' => $data['max_participants'] ?? 0,
                'record_enabled' => $data['record_enabled'] ?? true,
                'config' => $data['config'] ?? [],
            ]),
        };
    }
}
