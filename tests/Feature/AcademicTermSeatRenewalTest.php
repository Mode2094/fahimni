<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\ActivationCard;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\PlanFeature;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Plans\Exceptions\QuotaExceededException;
use App\Plans\PlanEngine;
use App\Services\EnrollmentService;
use App\Services\StudentSeatService;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Uid\UuidV7;

/**
 * Acceptance tests for the academic-terms + student-seat-renewal system.
 *
 * The contract under test: a student occupies ONE seat per teacher while they
 * have at least one effective enrollment; ending a term releases only the
 * seats whose students have no other active enrollment; history is never
 * destroyed and a full plan never blocks an already-counted student.
 */
class AcademicTermSeatRenewalTest extends TenantTestCase
{
    private function setStudentsLimit(Tenant $tenant, int $limit): void
    {
        $sub = TenantSubscription::where('tenant_id', $tenant->id)->firstOrFail();

        PlanFeature::where('plan_id', $sub->plan_id)
            ->where('feature_key', 'students_limit')
            ->update(['feature_value' => (string) $limit]);

        PlanEngine::bumpVersion();
        Cache::forget('plan:usage:'.$tenant->id);
    }

    private function course(Tenant $tenant, string $title = 'Course'): Course
    {
        return Course::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'title' => $title,
            'slug' => 'c-'.strtolower(UuidV7::generate()),
            'is_published' => true,
        ]);
    }

    private function student(Tenant $tenant): User
    {
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'student');

        return $user;
    }

    private function enroll(Tenant $tenant, Course $course, User $student, array $attrs = []): Enrollment
    {
        return app(EnrollmentService::class)->enroll($course, $student, $attrs);
    }

    private function seats(Tenant $tenant): int
    {
        return app(StudentSeatService::class)->count($tenant->id);
    }

    // ───────────────────────── seat counting ─────────────────────────

    public function test_one_student_many_courses_counts_as_one_seat(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);

        $a = $this->course($tenant, 'A');
        $b = $this->course($tenant, 'B');
        $c = $this->course($tenant, 'C');

        $s1 = $this->student($tenant);
        $s2 = $this->student($tenant);
        $s3 = $this->student($tenant);

        $this->enroll($tenant, $a, $s1);
        $this->enroll($tenant, $b, $s1);
        $this->enroll($tenant, $c, $s1);
        $this->enroll($tenant, $a, $s2);
        $this->enroll($tenant, $b, $s3);

        $this->assertSame(5, Enrollment::withoutTenant()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(3, $this->seats($tenant));
    }

    public function test_expired_enrollment_releases_seat_and_reactivation_regains_it(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $course = $this->course($tenant);
        $student = $this->student($tenant);

        $enrollment = $this->enroll($tenant, $course, $student, ['expires_at' => now()->subDay()]);
        $this->assertSame(0, $this->seats($tenant));

        $enrollment->update(['expires_at' => now()->addMonth()]);
        $this->assertSame(1, $this->seats($tenant));
    }

    // ───────────────────────── plan enforcement ─────────────────────────

    public function test_already_counted_student_takes_second_course_even_when_plan_full(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $this->setStudentsLimit($tenant, 2);

        $c1 = $this->course($tenant, 'C1');
        $c2 = $this->course($tenant, 'C2');

        $s1 = $this->student($tenant);
        $s2 = $this->student($tenant);

        $this->enroll($tenant, $c1, $s1);
        $this->enroll($tenant, $c1, $s2);
        $this->assertSame(2, $this->seats($tenant)); // plan is full

        // Second course for an already-counted student must NOT be rejected.
        $this->enroll($tenant, $c2, $s1);
        $this->assertSame(2, $this->seats($tenant));
    }

    public function test_new_student_rejected_when_plan_full(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $this->setStudentsLimit($tenant, 2);

        $course = $this->course($tenant);
        $this->enroll($tenant, $course, $this->student($tenant));
        $this->enroll($tenant, $course, $this->student($tenant));

        $this->expectException(QuotaExceededException::class);
        $this->enroll($tenant, $course, $this->student($tenant));
    }

    public function test_seats_do_not_leak_between_tenants(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $this->attachSubscription($a);
        $this->attachSubscription($b);

        $courseA = $this->course($a);
        $courseB = $this->course($b);

        $this->enroll($a, $courseA, $this->student($a));
        $this->enroll($b, $courseB, $this->student($b));
        $this->enroll($b, $courseB, $this->student($b));

        $this->assertSame(1, $this->seats($a));
        $this->assertSame(2, $this->seats($b));
    }

    // ───────────────────────── terms: end + renew ─────────────────────────

    public function test_ending_a_term_frees_seats_without_deleting_history(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $course = $this->course($tenant);

        $term = AcademicTerm::create([
            'tenant_id' => $tenant->id, 'name' => 'T1', 'status' => AcademicTerm::STATUS_ACTIVE,
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addDay(),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->enroll($tenant, $course, $this->student($tenant), ['academic_term_id' => $term->id]);
        }

        $this->assertSame(5, $this->seats($tenant));

        $summary = app(\App\Services\AcademicTermService::class)->end($term, [], null);

        $this->assertSame(5, $summary['seats_freed']);
        $this->assertSame(0, $summary['seats_after']);
        $this->assertSame(0, $this->seats($tenant));

        // No row was deleted — they are archived, still queryable for history.
        $this->assertSame(5, Enrollment::withoutTenant()
            ->where('tenant_id', $tenant->id)
            ->where('academic_term_id', $term->id)
            ->where('status', 'archived')
            ->count());

        $this->assertSame(AcademicTerm::STATUS_ARCHIVED, $term->fresh()->status);
    }

    public function test_student_active_in_another_course_keeps_seat_when_term_ends(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $courseA = $this->course($tenant, 'A');
        $courseB = $this->course($tenant, 'B');

        $term = AcademicTerm::create([
            'tenant_id' => $tenant->id, 'name' => 'T1', 'status' => AcademicTerm::STATUS_ACTIVE,
        ]);

        $student = $this->student($tenant);
        $this->enroll($tenant, $courseA, $student, ['academic_term_id' => $term->id]);
        $this->enroll($tenant, $courseB, $student); // termless: stays active

        $summary = app(\App\Services\AcademicTermService::class)->end($term, [], null);

        $this->assertSame(0, $summary['seats_freed']);
        $this->assertSame(1, $this->seats($tenant));
    }

    public function test_continuing_students_keep_seats_in_next_term(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $course = $this->course($tenant);

        $term1 = AcademicTerm::create(['tenant_id' => $tenant->id, 'name' => 'T1', 'status' => AcademicTerm::STATUS_ACTIVE]);
        $term2 = AcademicTerm::create(['tenant_id' => $tenant->id, 'name' => 'T2', 'status' => AcademicTerm::STATUS_UPCOMING]);

        $students = [];
        for ($i = 0; $i < 10; $i++) {
            $students[] = $this->student($tenant);
            $this->enroll($tenant, $course, end($students), ['academic_term_id' => $term1->id]);
        }

        $ids = array_map(fn (User $u) => $u->id, $students);

        $summary = app(\App\Services\AcademicTermService::class)->end($term1, $ids, $term2);

        $this->assertSame(0, $summary['seats_freed']);
        $this->assertSame(10, $summary['seats_after']);
        $this->assertSame(10, $this->seats($tenant));

        // Term 2 now holds the live enrollments; term 1 keeps the history.
        $this->assertSame(10, Enrollment::withoutTenant()
            ->where('tenant_id', $tenant->id)->where('academic_term_id', $term2->id)->where('status', 'active')->count());
        $this->assertSame(10, Enrollment::withoutTenant()
            ->where('tenant_id', $tenant->id)->where('academic_term_id', $term1->id)->where('status', 'archived')->count());
    }

    public function test_rejoin_same_course_in_new_term_preserves_both_rows(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $course = $this->course($tenant);
        $student = $this->student($tenant);

        $term1 = AcademicTerm::create(['tenant_id' => $tenant->id, 'name' => 'T1', 'status' => AcademicTerm::STATUS_ACTIVE]);
        $term2 = AcademicTerm::create(['tenant_id' => $tenant->id, 'name' => 'T2', 'status' => AcademicTerm::STATUS_ACTIVE]);

        // Term 1 is enrolled, then ended (archived) — its row stays as history.
        $e1 = $this->enroll($tenant, $course, $student, ['academic_term_id' => $term1->id]);
        app(\App\Services\AcademicTermService::class)->end($term1, []);

        // Re-joining the SAME course in the new term adds a fresh live row.
        $e2 = $this->enroll($tenant, $course, $student, ['academic_term_id' => $term2->id]);

        $this->assertNotSame($e1->id, $e2->id);
        $this->assertSame('archived', $e1->fresh()->status);
        $this->assertSame('active', $e2->fresh()->status);

        $this->assertSame(2, Enrollment::withoutTenant()
            ->where('tenant_id', $tenant->id)->where('course_id', $course->id)->where('user_id', $student->id)->count());
        $this->assertSame(1, $this->seats($tenant));
    }

    public function test_ending_a_term_is_idempotent(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $course = $this->course($tenant);

        $term = AcademicTerm::create(['tenant_id' => $tenant->id, 'name' => 'T1', 'status' => AcademicTerm::STATUS_ACTIVE]);
        $this->enroll($tenant, $course, $this->student($tenant), ['academic_term_id' => $term->id]);

        $service = app(\App\Services\AcademicTermService::class);
        $first = $service->end($term, [], null);
        $second = $service->end($term->fresh(), [], null);

        $this->assertSame($first['seats_freed'], $second['seats_freed']);
        $this->assertSame(0, $this->seats($tenant));
    }

    // ───────────────────────── HTTP contract ─────────────────────────

    public function test_teacher_can_create_preview_and_end_a_term_over_http(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $course = $this->course($tenant);

        $headers = $this->authHeaders($teacher, $tenant);

        $created = $this->withHeaders($headers)->postJson('/api/v1/terms', [
            'name' => 'الفصل الأول',
            'starts_at' => now()->toIso8601String(),
            'ends_at' => now()->addMonths(3)->toIso8601String(),
        ])->assertCreated()->json('term');

        $termId = $created['id'];

        foreach ([$this->student($tenant), $this->student($tenant)] as $student) {
            $this->enroll($tenant, $course, $student, ['academic_term_id' => $termId]);
        }

        $preview = $this->withHeaders($headers)
            ->getJson("/api/v1/terms/{$termId}/end-preview")
            ->assertOk()
            ->json('preview');

        $this->assertSame(2, $preview['seats_freed']);
        $this->assertSame(0, $preview['seats_after']);

        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/terms/{$termId}/end", [])
            ->assertOk()
            ->json();

        $this->assertSame(2, $response['summary']['seats_freed']);
        $this->assertSame(0, $response['seats']['used']);
    }

    public function test_students_cannot_manage_terms(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $student = $this->student($tenant);

        $headers = $this->authHeaders($student, $tenant);

        $this->withHeaders($headers)->getJson('/api/v1/terms')->assertForbidden();
        $this->withHeaders($headers)->postJson('/api/v1/terms', ['name' => 'X'])->assertForbidden();
    }

    // ─────────────── exact capacity arithmetic ───────────────

    public function test_85_existing_plus_10_continuing_equals_95_of_100(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $this->setStudentsLimit($tenant, 100);

        $course = $this->course($tenant);
        $term1 = AcademicTerm::create(['tenant_id' => $tenant->id, 'name' => 'T1', 'status' => AcademicTerm::STATUS_ACTIVE]);
        $term2 = AcademicTerm::create(['tenant_id' => $tenant->id, 'name' => 'T2', 'status' => AcademicTerm::STATUS_UPCOMING]);

        // 85 students already holding a seat outside this term.
        for ($i = 0; $i < 85; $i++) {
            $this->enroll($tenant, $course, $this->student($tenant));
        }

        // 10 more join term 1.
        $continuing = [];
        for ($i = 0; $i < 10; $i++) {
            $s = $this->student($tenant);
            $this->enroll($tenant, $course, $s, ['academic_term_id' => $term1->id]);
            $continuing[] = $s->id;
        }

        $this->assertSame(95, $this->seats($tenant));

        // End term 1 carrying the 10 forward: 85 + 10 = 95 seats stay used.
        $summary = app(\App\Services\AcademicTermService::class)->end($term1, $continuing, $term2);

        $this->assertSame(0, $summary['seats_freed']);
        $this->assertSame(95, $summary['seats_after']);
        $this->assertSame(95, $this->seats($tenant));

        // Freed capacity is genuinely usable: 5 more fit, the 101st is blocked.
        for ($i = 0; $i < 5; $i++) {
            $this->enroll($tenant, $course, $this->student($tenant));
        }
        $this->assertSame(100, $this->seats($tenant));

        $this->expectException(QuotaExceededException::class);
        $this->enroll($tenant, $course, $this->student($tenant));
    }

    public function test_ending_a_full_100_student_term_frees_all_seats(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $this->setStudentsLimit($tenant, 100);

        $course = $this->course($tenant);
        $term = AcademicTerm::create(['tenant_id' => $tenant->id, 'name' => 'T1', 'status' => AcademicTerm::STATUS_ACTIVE]);

        for ($i = 0; $i < 100; $i++) {
            $this->enroll($tenant, $course, $this->student($tenant), ['academic_term_id' => $term->id]);
        }

        $this->assertSame(100, $this->seats($tenant));

        $summary = app(\App\Services\AcademicTermService::class)->end($term, [], null);

        $this->assertSame(100, $summary['seats_freed']);
        $this->assertSame(0, $summary['seats_after']);
        $this->assertSame(0, $this->seats($tenant));

        // 100 archived history rows remain — nothing was deleted.
        $this->assertSame(100, Enrollment::withoutTenant()
            ->where('tenant_id', $tenant->id)
            ->where('academic_term_id', $term->id)
            ->where('status', 'archived')
            ->count());
    }

    // ─────────────── activation-card path ───────────────

    public function test_activation_card_redeem_consumes_one_seat_and_respects_plan(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $this->setStudentsLimit($tenant, 1);
        $course = $this->course($tenant);

        $card1 = ActivationCard::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'code' => 'CARDONE', 'status' => 'generated']);
        $card2 = ActivationCard::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'code' => 'CARDTWO', 'status' => 'generated']);

        $s1 = $this->student($tenant);
        $s2 = $this->student($tenant);

        $this->withHeaders($this->authHeaders($s1, $tenant))
            ->postJson('/api/v1/cards/redeem', ['code' => $card1->code])
            ->assertOk();

        $this->assertSame(1, $this->seats($tenant));
        $this->assertSame('redeemed', $card1->fresh()->status);

        // Plan is now full: a brand-new student's card must be rejected.
        $this->withHeaders($this->authHeaders($s2, $tenant))
            ->postJson('/api/v1/cards/redeem', ['code' => $card2->code])
            ->assertForbidden();

        $this->assertSame(1, $this->seats($tenant));
        $this->assertSame('generated', $card2->fresh()->status);
    }
}
