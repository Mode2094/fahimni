<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Announcement;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\PlanFeature;
use App\Models\Section;
use App\Models\StudentCertificate;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Plans\Exceptions\QuotaExceededException;
use App\Plans\PlanEngine;
use App\Services\AcademicTermService;
use App\Services\EnrollmentService;
use App\Services\StudentSeatService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Uid\UuidV7;

/**
 * Hardening coverage for the follow-up review: linking pre-migration
 * enrollments, cross-tenant assignment guards, term-lifecycle state machine,
 * live preview + stale-token protection, atomic/idempotent end, rollback on
 * mid-operation failure, per-term exam budgets, and preservation of history.
 */
class AcademicTermHardeningTest extends TenantTestCase
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

    private function teacher(Tenant $tenant): User
    {
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'teacher');

        return $user;
    }

    private function term(Tenant $tenant, string $name = 'T', string $status = AcademicTerm::STATUS_ACTIVE): AcademicTerm
    {
        return AcademicTerm::create([
            'tenant_id' => $tenant->id, 'name' => $name, 'status' => $status,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);
    }

    private function enroll(Course $course, User $student, array $attrs = []): Enrollment
    {
        return app(EnrollmentService::class)->enroll($course, $student, $attrs);
    }

    private function seats(Tenant $tenant): int
    {
        return app(StudentSeatService::class)->count($tenant->id);
    }

    private function lesson(Tenant $tenant, Course $course, string $title = 'L'): Lesson
    {
        $section = Section::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'course_id' => $course->id, 'title' => 'S', 'sort_order' => 0,
        ]);

        return Lesson::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'section_id' => $section->id, 'title' => $title,
            'lesson_type' => 'video', 'is_published' => true,
            'is_free_preview' => false, 'sort_order' => 0,
        ]);
    }

    // ─────────────── (1)+(2) link existing enrollments, keep history ───────────────

    public function test_link_100_pre_migration_enrollments_then_end_frees_all_seats(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $this->setStudentsLimit($tenant, 200);

        $course = $this->course($tenant);
        $term = $this->term($tenant, 'T1');

        // 100 students enrolled BEFORE terms existed → academic_term_id NULL.
        $ids = [];
        $originalEnrollmentIds = [];
        for ($i = 0; $i < 100; $i++) {
            $s = $this->student($tenant);
            $e = $this->enroll($course, $s);
            $ids[] = $s->id;
            $originalEnrollmentIds[] = $e->id;
        }

        $this->assertSame(100, $this->seats($tenant));
        $this->assertSame(100, Enrollment::withoutTenant()
            ->where('tenant_id', $tenant->id)->whereNull('academic_term_id')->count());

        $result = app(AcademicTermService::class)->linkEnrollments($term, $ids);

        $this->assertSame(100, $result['linked']);
        $this->assertSame([], $result['skipped']);

        // Same rows, now bound to the term — no row was recreated.
        $this->assertSame(0, Enrollment::withoutTenant()
            ->where('tenant_id', $tenant->id)->whereNull('academic_term_id')->count());
        $this->assertSame(100, Enrollment::withoutTenant()
            ->where('tenant_id', $tenant->id)->where('academic_term_id', $term->id)->count());
        sort($originalEnrollmentIds);
        $linkedIds = Enrollment::withoutTenant()
            ->where('tenant_id', $tenant->id)->where('academic_term_id', $term->id)
            ->pluck('id')->all();
        sort($linkedIds);
        $this->assertSame($originalEnrollmentIds, $linkedIds);

        // Ending the term frees exactly 100 seats and deletes nothing.
        $summary = app(AcademicTermService::class)->end($term, []);
        $this->assertSame(100, $summary['seats_freed']);
        $this->assertSame(0, $this->seats($tenant));
        $this->assertSame(100, Enrollment::withoutTenant()
            ->where('tenant_id', $tenant->id)->where('academic_term_id', $term->id)
            ->where('status', 'archived')->count());
    }

    public function test_linking_preserves_enrollment_identity_and_lesson_progress(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $course = $this->course($tenant);
        $lesson = $this->lesson($tenant, $course);
        $term = $this->term($tenant, 'T1');

        $student = $this->student($tenant);
        $enrollment = $this->enroll($course, $student);

        $progress = LessonProgress::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'enrollment_id' => $enrollment->id, 'lesson_id' => $lesson->id,
            'user_id' => $student->id, 'status' => 'completed',
            'watch_time_seconds' => 120, 'completed_at' => now(),
        ]);

        app(AcademicTermService::class)->linkEnrollments($term, [$student->id]);

        $this->assertSame($enrollment->id, $enrollment->fresh()->id);
        $this->assertSame($term->id, $enrollment->fresh()->academic_term_id);
        $this->assertDatabaseHas('lesson_progress', ['id' => $progress->id, 'enrollment_id' => $enrollment->id, 'status' => 'completed']);

        app(AcademicTermService::class)->end($term, []);

        $this->assertDatabaseHas('lesson_progress', [
            'id' => $progress->id, 'enrollment_id' => $enrollment->id, 'status' => 'completed',
        ]);
    }

    // ─────────────── (3) cross-tenant / role assignment guards ───────────────

    public function test_assignment_rejects_user_from_another_teachers_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantA);
        $this->attachSubscription($tenantB);

        $courseA = $this->course($tenantA, 'A');
        $termA = $this->term($tenantA, 'TA');

        // A student who belongs to a DIFFERENT teacher's tenant.
        $foreign = $this->student($tenantB);

        $result = app(AcademicTermService::class)->assignStudents($termA, $courseA->id, [$foreign->id]);

        $this->assertSame([], $result['created']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame($foreign->id, $result['failed'][0]['user_id']);

        $this->assertSame(0, Enrollment::withoutTenant()
            ->where('tenant_id', $tenantA->id)->where('user_id', $foreign->id)->count());
    }

    public function test_http_enrollment_store_rejects_user_from_another_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantA);
        $this->attachSubscription($tenantB);

        $teacherA = $this->teacher($tenantA);
        $courseA = $this->course($tenantA, 'A');
        $foreign = $this->student($tenantB);

        $this->withHeaders($this->authHeaders($teacherA, $tenantA))
            ->postJson('/api/v1/enrollments', ['course_id' => $courseA->id, 'user_id' => $foreign->id])
            ->assertForbidden();

        $this->assertSame(0, Enrollment::withoutTenant()
            ->where('tenant_id', $tenantA->id)->where('user_id', $foreign->id)->count());
    }

    public function test_assignment_rejects_staff_of_the_same_tenant(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $course = $this->course($tenant);
        $term = $this->term($tenant, 'T1');

        $staff = $this->teacher($tenant);

        $result = app(AcademicTermService::class)->assignStudents($term, $course->id, [$staff->id]);

        $this->assertSame([], $result['created']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame(0, Enrollment::withoutTenant()
            ->where('tenant_id', $tenant->id)->where('user_id', $staff->id)->count());
    }

    // ─────────────── (4) access revoked on expiry/archive, results kept ───────────────

    public function test_mobile_access_revoked_after_expiry_but_results_preserved(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $course = $this->course($tenant);
        $lesson = $this->lesson($tenant, $course);
        $student = $this->student($tenant);

        $enrollment = $this->enroll($course, $student, ['expires_at' => now()->addDay()]);

        // A historical result that must survive access loss.
        $section = Section::where('tenant_id', $tenant->id)->firstOrFail();
        $exam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'course_id' => $course->id, 'section_id' => $section->id,
            'title' => 'Quiz', 'duration_minutes' => 10, 'pass_mark_percent' => 50,
            'max_attempts' => 2, 'is_published' => true,
        ]);
        $attempt = ExamAttempt::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'exam_id' => $exam->id, 'user_id' => $student->id,
            'attempt_number' => 1, 'status' => 'submitted', 'score' => 8,
            'total_points' => 10, 'percentage' => 80, 'passed' => true,
            'started_at' => now()->subHour(), 'submitted_at' => now()->subMinutes(30),
        ]);

        // While effective: enrolled + lesson accessible.
        $this->withHeaders($this->authHeaders($student, $tenant))
            ->getJson('/api/v1/mobile/courses')
            ->assertOk()
            ->assertJsonPath('courses.0.is_enrolled', true);

        // Expire the enrollment.
        $enrollment->update(['expires_at' => now()->subMinute()]);

        $this->withHeaders($this->authHeaders($student, $tenant))
            ->getJson('/api/v1/mobile/courses')
            ->assertOk()
            ->assertJsonPath('courses.0.is_enrolled', false);

        $this->withHeaders($this->authHeaders($student, $tenant))
            ->getJson("/api/v1/mobile/lessons/{$lesson->id}")
            ->assertForbidden();

        // Historical results remain reachable.
        $this->withHeaders($this->authHeaders($student, $tenant))
            ->getJson('/api/v1/student/exam-attempts')
            ->assertOk()
            ->assertJsonFragment(['id' => $attempt->id, 'percentage' => 80.0]);

        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id, 'percentage' => 80]);
    }

    public function test_announcement_audience_excludes_expired_students(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $course = $this->course($tenant);
        $student = $this->student($tenant);

        $this->enroll($course, $student, ['expires_at' => now()->addDay()]);

        Announcement::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'title' => 'Course news', 'body' => 'Hello course', 'audience' => 'course',
            'course_id' => $course->id, 'status' => 'sent', 'published_at' => now(),
        ]);

        $this->withHeaders($this->authHeaders($student, $tenant))
            ->getJson('/api/v1/mobile/home')
            ->assertOk()
            ->assertJsonPath('announcements.0.title', 'Course news');

        Enrollment::withoutTenant()->where('tenant_id', $tenant->id)
            ->where('user_id', $student->id)->update(['expires_at' => now()->subMinute()]);

        $this->withHeaders($this->authHeaders($student, $tenant))
            ->getJson('/api/v1/mobile/home')
            ->assertOk()
            ->assertJsonCount(0, 'announcements');
    }

    public function test_archived_term_revokes_mobile_access(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $course = $this->course($tenant);
        $term = $this->term($tenant, 'T1');
        $student = $this->student($tenant);

        $this->enroll($course, $student, ['academic_term_id' => $term->id]);

        $this->withHeaders($this->authHeaders($student, $tenant))
            ->getJson('/api/v1/mobile/courses')
            ->assertJsonPath('courses.0.is_enrolled', true);

        app(AcademicTermService::class)->end($term, []);

        $this->withHeaders($this->authHeaders($student, $tenant))
            ->getJson('/api/v1/mobile/courses')
            ->assertJsonPath('courses.0.is_enrolled', false);
    }

    // ─────────────── (5) lifecycle: no direct archive / reactivation ───────────────

    public function test_direct_status_archived_change_is_blocked(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->teacher($tenant);
        $term = $this->term($tenant, 'T1');

        $headers = $this->authHeaders($teacher, $tenant);

        // Cannot create straight into `archived`.
        $this->withHeaders($headers)
            ->postJson('/api/v1/terms', ['name' => 'X', 'status' => 'archived'])
            ->assertStatus(422);

        // Cannot PUT a term into `archived`.
        $this->withHeaders($headers)
            ->putJson("/api/v1/terms/{$term->id}", ['status' => 'archived'])
            ->assertStatus(422);

        $this->assertSame(AcademicTerm::STATUS_ACTIVE, $term->fresh()->status);
    }

    public function test_archived_term_cannot_be_reactivated(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->teacher($tenant);
        $term = $this->term($tenant, 'T1');
        $term->update(['status' => AcademicTerm::STATUS_ARCHIVED]);

        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson("/api/v1/terms/{$term->id}/activate")
            ->assertStatus(422)
            ->assertJsonPath('code', 'term_archived');
    }

    public function test_deleting_a_term_with_enrollments_is_blocked(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->teacher($tenant);
        $course = $this->course($tenant);
        $term = $this->term($tenant, 'T1');
        $this->enroll($course, $this->student($tenant), ['academic_term_id' => $term->id]);

        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->deleteJson("/api/v1/terms/{$term->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'term_has_enrollments');

        $this->assertDatabaseHas('academic_terms', ['id' => $term->id]);
    }

    public function test_enrolling_into_an_archived_term_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $course = $this->course($tenant);
        $term = $this->term($tenant, 'T1', AcademicTerm::STATUS_ARCHIVED);

        $this->expectException(HttpResponseException::class);
        $this->enroll($course, $this->student($tenant), ['academic_term_id' => $term->id]);
    }

    public function test_next_term_must_differ_and_not_be_archived(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $course = $this->course($tenant);
        $term1 = $this->term($tenant, 'T1');
        $archived = $this->term($tenant, 'T0', AcademicTerm::STATUS_ARCHIVED);
        $student = $this->student($tenant);
        $this->enroll($course, $student, ['academic_term_id' => $term1->id]);

        $service = app(AcademicTermService::class);

        // Same term as the target.
        try {
            $service->end($term1, [$student->id], ['next_term_id' => $term1->id]);
            $this->fail('Expected same_term rejection.');
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
        }

        // Archived term as the promotion target.
        try {
            $service->end($term1, [$student->id], ['next_term_id' => $archived->id]);
            $this->fail('Expected term_closed rejection.');
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
        }

        // Nothing changed.
        $this->assertSame(AcademicTerm::STATUS_ACTIVE, $term1->fresh()->status);
    }

    // ─────────────── (6) preview recompute + stale token ───────────────

    public function test_end_preview_recomputes_on_continuing_change_and_stale_token_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->teacher($tenant);
        $course = $this->course($tenant);
        $term = $this->term($tenant, 'T1');

        $students = [];
        for ($i = 0; $i < 3; $i++) {
            $s = $this->student($tenant);
            $this->enroll($course, $s, ['academic_term_id' => $term->id]);
            $students[] = $s;
        }

        $headers = $this->authHeaders($teacher, $tenant);

        $first = $this->withHeaders($headers)
            ->getJson("/api/v1/terms/{$term->id}/end-preview")
            ->assertOk();
        $this->assertSame(3, $first->json('preview.seats_freed'));
        $token = $first->json('signature');

        $second = $this->withHeaders($headers)
            ->getJson("/api/v1/terms/{$term->id}/end-preview?continue[]={$students[0]->id}")
            ->assertOk();
        $this->assertSame(2, $second->json('preview.seats_freed'));
        $this->assertNotSame($token, $second->json('signature'));

        // Confirming with the stale token must fail and change nothing.
        $this->withHeaders($headers)
            ->postJson("/api/v1/terms/{$term->id}/end", [
                'continue' => [$students[0]->id],
                'preview_token' => $token,
                'next_term_id' => $this->term($tenant, 'T2', AcademicTerm::STATUS_UPCOMING)->id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_preview');

        $this->assertSame(AcademicTerm::STATUS_ACTIVE, $term->fresh()->status);
        $this->assertSame(3, $this->seats($tenant));
    }

    // ─────────────── (7)+(8) atomic + idempotent end ───────────────

    public function test_two_sequential_end_requests_are_consistent_and_create_one_next_term(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->teacher($tenant);
        $course = $this->course($tenant);
        $term = $this->term($tenant, 'T1');
        $this->enroll($course, $this->student($tenant), ['academic_term_id' => $term->id]);

        $headers = $this->authHeaders($teacher, $tenant);
        $payload = ['create_next' => ['name' => 'T2']];

        $first = $this->withHeaders($headers)
            ->postJson("/api/v1/terms/{$term->id}/end", $payload)
            ->assertOk()
            ->json('summary');

        $second = $this->withHeaders($headers)
            ->postJson("/api/v1/terms/{$term->id}/end", $payload)
            ->assertOk()
            ->json('summary');

        $this->assertSame($first['seats_freed'], $second['seats_freed']);
        $this->assertSame($first['next_term_id'], $second['next_term_id']);

        // Exactly two terms exist: the ended one + the one next term.
        $this->assertSame(2, AcademicTerm::withoutTenant()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, $this->seats($tenant));
    }

    public function test_end_retry_after_success_creates_no_new_term(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $course = $this->course($tenant);
        $term = $this->term($tenant, 'T1');
        $student = $this->student($tenant);
        $this->enroll($course, $student, ['academic_term_id' => $term->id]);

        $service = app(AcademicTermService::class);

        $first = $service->end($term, [$student->id], ['create' => ['name' => 'T2']]);
        $second = $service->end($term->fresh(), [$student->id], ['create' => ['name' => 'T2']]);
        $third = $service->end($term->fresh(), [$student->id], ['create' => ['name' => 'T3']]);

        $this->assertSame($first['next_term_id'], $second['next_term_id']);
        $this->assertSame($first['next_term_id'], $third['next_term_id']);
        $this->assertSame($first['seats_freed'], $third['seats_freed']);

        // No extra term was created on retry (T1 + T2 only).
        $this->assertSame(2, AcademicTerm::withoutTenant()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, Enrollment::withoutTenant()
            ->where('tenant_id', $tenant->id)->where('academic_term_id', $first['next_term_id'])
            ->where('status', 'active')->count());
    }

    public function test_mid_operation_failure_rolls_back_cleanly(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $course = $this->course($tenant);
        $term = $this->term($tenant, 'T1');

        $enrollments = [];
        for ($i = 0; $i < 3; $i++) {
            $enrollments[] = $this->enroll($course, $this->student($tenant), ['academic_term_id' => $term->id]);
        }

        // Explode while archiving the third row → the whole transaction must
        // roll back, leaving no partial state and no orphan next term.
        $armed = true;
        $targetId = $enrollments[2]->id;
        Enrollment::updating(function (Enrollment $e) use (&$armed, $targetId) {
            if ($armed && $e->id === $targetId) {
                throw new \RuntimeException('boom');
            }
        });

        try {
            app(AcademicTermService::class)->end($term, [], ['create' => ['name' => 'T2']]);
            $this->fail('Expected the injected failure to surface.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        } finally {
            $armed = false;
        }

        $this->assertSame(AcademicTerm::STATUS_ACTIVE, $term->fresh()->status);
        $this->assertSame(0, Enrollment::withoutTenant()
            ->where('tenant_id', $tenant->id)->where('academic_term_id', $term->id)
            ->where('status', 'archived')->count());
        $this->assertSame(3, $this->seats($tenant));
        $this->assertSame(1, AcademicTerm::withoutTenant()->where('tenant_id', $tenant->id)->count());
    }

    // ─────────────── (9) per-term exam budget + preserved results ───────────────

    public function test_renewal_grants_fresh_attempt_budget_without_mixing_results(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $course = $this->course($tenant);
        $lesson = $this->lesson($tenant, $course);
        $term1 = $this->term($tenant, 'T1');
        $term2 = $this->term($tenant, 'T2', AcademicTerm::STATUS_UPCOMING);
        $student = $this->student($tenant);

        $section = Section::where('tenant_id', $tenant->id)->firstOrFail();
        $exam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'course_id' => $course->id, 'section_id' => $section->id,
            'title' => 'Quiz', 'duration_minutes' => 10, 'pass_mark_percent' => 50,
            'max_attempts' => 1, 'is_published' => true,
        ]);

        $enrollment = $this->enroll($course, $student, ['academic_term_id' => $term1->id]);

        // Historical grade in term 1 that must never be mixed with term 2.
        $oldAttempt = ExamAttempt::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'exam_id' => $exam->id, 'user_id' => $student->id,
            'academic_term_id' => $term1->id, 'attempt_number' => 1,
            'status' => 'submitted', 'score' => 9, 'total_points' => 10,
            'percentage' => 90, 'passed' => true,
            'started_at' => now()->subHours(2), 'submitted_at' => now()->subHour(),
        ]);

        LessonProgress::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'enrollment_id' => $enrollment->id, 'lesson_id' => $lesson->id,
            'user_id' => $student->id, 'status' => 'completed', 'completed_at' => now(),
        ]);

        $headers = $this->authHeaders($student, $tenant);

        // Budget exhausted inside term 1.
        $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempt")->assertForbidden();

        // Renew into term 2 (same course) carrying the student forward.
        app(AcademicTermService::class)->end($term1, [$student->id], ['next_term_id' => $term2->id]);

        // A fresh budget applies in term 2.
        $this->withHeaders($headers)
            ->postJson("/api/v1/exams/{$exam->id}/attempt")
            ->assertOk()
            ->assertJsonPath('attempt.academic_term_id', $term2->id);

        // Old results + progress are untouched and remain in term 1's scope.
        $old = ExamAttempt::withoutTenant()->find($oldAttempt->id);
        $this->assertNotNull($old);
        $this->assertSame('90.00', (string) $old->percentage);
        $this->assertSame($term1->id, $old->academic_term_id);
        $this->assertDatabaseHas('lesson_progress', ['user_id' => $student->id, 'lesson_id' => $lesson->id, 'status' => 'completed']);
    }

    // ─────────────── (10)+(11) seat limits across courses ───────────────

    public function test_two_students_two_courses_share_one_tenant_limit(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $this->setStudentsLimit($tenant, 2);
        $courseA = $this->course($tenant, 'A');
        $courseB = $this->course($tenant, 'B');

        $s1 = $this->student($tenant);
        $s2 = $this->student($tenant);

        // Different courses, same tenant: the seat mutex keeps the count exact.
        $this->enroll($courseA, $s1);
        $this->enroll($courseB, $s2);
        $this->assertSame(2, $this->seats($tenant));

        // Already-counted students may take another course...
        $this->enroll($courseB, $s1);
        $this->assertSame(2, $this->seats($tenant));

        // ...but a brand-new student is blocked.
        $this->expectException(QuotaExceededException::class);
        $this->enroll($courseA, $this->student($tenant));
    }

    // ─────────────── (12) history never deleted / archive vs hard delete ───────────────

    public function test_archive_keeps_progress_certificate_and_attempt_and_hard_delete_needs_super_admin(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->teacher($tenant);
        $course = $this->course($tenant);
        $lesson = $this->lesson($tenant, $course);
        $student = $this->student($tenant);
        $enrollment = $this->enroll($course, $student);

        $progress = LessonProgress::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'enrollment_id' => $enrollment->id, 'lesson_id' => $lesson->id,
            'user_id' => $student->id, 'status' => 'completed', 'completed_at' => now(),
        ]);
        $certificate = StudentCertificate::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'user_id' => $student->id, 'course_id' => $course->id,
            'enrollment_id' => $enrollment->id, 'certificate_number' => 'CERT-'.strtoupper(UuidV7::generate()),
            'issued_at' => now(), 'status' => 'issued',
        ]);

        $headers = $this->authHeaders($teacher, $tenant);

        // Ordinary removal = safe archive. Nothing is deleted.
        $this->withHeaders($headers)
            ->deleteJson("/api/v1/enrollments/{$enrollment->id}")
            ->assertOk()
            ->assertJsonPath('code', 'archived');

        $this->assertSame('archived', $enrollment->fresh()->status);
        $this->assertDatabaseHas('lesson_progress', ['id' => $progress->id]);
        $this->assertDatabaseHas('student_certificates', ['id' => $certificate->id]);
        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id]);

        // Hard delete is an exceptional admin action — blocked for teachers.
        $this->withHeaders($headers)
            ->deleteJson("/api/v1/enrollments/{$enrollment->id}?force=1")
            ->assertForbidden()
            ->assertJsonPath('code', 'force_delete_forbidden');

        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->id]);
    }
}
