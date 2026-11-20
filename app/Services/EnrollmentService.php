<?php

namespace App\Services;

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
 * card redemption, admin-created student, term renewal) goes through here so
 * that seat accounting, duplicate protection and per-course caps are enforced
 * identically. The tenant row lock taken first makes the seat check atomic
 * even when two students are enrolled concurrently in *different* courses.
 */
class EnrollmentService
{
    public function __construct(
        private readonly StudentSeatService $seats,
        private readonly QuotaService $quota,
        private readonly PlanEngine $engine,
    ) {}

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
     * Re-joining the same course in a LATER term is allowed: the previous
     * live enrollment is archived (its row, grades and attempts stay intact)
     * and a fresh enrollment opens in the new term.
     *
     * @param  array<string, mixed>  $attrs  status/source/expires_at/metadata/academic_term_id overrides
     */
    public function enroll(Course $course, User $student, array $attrs = []): Enrollment
    {
        $tenantId = (string) $course->tenant_id;

        return DB::transaction(function () use ($course, $student, $tenantId, $attrs) {
            // Serialize every seat decision for this teacher.
            $this->seats->lockTenant($tenantId);

            $termId = $attrs['academic_term_id'] ?? null;

            // Capture seat occupancy BEFORE we touch anything: re-joining the
            // same course in a new term must not look like a brand-new seat.
            $heldSeat = $this->seats->studentHoldsSeat($tenantId, $student->id);

            // Guarded rows (any status that the partial unique index counts).
            $existing = Enrollment::withoutTenant()
                ->where('tenant_id', $tenantId)
                ->where('course_id', $course->id)
                ->where('user_id', $student->id)
                ->whereIn('status', Enrollment::EFFECTIVE_STATUSES)
                ->get();

            $live = $existing->first(fn (Enrollment $e) => $e->isEffective());

            // A live enrollment blocks a plain re-enroll; a DIFFERENT term is
            // allowed (the old period is archived and a new one opens).
            if ($live && ! ($termId && (string) $live->academic_term_id !== (string) $termId)) {
                throw new HttpResponseException(
                    response()->json(['message' => 'Student is already enrolled in this course.'], 409)
                );
            }

            // Move every guarded row out of the unique-index state (archiving
            // keeps the history). This also clears expired-but-active rows so
            // the fresh insert can never trip the partial unique index.
            foreach ($existing as $row) {
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
     * new row is created directly (no seat re-check — the seat is retained).
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
