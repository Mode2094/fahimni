<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\AssignmentSubmissionResource;
use App\Models\Assignment;
use App\Models\AssignmentComment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Enrollment;
use App\Plans\QuotaService;
use App\Traits\AuthorizesTenantAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\Uid\UuidV7;

class AssignmentController extends Controller
{
    use AuthorizesTenantAccess;

    private function isTeacher(Request $request): bool
    {
        return $this->isTenantStaff($request);
    }

    private function isEnrolled(Request $request, string $courseId): bool
    {
        return Enrollment::withoutTenant()
            ->where('course_id', $courseId)
            ->where('user_id', $request->user()->id)
            ->whereIn('status', ['active', 'completed'])
            ->exists();
    }

    private function requireAssignment(Request $request, Assignment $assignment): bool
    {
        $isTeacher = $this->isTeacher($request);
        if ($isTeacher) {
            return true;
        }

        return $assignment->is_published && $this->isEnrolled($request, $assignment->course_id);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['course_id' => 'required|string|exists:courses,id']);

        $course = Course::findOrFail($request->course_id);
        $isTeacher = $this->isTeacher($request);

        if (! $isTeacher && ! $this->isEnrolled($request, $course->id)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $query = Assignment::byTenant($course->tenant_id)
            ->where('course_id', $course->id)
            ->when(! $isTeacher, fn ($q) => $q->where('is_published', true))
            ->with([
                'submissions' => fn ($q) => $q
                    ->when(! $isTeacher, fn ($sq) => $sq->where('user_id', $request->user()->id))
                    ->with(['student.roles', 'comments.user']),
            ])
            ->orderBy('sort_order')
            ->orderByDesc('created_at');

        $assignments = $query->get();

        return response()->json([
            'assignments' => AssignmentResource::collection($assignments),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $isTeacher = $this->isTeacher($request);

        $assignment = Assignment::with([
            'submissions' => fn ($q) => $q
                ->when(! $isTeacher, fn ($sq) => $sq->where('user_id', $request->user()->id))
                ->with(['student.roles', 'comments.user']),
        ])->findOrFail($id);

        if (! $this->requireAssignment($request, $assignment)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        return response()->json([
            'assignment' => new AssignmentResource($assignment),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'course_id' => 'required|string|exists:courses,id',
            'section_id' => 'nullable|string|exists:sections,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'points' => 'nullable|numeric|min:0|max:999999',
            'due_at' => 'nullable|date',
            'allow_late' => 'nullable|boolean',
            'is_published' => 'nullable|boolean',
            'file_url' => 'nullable|string|max:500',
            'file_name' => 'nullable|string|max:255',
            'file_size_bytes' => 'nullable|integer|min:0',
        ]);

        if (! $this->isTeacher($request)) {
            return response()->json(['message' => 'Only teachers can create assignments.'], 403);
        }

        $course = Course::findOrFail($request->course_id);

        if ($course->tenant_id !== $request->header('X-Tenant-Id')) {
            return response()->json(['message' => 'Course does not belong to this tenant.'], 403);
        }

        app(QuotaService::class)->assertCan($course->tenant_id, 'assignments');

        $assignment = Assignment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $course->tenant_id,
            'course_id' => $course->id,
            'section_id' => $request->section_id,
            'title' => $request->title,
            'description' => $request->description,
            'points' => $request->points ?? 10,
            'due_at' => $request->due_at,
            'allow_late' => $request->boolean('allow_late', false),
            'is_published' => $request->boolean('is_published', false),
            'sort_order' => Assignment::withoutTenant()
                ->where('course_id', $course->id)
                ->max('sort_order') + 1,
            'file_url' => $request->file_url,
            'file_name' => $request->file_name,
            'file_size_bytes' => $request->file_size_bytes,
            'metadata' => [],
        ]);

        return response()->json([
            'assignment' => new AssignmentResource($assignment),
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'section_id' => 'nullable|string|exists:sections,id',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'points' => 'nullable|numeric|min:0|max:999999',
            'due_at' => 'nullable|date',
            'allow_late' => 'nullable|boolean',
            'is_published' => 'nullable|boolean',
            'file_url' => 'nullable|string|max:500',
            'file_name' => 'nullable|string|max:255',
            'file_size_bytes' => 'nullable|integer|min:0',
        ]);

        if (! $this->isTeacher($request)) {
            return response()->json(['message' => 'Only teachers can update assignments.'], 403);
        }

        $assignment = Assignment::findOrFail($id);

        if ($assignment->tenant_id !== $request->header('X-Tenant-Id')) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $assignment->update($request->validated());

        return response()->json([
            'assignment' => new AssignmentResource($assignment->fresh()),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if (! $this->isTeacher($request)) {
            return response()->json(['message' => 'Only teachers can delete assignments.'], 403);
        }

        $assignment = Assignment::findOrFail($id);

        if ($assignment->tenant_id !== $request->header('X-Tenant-Id')) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $assignment->delete();

        return response()->json(['message' => 'Assignment deleted.']);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'submission_text' => 'nullable|string|max:5000',
            'file_url' => 'nullable|string|max:500',
            'file_name' => 'nullable|string|max:255',
            'file_size_bytes' => 'nullable|integer|min:0',
        ]);

        $assignment = Assignment::findOrFail($id);

        if (! $this->requireAssignment($request, $assignment)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if ($this->isTeacher($request)) {
            return response()->json(['message' => 'Teachers cannot submit assignments.'], 403);
        }

        if (! $assignment->is_published) {
            return response()->json(['message' => 'This assignment is not published yet.'], 403);
        }

        if ($assignment->due_at?->lt(now()) && ! $assignment->allow_late) {
            return response()->json(['message' => 'The deadline for this assignment has passed.'], 403);
        }

        $tenantId = $request->header('X-Tenant-Id');
        $user = $request->user();

        $submission = AssignmentSubmission::withoutTenant()
            ->where('assignment_id', $assignment->id)
            ->where('user_id', $user->id)
            ->first();

        // A graded submission may not be silently resubmitted — the grade
        // would be wiped with no teacher consent and no history kept.
        if ($submission && $submission->status === 'graded') {
            return response()->json([
                'message' => 'This submission has already been graded. Ask your teacher to allow a resubmission.',
            ], 422);
        }

        if (! $submission) {
            $submission = new AssignmentSubmission(['id' => UuidV7::generate(), 'tenant_id' => $tenantId]);
        }

        $isLate = $assignment->due_at !== null && now()->gt($assignment->due_at);

        $submission->fill([
            'assignment_id' => $assignment->id,
            'user_id' => $user->id,
            'submission_text' => $request->submission_text,
            'file_url' => $request->file_url,
            'file_name' => $request->file_name,
            'file_size_bytes' => $request->file_size_bytes,
            'submitted_at' => now(),
            'status' => $isLate ? 'late' : 'submitted',
        ]);

        $submission->save();

        return response()->json([
            'submission' => new AssignmentSubmissionResource($submission->load(['student', 'comments.user'])),
        ], 201);
    }

    public function grade(Request $request, string $assignmentId, string $submissionId): JsonResponse
    {
        if (! $this->isTeacher($request)) {
            return response()->json(['message' => 'Only teachers can grade submissions.'], 403);
        }

        $submission = AssignmentSubmission::with('assignment')
            ->where('assignment_id', $assignmentId)
            ->findOrFail($submissionId);

        if ($submission->tenant_id !== $request->header('X-Tenant-Id')) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        // A grade can never exceed the assignment's points.
        $maxPoints = (float) ($submission->assignment->points ?? 0);

        $request->validate([
            'grade' => "required|numeric|min:0|max:{$maxPoints}",
            'grade_comment' => 'nullable|string|max:1000',
        ]);

        $submission->update([
            'grade' => $request->grade,
            'grade_comment' => $request->grade_comment,
            'graded_at' => now(),
            'graded_by' => $request->user()->id,
            'status' => 'graded',
        ]);

        return response()->json([
            'submission' => new AssignmentSubmissionResource($submission->fresh()->load(['student', 'comments.user'])),
        ]);
    }

    public function comment(Request $request, string $submissionId): JsonResponse
    {
        $request->validate([
            'comment' => 'required|string|max:2000',
        ]);

        $submission = AssignmentSubmission::with('assignment')->findOrFail($submissionId);

        $isTeacher = $this->isTeacher($request);
        $isOwner = $submission->user_id === $request->user()->id;

        if (! $isTeacher && ! $isOwner) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if (! $isTeacher && ! $submission->assignment->is_published) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $comment = AssignmentComment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $submission->tenant_id,
            'submission_id' => $submission->id,
            'user_id' => $request->user()->id,
            'comment' => $request->comment,
        ]);

        return response()->json([
            'comment' => [
                'id' => $comment->id,
                'submission_id' => $comment->submission_id,
                'user_id' => $comment->user_id,
                'user_name' => $comment->user?->name,
                'comment' => $comment->comment,
                'created_at' => $comment->created_at,
            ],
        ], 201);
    }
}
