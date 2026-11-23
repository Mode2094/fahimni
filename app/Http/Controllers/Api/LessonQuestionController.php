<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonQuestion;
use App\Models\User;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

/**
 * Lesson Q&A — a student asks a question on a lesson, the teacher answers.
 *
 * Never touches exam grading. A student sees their own questions plus the
 * public questions of others; they may delete only their own, unanswered
 * questions. Staff see and answer every question of their tenant.
 */
class LessonQuestionController extends Controller
{
    use AuthorizesTenantAccess;

    /** Questions for one lesson (asked by me, plus public ones). */
    public function index(Request $request, string $lessonId): JsonResponse
    {
        $lesson = $this->resolveLesson($request, $lessonId);
        $user = $request->user();

        $questions = LessonQuestion::with(['student:id,name,avatar_url', 'answerer:id,name'])
            ->where('lesson_id', $lesson->id)
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhere('is_public', true);
            })
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'questions' => $questions->map(fn (LessonQuestion $q) => $this->present($q, $user)),
        ]);
    }

    public function store(Request $request, string $lessonId): JsonResponse
    {
        $lesson = $this->resolveLesson($request, $lessonId);

        $validated = $request->validate([
            'body' => 'required|string|max:5000',
            'is_public' => 'nullable|boolean',
        ]);

        $question = LessonQuestion::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $lesson->tenant_id,
            'user_id' => $request->user()->id,
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->section?->course_id,
            'body' => $validated['body'],
            'is_public' => $request->boolean('is_public', true),
        ]);

        return response()->json([
            'question' => $this->present($question->load('student:id,name,avatar_url'), $request->user()),
        ], 201);
    }

    public function destroy(Request $request, string $questionId): JsonResponse
    {
        $question = LessonQuestion::findOrFail($questionId);

        if ($question->user_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only delete your own questions.'], 403);
        }

        if ($question->answered_at !== null) {
            return response()->json(['message' => 'An answered question cannot be deleted.'], 422);
        }

        $question->delete();

        return response()->json(['message' => 'Question deleted.']);
    }

    /** Staff view: every question of the tenant, optionally filtered. */
    public function teacherIndex(Request $request): JsonResponse
    {
        if (! $this->isTenantStaff($request)) {
            return response()->json(['message' => 'Only staff can view all questions.'], 403);
        }

        $questions = LessonQuestion::with(['student:id,name,avatar_url', 'lesson:id,title', 'answerer:id,name'])
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->course_id))
            ->when($request->filled('lesson_id'), fn ($q) => $q->where('lesson_id', $request->lesson_id))
            ->when($request->input('status') === 'unanswered', fn ($q) => $q->whereNull('answered_at'))
            ->when($request->input('status') === 'answered', fn ($q) => $q->whereNotNull('answered_at'))
            ->orderByRaw('answered_at IS NULL DESC')
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return response()->json([
            'questions' => $questions->map(fn (LessonQuestion $q) => $this->present($q, $request->user(), true)),
            'unanswered_count' => LessonQuestion::whereNull('answered_at')->count(),
        ]);
    }

    public function answer(Request $request, string $questionId): JsonResponse
    {
        if (! $this->isTenantStaff($request)) {
            return response()->json(['message' => 'Only staff can answer questions.'], 403);
        }

        $question = LessonQuestion::findOrFail($questionId);

        $validated = $request->validate([
            'answer' => 'required|string|max:5000',
        ]);

        $question->update([
            'answer' => $validated['answer'],
            'answered_by' => $request->user()->id,
            'answered_at' => now(),
        ]);

        return response()->json([
            'question' => $this->present($question->fresh()->load(['student:id,name,avatar_url', 'answerer:id,name']), $request->user(), true),
        ]);
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

    private function present(LessonQuestion $q, User $viewer, bool $staffView = false): array
    {
        return [
            'id' => $q->id,
            'body' => $q->body,
            'answer' => $q->answer,
            'is_public' => (bool) $q->is_public,
            'answered_at' => $q->answered_at?->toIso8601String(),
            'created_at' => $q->created_at?->toIso8601String(),
            'mine' => $q->user_id === $viewer->id,
            'student' => $staffView || $q->is_public ? [
                'name' => $q->student?->name,
                'avatar_url' => $q->student?->avatar_url,
            ] : null,
            'answered_by' => $q->answerer?->name,
            'lesson' => $staffView && $q->relationLoaded('lesson') ? [
                'id' => $q->lesson?->id,
                'title' => $q->lesson?->title,
            ] : null,
        ];
    }
}
