<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SectionResource;
use App\Models\Enrollment;
use App\Models\Section;
use App\Rules\TenantScopedExists;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class SectionController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request): JsonResponse
    {
        $sections = Section::with(['lessons.contents.video', 'lessons.contents.text', 'lessons.contents.file', 'lessons.contents.liveSession'])
            ->withCount('lessons')
            ->when(! $this->isTenantStaff($request), function ($q) use ($request) {
                $enrolledCourseIds = Enrollment::where('user_id', $request->user()->id)
                    ->whereIn('status', ['active', 'completed'])
                    ->pluck('course_id');

                $q->whereIn('course_id', $enrolledCourseIds);
            })
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->course_id))
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'sections' => SectionResource::collection($sections),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $section = Section::with(['lessons.contents'])->findOrFail($id);

        $this->authorize('view', $section);

        return response()->json([
            'section' => new SectionResource($section),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Section::class);

        $validated = $request->validate([
            'course_id' => ['required', 'string', new TenantScopedExists('courses')],
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
            'is_published' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);

        $section = Section::create([
            'id' => UuidV7::generate(),
            ...$validated,
        ]);

        return response()->json([
            'section' => new SectionResource($section),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $section = Section::findOrFail($id);

        $this->authorize('update', $section);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
            'is_published' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);

        $section->update($validated);

        return response()->json([
            'section' => new SectionResource($section->fresh()),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $section = Section::findOrFail($id);

        $this->authorize('delete', $section);

        $section->delete();

        return response()->json(['message' => 'Section deleted successfully.']);
    }
}
