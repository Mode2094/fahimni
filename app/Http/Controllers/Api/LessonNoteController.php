<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonNote;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

/**
 * Per-lesson student notes (optionally pinned to a video timestamp).
 *
 * A note always belongs to its author. Staff of the tenant can read a note
 * only when the student explicitly shared it. There is no cross-student leak:
 * a plain student listing is always filtered to `user_id = me`.
 */
class LessonNoteController extends Controller
{
    use AuthorizesTenantAccess;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $staff = $this->isTenantStaff($request);

        $notes = LessonNote::with(['lesson:id,title'])
            ->when(! $staff || $request->boolean('mine'), fn ($q) => $q->where('user_id', $user->id))
            ->when($staff && ! $request->boolean('mine'), fn ($q) => $q->where('is_shared', true))
            ->when($request->filled('lesson_id'), fn ($q) => $q->where('lesson_id', $request->lesson_id))
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->course_id))
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return response()->json(['notes' => $notes]);
    }

    public function store(Request $request, string $lessonId): JsonResponse
    {
        $lesson = $this->resolveLesson($request, $lessonId);

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
            'timestamp_seconds' => 'nullable|integer|min:0|max:360000',
            'is_shared' => 'nullable|boolean',
        ]);

        $note = LessonNote::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $lesson->tenant_id,
            'user_id' => $request->user()->id,
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->section?->course_id,
            'timestamp_seconds' => $validated['timestamp_seconds'] ?? null,
            'body' => $validated['body'],
            'is_shared' => $request->boolean('is_shared', false),
        ]);

        return response()->json(['note' => $note], 201);
    }

    public function update(Request $request, string $noteId): JsonResponse
    {
        $note = LessonNote::findOrFail($noteId);

        if ($note->user_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only edit your own notes.'], 403);
        }

        $validated = $request->validate([
            'body' => 'sometimes|required|string|max:5000',
            'timestamp_seconds' => 'nullable|integer|min:0|max:360000',
            'is_shared' => 'nullable|boolean',
        ]);

        $data = [];
        if ($request->has('body')) {
            $data['body'] = $validated['body'];
        }
        if ($request->has('timestamp_seconds')) {
            $data['timestamp_seconds'] = $validated['timestamp_seconds'];
        }
        if ($request->has('is_shared')) {
            $data['is_shared'] = $request->boolean('is_shared');
        }

        $note->update($data);

        return response()->json(['note' => $note->fresh()]);
    }

    public function destroy(Request $request, string $noteId): JsonResponse
    {
        $note = LessonNote::findOrFail($noteId);

        if ($note->user_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only delete your own notes.'], 403);
        }

        $note->delete();

        return response()->json(['message' => 'Note deleted.']);
    }

    private function resolveLesson(Request $request, string $lessonId): Lesson
    {
        $tenantId = (string) $request->current_tenant->id;

        $lesson = Lesson::with('section.course')->findOrFail($lessonId);

        if ($lesson->tenant_id !== $tenantId) {
            abort(404, 'Lesson not found.');
        }

        return $lesson;
    }
}
