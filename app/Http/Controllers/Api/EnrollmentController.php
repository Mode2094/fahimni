<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Enrollment\EnrollStudentRequest;
use App\Http\Resources\EnrollmentResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\IntegrationService;
use App\Services\StudentGamificationService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Enrollment::class);

        $user = $request->user();
        $tenantId = $request->header('X-Tenant-Id');

        $enrollments = Enrollment::with(['course', 'student.roles', 'academicTerm'])
            ->when(
                ! $user->is_super_admin,
                fn ($q) => $q->where(function ($q) use ($tenantId) {
                    $q->whereHas('course', fn ($q) => $q->byTenant($tenantId));
                })
            )
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->course_id))
            ->when($request->filled('academic_term_id'), fn ($q) => $q->where('academic_term_id', $request->academic_term_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('enrolled_at')
            ->get();

        return response()->json([
            'enrollments' => EnrollmentResource::collection($enrollments),
        ]);
    }

    public function myCourses(Request $request): JsonResponse
    {
        $user = $request->user();

        $enrollments = Enrollment::with(['course.sections.lessons', 'academicTerm'])
            ->where('user_id', $user->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('enrolled_at')
            ->get();

        return response()->json([
            'enrollments' => EnrollmentResource::collection($enrollments),
        ]);
    }

    public function store(EnrollStudentRequest $request): JsonResponse
    {
        $this->authorize('create', Enrollment::class);

        $course = Course::findOrFail($request->course_id);
        $student = User::findOrFail($request->user_id);

        // Use the middleware-resolved tenant (never the raw header) so the
        // isolation can never be bypassed by header tricks.
        $tenantId = $request->attributes->get('current_tenant')?->getKey()
            ?? $request->header('X-Tenant-Id');

        if ($course->tenant_id !== $tenantId) {
            return response()->json(['message' => 'Course does not belong to this tenant.'], 403);
        }

        // The selected user must be an active STUDENT of this tenant.
        $service = app(EnrollmentService::class);
        $service->assertTenantStudent((string) $tenantId, $student);

        // Membership + role + term-state + seat + duplicate + per-course-cap
        // enforcement all live in the service (under a tenant lock). Only an
        // explicit, authorised move may replace a live enrollment.
        $enrollment = $service->enroll($course, $student, [
            'academic_term_id' => $request->input('academic_term_id'),
            'expires_at' => $request->input('expires_at'),
            'source' => $request->input('source', 'manual'),
            'metadata' => $request->input('metadata', []),
            'authorized_move' => (bool) $request->boolean('authorized_move'),
        ]);

        app(StudentGamificationService::class)->logActivity(
            $student,
            $tenantId,
            'enrollment',
            'سجّل في الكورس: '.($course->title ?? ''),
            ['course_id' => $course->id, 'source' => $enrollment->source]
        );

        app(IntegrationService::class)->triggerZapier($tenantId, 'new_enrollment', [
            'student' => ['id' => $student->id, 'name' => $student->name, 'email' => $student->email],
            'course' => ['id' => $course->id, 'title' => $course->title],
            'enrollment_id' => $enrollment->id,
            'source' => $enrollment->source,
        ]);

        app(StudentGamificationService::class)->checkAchievements($student, $tenantId);

        return response()->json([
            'enrollment' => new EnrollmentResource($enrollment),
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $enrollment = Enrollment::with(['course', 'student', 'academicTerm'])->findOrFail($id);

        $this->authorize('view', $enrollment);

        return response()->json([
            'enrollment' => new EnrollmentResource($enrollment),
        ]);
    }

    /**
     * Remove a student from a course.
     *
     * Default behaviour is a SAFE ARCHIVE: access stops, the seat is released,
     * and lesson progress / certificates / attempts stay intact. A hard
     * delete (which cascades to lesson_progress) is an exceptional admin
     * action — restricted to super admins and explicitly requested.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $enrollment = Enrollment::findOrFail($id);

        $this->authorize('delete', $enrollment);

        if ($request->boolean('force')) {
            if (! $request->user()->is_super_admin) {
                return response()->json([
                    'message' => 'Hard delete is an exceptional admin action.',
                    'code' => 'force_delete_forbidden',
                ], 403);
            }

            $enrollment->delete();

            AuditLogger::log($request, 'enrollment.force_deleted', 'enrollment', $id, [
                'course_id' => $enrollment->course_id,
                'user_id' => $enrollment->user_id,
            ]);

            return response()->json(['message' => 'Enrollment force-deleted.', 'code' => 'force_deleted']);
        }

        app(EnrollmentService::class)->archive($enrollment);

        AuditLogger::log($request, 'enrollment.archived', 'enrollment', $id, [
            'course_id' => $enrollment->course_id,
            'user_id' => $enrollment->user_id,
        ]);

        return response()->json([
            'message' => 'Enrollment archived. Progress, certificates and results are preserved.',
            'code' => 'archived',
        ]);
    }
}
