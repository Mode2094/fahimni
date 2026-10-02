<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lesson\StoreLessonRequest;
use App\Http\Resources\LessonResource;
use App\Models\FileContent;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\LiveSession;
use App\Models\Section;
use App\Models\TextContent;
use App\Models\VideoContent;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class LessonController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request): JsonResponse
    {
        $isStaff = $this->isTenantStaff($request);

        $lessons = Lesson::withCount('contents')
            ->when($request->filled('section_id'), fn ($q) => $q->where('section_id', $request->section_id))
            ->when(
                ! $isStaff,
                // Students may only list published lessons of courses they
                // are actively enrolled in.
                fn ($q) => $q
                    ->where('is_published', true)
                    ->whereHas('section', function ($sq) use ($request) {
                        $sq->whereHas('course.enrollments', function ($eq) use ($request) {
                            $eq->where('user_id', $request->user()->id)
                                ->whereIn('status', ['active', 'completed'])
                                ->where(fn ($e) => $e->whereNull('expires_at')->orWhere('expires_at', '>', now()));
                        });
                    })
            )
            ->orderBy('sort_order')
            ->paginate($this->perPage($request));

        return response()->json([
            'lessons' => LessonResource::collection($lessons->items()),
            'pagination' => [
                'current_page' => $lessons->currentPage(),
                'last_page' => $lessons->lastPage(),
                'per_page' => $lessons->perPage(),
                'total' => $lessons->total(),
            ],
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->input('per_page', 20), 1), 100);
    }

    public function show(string $id): JsonResponse
    {
        $lesson = Lesson::with(['contents'])->findOrFail($id);

        $this->authorize('view', $lesson);

        return response()->json([
            'lesson' => new LessonResource($lesson),
        ]);
    }

    public function store(StoreLessonRequest $request): JsonResponse
    {
        $this->authorize('create', Lesson::class);

        $lesson = Lesson::create([
            'id' => UuidV7::generate(),
            ...$request->validated(),
        ]);

        $this->syncContent($lesson, $request);

        return response()->json([
            'lesson' => new LessonResource($lesson->fresh()->load('contents')),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $lesson = Lesson::findOrFail($id);

        $this->authorize('update', $lesson);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'duration_minutes' => 'nullable|integer|min:0',
            'sort_order' => 'nullable|integer|min:0',
            'is_published' => 'nullable|boolean',
            'is_free_preview' => 'nullable|boolean',
            'lesson_type' => 'nullable|string|max:20',
            'metadata' => 'nullable|array',
        ]);

        $lesson->update($validated);

        if ($request->has('video_url') || $request->has('content')) {
            $this->syncContent($lesson, $request);
        }

        return response()->json([
            'lesson' => new LessonResource($lesson->fresh()->load('contents')),
        ]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'section_id' => 'required|string|exists:sections,id',
            'lesson_ids' => 'required|array|min:1',
            'lesson_ids.*' => 'required|string|distinct',
        ]);

        $section = Section::findOrFail($validated['section_id']);

        $this->authorize('update', $section);

        $ids = array_values($validated['lesson_ids']);

        $lessons = Lesson::where('section_id', $section->id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($lessons->count() !== count($ids)) {
            return response()->json(['message' => 'Invalid lesson ids.'], 422);
        }

        foreach ($ids as $index => $lessonId) {
            $lesson = $lessons[$lessonId];
            if ($lesson->sort_order !== $index + 1) {
                $lesson->sort_order = $index + 1;
                $lesson->save();
            }
        }

        return response()->json(['message' => 'Lessons reordered successfully.']);
    }

    public function destroy(string $id): JsonResponse
    {
        $lesson = Lesson::findOrFail($id);

        $this->authorize('delete', $lesson);

        $lesson->delete();

        return response()->json(['message' => 'Lesson deleted successfully.']);
    }

    private function syncContent(Lesson $lesson, Request $request): void
    {
        // Clean up child rows of replaced content so nothing is orphaned.
        $oldContents = LessonContent::where('lesson_id', $lesson->id)->pluck('id');

        if ($oldContents->isNotEmpty()) {
            VideoContent::whereIn('lesson_content_id', $oldContents)->delete();
            TextContent::whereIn('lesson_content_id', $oldContents)->delete();
            FileContent::whereIn('lesson_content_id', $oldContents)->delete();
            LiveSession::whereIn('lesson_content_id', $oldContents)->delete();
        }

        LessonContent::where('lesson_id', $lesson->id)->forceDelete();

        $videoUrl = $request->input('video_url');
        if ($videoUrl) {
            $content = LessonContent::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $lesson->tenant_id,
                'lesson_id' => $lesson->id,
                'content_type' => 'video',
                'sort_order' => 1,
                'is_required' => true,
                'title' => $request->input('title'),
                'metadata' => [],
            ]);

            VideoContent::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $lesson->tenant_id,
                'lesson_content_id' => $content->id,
                'provider' => 'url',
                'provider_video_id' => $videoUrl,
                'title' => $request->input('title'),
                'description' => $request->input('content'),
                'duration' => (int) ($request->input('duration_minutes') ?? 0),
                'status' => 'ready',
                'config' => [],
            ]);

            return;
        }

        $text = $request->input('content');
        if ($text) {
            $content = LessonContent::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $lesson->tenant_id,
                'lesson_id' => $lesson->id,
                'content_type' => 'text',
                'sort_order' => 1,
                'is_required' => true,
                'title' => $request->input('title'),
                'metadata' => [],
            ]);

            TextContent::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $lesson->tenant_id,
                'lesson_content_id' => $content->id,
                'body' => $text,
                'format' => 'plain',
                'word_count' => str_word_count($text),
                'reading_time_minutes' => max(1, intdiv(str_word_count($text), 200)),
            ]);
        }
    }
}
