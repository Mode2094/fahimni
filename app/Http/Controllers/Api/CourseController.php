<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Course\StoreCourseRequest;
use App\Http\Requests\Course\UpdateCourseRequest;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Plans\QuotaService;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class CourseController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request): JsonResponse
    {
        $isStaff = $this->isTenantStaff($request);

        $courses = Course::withCount(['sections', 'enrollments', 'exams', 'questions'])
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($request->filled('level'), fn ($q) => $q->where('level', $request->level))
            ->when(
                $isStaff && $request->filled('is_published'),
                fn ($q) => $q->where('is_published', $request->boolean('is_published'))
            )
            // Students can never see drafts, regardless of query params.
            ->when(! $isStaff, fn ($q) => $q->where('is_published', true))
            ->orderBy('sort_order')
            ->paginate($this->perPage($request));

        return response()->json([
            'courses' => CourseResource::collection($courses->items()),
            'pagination' => [
                'current_page' => $courses->currentPage(),
                'last_page' => $courses->lastPage(),
                'per_page' => $courses->perPage(),
                'total' => $courses->total(),
            ],
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->input('per_page', 20), 1), 100);
    }

    public function show(string $id): JsonResponse
    {
        $course = Course::with(['sections.lessons.contents'])->findOrFail($id);

        $this->authorize('view', $course);

        return response()->json([
            'course' => new CourseResource($course),
        ]);
    }

    public function store(StoreCourseRequest $request): JsonResponse
    {
        $this->authorize('create', Course::class);

        app(QuotaService::class)->assertCan($request->current_tenant->id, 'courses');

        $course = Course::create([
            'id' => UuidV7::generate(),
            ...$request->validated(),
        ]);

        return response()->json([
            'course' => new CourseResource($course),
        ], 201);
    }

    public function update(UpdateCourseRequest $request, string $id): JsonResponse
    {
        $course = Course::findOrFail($id);

        $this->authorize('update', $course);

        $course->update($request->validated());

        return response()->json([
            'course' => new CourseResource($course->fresh()),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $course = Course::findOrFail($id);

        $this->authorize('delete', $course);

        $course->delete();

        return response()->json(['message' => 'Course deleted successfully.']);
    }

    public function publish(string $id): JsonResponse
    {
        $course = Course::findOrFail($id);

        $this->authorize('update', $course);

        $course->update(['is_published' => true]);

        return response()->json([
            'course' => new CourseResource($course->fresh()),
        ]);
    }

    public function unpublish(string $id): JsonResponse
    {
        $course = Course::findOrFail($id);

        $this->authorize('update', $course);

        $course->update(['is_published' => false]);

        return response()->json([
            'course' => new CourseResource($course->fresh()),
        ]);
    }

    public function search(Request $request, string $id): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:2|max:100']);

        $course = Course::with('sections:id,title,course_id')->findOrFail($id);

        $query = trim($request->q);
        $user = $request->user();

        $isSuper = $user->is_super_admin;
        $tenantId = $course->tenant_id;
        $inTenant = $request->header('X-Tenant-Id') === $tenantId;
        $isTeacher = $isSuper || $this->isTenantStaff($request, $tenantId);
        $enrolled = Enrollment::withoutTenant()
            ->where('course_id', $course->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['active', 'completed'])
            ->exists();

        if (! $isSuper && ! $inTenant && ! $enrolled) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $sectionIds = $course->sections->pluck('id');

        $lessons = Lesson::byTenant($tenantId)
            ->whereIn('section_id', $sectionIds)
            ->where(function ($q) use ($query) {
                $q->where('title', 'ilike', "%{$query}%")
                    ->orWhere('description', 'ilike', "%{$query}%")
                    ->orWhereHas('contents.text', fn ($cq) => $cq->where('body', 'ilike', "%{$query}%"))
                    ->orWhereHas('contents.video', fn ($cq) => $cq->where('title', 'ilike', "%{$query}%")->orWhere('description', 'ilike', "%{$query}%"))
                    ->orWhereHas('contents.file', fn ($cq) => $cq->where('original_name', 'ilike', "%{$query}%"));
            })
            ->with(['section:id,title', 'contents.text', 'contents.video', 'contents.file'])
            ->orderBy('sort_order')
            ->get();

        $lessonResults = $lessons->map(function (Lesson $lesson) use ($query, $isTeacher, $enrolled) {
            $snippets = [];

            if (mb_stripos($lesson->title, $query) !== false) {
                $snippets[] = ['type' => 'title', 'text' => $lesson->title];
            }

            if ($lesson->description && mb_stripos($lesson->description, $query) !== false) {
                $snippets[] = ['type' => 'description', 'text' => $this->makeSnippet($lesson->description, $query)];
            }

            // Locked lessons never leak body content: only title/description
            // matches are shown so a non-enrolled member cannot read the
            // lesson text through the search API.
            $locked = ! $isTeacher && ! $enrolled && ! $lesson->is_free_preview;

            if (! $locked) {
                foreach ($lesson->contents as $content) {
                    if ($content->text && $content->text->body && mb_stripos($content->text->body, $query) !== false) {
                        $snippets[] = ['type' => 'text', 'text' => $this->makeSnippet($content->text->body, $query)];
                    }
                    if ($content->video && mb_stripos(($content->video->title ?? ''), $query) !== false) {
                        $snippets[] = ['type' => 'video', 'text' => $content->video->title];
                    } elseif ($content->video && mb_stripos(($content->video->description ?? ''), $query) !== false) {
                        $snippets[] = ['type' => 'video', 'text' => $this->makeSnippet($content->video->description, $query)];
                    }
                    if ($content->file && mb_stripos(($content->file->original_name ?? ''), $query) !== false) {
                        $snippets[] = ['type' => 'file', 'text' => $content->file->original_name];
                    }
                }
            }

            return [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'lesson_type' => $lesson->lesson_type,
                'duration_minutes' => $lesson->duration_minutes,
                'is_free_preview' => (bool) $lesson->is_free_preview,
                'section_id' => $lesson->section_id,
                'section_title' => $lesson->section?->title,
                'locked' => $locked,
                'snippets' => $snippets,
            ];
        })->values();

        $sectionResults = $course->sections
            ->filter(fn ($s) => mb_stripos($s->title, $query) !== false)
            ->values()
            ->map(fn ($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'lesson_count' => $s->lessons_count ?? 0,
            ]);

        return response()->json([
            'query' => $query,
            'results' => [
                'lessons' => $lessonResults,
                'sections' => $sectionResults,
            ],
        ]);
    }

    private function makeSnippet(string $text, string $query): string
    {
        $clean = preg_replace('/\s+/u', ' ', strip_tags($text));
        $clean = trim($clean);

        $pos = mb_stripos($clean, $query);
        if ($pos === false) {
            return mb_substr($clean, 0, 140);
        }

        $start = max(0, $pos - 60);
        $length = mb_strlen($clean) - $start;
        $snippet = mb_substr($clean, $start, min(160, $length));

        if ($start > 0) {
            $snippet = '…'.$snippet;
        }
        if ($start + mb_strlen($snippet) < mb_strlen($clean)) {
            $snippet .= '…';
        }

        return $snippet;
    }
}
