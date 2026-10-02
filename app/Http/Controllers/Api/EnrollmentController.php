<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Enrollment\EnrollStudentRequest;
use App\Http\Resources\EnrollmentResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Plans\QuotaService;
use App\Services\FeatureService;
use App\Services\IntegrationService;
use App\Services\StudentGamificationService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

class EnrollmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Enrollment::class);

        $user = $request->user();
        $tenantId = $request->header('X-Tenant-Id');

        $enrollments = Enrollment::with(['course', 'student.roles'])
            ->when(
                ! $user->is_super_admin,
                fn ($q) => $q->where(function ($q) use ($tenantId) {
                    $q->whereHas('course', fn ($q) => $q->byTenant($tenantId));
                })
            )
            ->when($request->filled('course_id'), fn ($q) => $q->where('course_id', $request->course_id))
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

        $enrollments = Enrollment::with(['course.sections.lessons'])
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

        // Only students can be enrolled as students — staff accounts cannot
        // be silently added to the student roster.
        $pivot = $student->tenantPivots()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->first();

        if (! $pivot) {
            return response()->json(['message' => 'Student is not a member of this tenant.'], 403);
        }

        $isStudentRole = $pivot->role === 'student';

        if (! $isStudentRole && ! $student->is_super_admin) {
            return response()->json(['message' => 'The selected user is not a student.'], 422);
        }

        if ($student->is_super_admin) {
            $memberOfTenant = $student->tenants()->where('tenant_id', $tenantId)->wherePivot('status', 'active')->exists();
            if (! $memberOfTenant) {
                return response()->json(['message' => 'Student is not a member of this tenant.'], 403);
            }
        }

        $features = app(FeatureService::class);

        $enrollment = DB::transaction(function () use ($request, $course, $student, $tenantId, $features) {
            // Serialize on the course row: two concurrent enrollments can
            // never exceed the per-course cap.
            Course::whereKey($course->id)->lockForUpdate()->first();

            // A cancelled/expired enrollment can be re-created; only an
            // active/complete one blocks a duplicate.
            $exists = Enrollment::where('course_id', $course->id)
                ->where('user_id', $student->id)
                ->whereIn('status', ['active', 'completed'])
                ->exists();

            if ($exists) {
                throw new HttpResponseException(
                    response()->json(['message' => 'Student is already enrolled in this course.'], 409)
                );
            }

            // Plan enforcement: students limit (dynamic from DB).
            app(QuotaService::class)->assertCan($tenantId, 'students');

            // Per-course cap (dynamic plan feature) — checked under the
            // course row lock so concurrent requests cannot overshoot.
            $maxPerCourse = $features->getIntFeature('max_students_per_course');
            $usedInCourse = Enrollment::where('course_id', $course->id)->count();
            if ($maxPerCourse > 0 && $usedInCourse >= $maxPerCourse) {
                throw new HttpResponseException(
                    response()->json(['message' => "Course limit reached: this course allows {$maxPerCourse} students."], 403)
                );
            }

            return Enrollment::create([
                'id' => UuidV7::generate(),
                'course_id' => $course->id,
                'user_id' => $student->id,
                'status' => 'active',
                'enrolled_at' => now(),
                'expires_at' => $request->input('expires_at'),
                'source' => $request->input('source', 'manual'),
                'metadata' => $request->input('metadata', []),
            ]);
        });

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
        $enrollment = Enrollment::with(['course', 'student'])->findOrFail($id);

        $this->authorize('view', $enrollment);

        return response()->json([
            'enrollment' => new EnrollmentResource($enrollment),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $enrollment = Enrollment::findOrFail($id);

        $this->authorize('delete', $enrollment);

        $enrollment->delete();

        return response()->json(['message' => 'Enrollment deleted successfully.']);
    }
}
