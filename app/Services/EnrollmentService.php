<?php

namespace App\Services;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Plans\PlanEngine;
use App\Plans\QuotaService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * The one place a student gets enrolled.
 *
 * Every path that can create an enrollment (manual roster add, activation
 * card redemption, admin-created student, term assignment) goes through here
 * so that membership checks, term-state validation, seat accounting,
 * duplicate protection and per-course caps are enforced identically. The
 * tenant row lock taken first makes the seat check atomic even when two
 * students are enrolled concurrently in different courses.
 */
class EnrollmentService
{
    /** Term states in which a student may be enrolled. */
    public const ENROLLABLE_TERM_STATUSES = [AcademicTerm::STATUS_UPCOMING, AcademicTerm::STATUS_ACTIVE];

    public function __construct(
        private readonly StudentSeatService $seats,
        private readonly QuotaService $quota,
        private readonly PlanEngine $engine,
    ) {}

    /**
     * A user may only be added to a roster as a STUDENT of the given tenant:
     * an ACTIVE membership whose role is `student`. Staff/teachers and users
     * belonging to another teacher are rejected (super admins must still be
     * an active member of the tenant).
     */
    public function assertTenantStudent(string $tenantId, User $user): void
    {
        $pivot = $user->tenantPivots()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->first();

        if (! $pivot) {
            throw new HttpResponseException(
                response()->json(['message' => 'Student is not a member of this tenant.', 'code' => 'not_member'], 403)
            );
        }

        if ($pivot->role !== 'student' && ! $user->is_super_admin) {
            throw new HttpResponseException(
                response()->json(['message' => 'The selected user is not a student.', 'code' => 'not_student'], 422)
            );
        }
    }

    /**
     * Validate that a term exists, belongs to the tenant and still accepts
     * enrollments. Archived/completed terms are closed to new enrollments;
     * historical seats must be renewed through the term-renewal flow instead.
     */
    public function assertTermAcceptsEnrollment(string $tenantId, string $termId): AcademicTerm
    {
        $term = AcademicTerm::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->whereKey($termId)
            ->first();

        if (! $term) {
            throw new HttpResponseException(
                response()->json(['message' => 'Term does not belong to this tenant.', 'code' => 'term_not_found'], 422)
            );
        }

        if (! in_array($term->status, self::ENROLLABLE_TERM_STATUSES, true)) {
            throw new HttpResponseException(
                response()->json(['message' => 'Term is closed for enrollment.', 'code' => 'term_closed'], 422)
            );
        }

        return $term;
    }

    /**
     * Assert the tenant has room for this student — but only when the student
     * is not already holding a seat. Enrolling an already-counted student in
     * another course must never be blocked by a full plan.
     */
    public function assertSeatAvailable(string $tenantId, string $userId): void
    {
        if ($this->seats->studentHoldsSeat($tenantId, $userId)) {
            return;
        }

        $this->quota->assertCan($tenantId, 'students');
    }

    /**
     * Assert room for a *brand new* student (cannot already hold a seat).
     * Used before the account row is created so a full plan fails cleanly.
     */
    public function assertNewSeatAvailable(string $tenantId): void
    {
        $this->quota->assertCan($tenantId, 'students');
    }

    /**
     * Per-course cap, counting only enrollments that actually consume a slot
     * (archived term history no longer holds a place in the course).
     */
    public function assertCourseCapacity(string $tenantId, string $courseId): void
    {
        $maxPerCourse = $this->engine->intValue($tenantId, 'max_students_per_course', 0);

        if ($maxPerCourse <= 0) {
            return;
        }

        $used = $this->seats->effectiveQuery($tenantId)
            ->where('course_id', $courseId)
            ->distinct()
            ->count('user_id');

        if ($used >= $maxPerCourse) {
            throw new HttpResponseException(
                response()->json(['message' => "Course limit reached: this course allows {$maxPerCourse} students."], 403)
            );
        }
    }

    /**
     * Create an enrollment atomically.
     *
     * A live enrollment for the same (course, student) normally blocks a new
     * one (409). Moving a student into a DIFFERENT term is only allowed when
     * the caller explicitly authorises it with `authorized_move` — that is an
     * intentional, auditable transition (e.g. an admin re-enrolling a student
     * into next term). Passing a different `academic_term_id` alone must never
     * silently archive an ongoing enrollment.
     *
     * @param  array<string, mixed>  $attrs  status/source/expires_at/metadata/academic_term_id overrides
     */
    public function enroll(Course $course, User $student, array $attrs = []): Enrollment
    {
        $tenantId = (string) $course->tenant_id;
        $termId = isset($attrs['academic_term_id']) && $attrs['academic_term_id'] !== ''
            ? (string) $attrs['academic_term_id']
            : null;
        $authorizedMove = (bool) ($attrs['authorized_move'] ?? false);

        unset($attrs['authorized_move']);

        return DB::transaction(function () use ($course, $student, $tenantId, $termId, $authorizedMove, $attrs) {
            // Serialize every seat decision for this teacher.
            $this->seats->lockTenant($tenantId);

            // Defence-in-depth: the target user must be an active STUDENT of
            // this tenant. Every enrollment path inherits this guard.
            $this->assertTenantStudent($tenantId, $student);

            // Validate the target term (belongs to tenant + still open). Null
            // means "no term" and is always allowed.
            if ($termId !== null) {
                $this->assertTermAcceptsEnrollment($tenantId, $termId);
            }

            // Capture seat occupancy BEFORE we touch anything: moving a
            // student in the same teacher's roster must not look like a new seat.
            $heldSeat = $this->seats->studentHoldsSeat($tenantId, $student->id);

            // Rows guarded by the partial unique index (active/completed),
            // regardless of expiry.
            $guarded = Enrollment::withoutTenant()
                ->where('tenant_id', $tenantId)
                ->where('course_id', $course->id)
                ->where('user_id', $student->id)
                ->whereIn('status', Enrollment::EFFECTIVE_STATUSES)
                ->get();

            $live = $guarded->first(fn (Enrollment $e) => $e->isEffective());

            if ($live) {
                $sameTerm = (string) $live->academic_term_id === (string) $termId;

                // Only an explicit, authorised term transition may archive a
                // live enrollment. Anything else is a duplicate → 409.
                if (! $authorizedMove || $sameTerm) {
                    throw new HttpResponseException(
                        response()->json(['message' => 'Student is already enrolled in this course.', 'code' => 'already_enrolled'], 409)
                    );
                }
            }

            // Clear every guarded row out of the unique-index state. This
            // archives expired-but-active rows too, so the fresh insert can
            // never trip the partial unique index.
            foreach ($guarded as $row) {
                $row->update([
                    'status' => 'archived',
                    'archived_at' => now(),
                    'expires_at' => $row->expires_at ?? now(),
                ]);
            }

            if (! $heldSeat) {
                $this->quota->assertCan($tenantId, 'students');
            }

            $this->assertCourseCapacity($tenantId, $course->id);

            $enrollment = Enrollment::create(array_merge([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenantId,
                'course_id' => $course->id,
                'user_id' => $student->id,
                'academic_term_id' => $termId,
                'status' => 'active',
                'enrolled_at' => now(),
                'source' => 'manual',
                'metadata' => [],
            ], $attrs));

            $this->seats->flushUsageCache($tenantId);

            return $enrollment;
        });
    }

    /**
     * Archive an enrollment without deleting it: access stops, history stays.
     */
    public function archive(Enrollment $enrollment): void
    {
        $enrollment->update([
            'status' => 'archived',
            'archived_at' => now(),
            'expires_at' => $enrollment->expires_at ?? now(),
        ]);

        $this->seats->flushUsageCache((string) $enrollment->tenant_id);
    }

    /**
     * Clear any partial-unique-guarded row for (course, user) so a fresh
     * enrollment can be inserted. Used by term renewal/promotion, where the
     * new row is created directly (the seat is retained and the transition is
     * explicitly authorised by the term flow).
     */
    public function closeGuardedRows(string $tenantId, string $courseId, string $userId): void
    {
        Enrollment::withoutTenant()
            ->where('tenant_id', $tenantId)
            ->where('course_id', $courseId)
            ->where('user_id', $userId)
            ->whereIn('status', Enrollment::EFFECTIVE_STATUSES)
            ->update(['status' => 'archived', 'archived_at' => now()]);
    }
}
