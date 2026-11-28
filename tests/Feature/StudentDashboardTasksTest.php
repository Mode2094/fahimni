<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseGroup;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Symfony\Component\Uid\UuidV7;

class StudentDashboardTasksTest extends TenantTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_task_window_follows_the_tenants_local_day_not_utc(): void
    {
        // 22:00 UTC == 01:00 the NEXT day in Asia/Riyadh (UTC+3). A task due
        // ~8 days out is in "this week" for the tenant's local day, but outside
        // a naive now()+7d (UTC) window.
        Carbon::setTestNow('2026-01-01 22:00:00');

        $tenant = $this->makeTenant(); // timezone Asia/Riyadh
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->attachSubscription($tenant);

        $course = Course::factory()->create(['tenant_id' => $tenant->id]);
        Enrollment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
            'progress_percent' => 0,
        ]);

        // 2026-01-09 12:00 UTC is beyond now()+7d (2026-01-08 22:00 UTC) but
        // before the tenant-local end-of-window (2026-01-09 20:59 UTC).
        $assignment = $this->makeAssignment($tenant, $course, 'Late-week task', Carbon::parse('2026-01-09 12:00:00'));

        $response = $this->getJson('/api/v1/dashboard', $this->authHeaders($student, $tenant));

        $response->assertOk()
            ->assertJsonCount(1, 'learning_tasks.due_assignments')
            ->assertJsonFragment(['id' => $assignment->id]);
    }

    public function test_student_dashboard_lists_only_due_work_from_effective_enrollments(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->attachSubscription($tenant);

        $course = Course::factory()->create(['tenant_id' => $tenant->id]);
        Enrollment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
            'progress_percent' => 25,
        ]);

        $due = $this->makeAssignment($tenant, $course, 'Due soon', now()->addDays(3));
        $overdueLateAllowed = $this->makeAssignment($tenant, $course, 'Late work', now()->subDay(), true);
        $submitted = $this->makeAssignment($tenant, $course, 'Already submitted', now()->addDay());
        AssignmentSubmission::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'assignment_id' => $submitted->id,
            'user_id' => $student->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        $this->makeAssignment($tenant, $course, 'No due date', null);
        $this->makeAssignment($tenant, $course, 'Too far away', now()->addDays(9));
        $this->makeAssignment($tenant, $course, 'Draft assignment', now()->addDay(), false, false);

        $upcomingExam = $this->makeExam($tenant, $course, 'Exam this week', now()->addDays(5));
        $this->makeExam($tenant, $course, 'Exam next month', now()->addDays(20));
        $this->makeExam($tenant, $course, 'Exam draft', now()->addDays(2), false);
        $restrictedExam = $this->makeExam($tenant, $course, 'Restricted exam', now()->addDays(2));
        $group = CourseGroup::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'name' => 'Group A',
            'sort_order' => 0,
            'is_active' => true,
        ]);
        $restrictedExam->groups()->sync([$group->id]);

        $response = $this->getJson('/api/v1/dashboard', $this->authHeaders($student, $tenant));

        $response->assertOk()
            ->assertJsonCount(2, 'learning_tasks.due_assignments')
            ->assertJsonCount(1, 'learning_tasks.upcoming_exams')
            ->assertJsonFragment(['id' => $due->id])
            ->assertJsonFragment(['id' => $overdueLateAllowed->id])
            ->assertJsonFragment(['id' => $upcomingExam->id])
            ->assertJsonMissing(['id' => $submitted->id])
            ->assertJsonMissing(['id' => $restrictedExam->id])
            ->assertJsonPath('learning_tasks.due_assignments.0.course_title', $course->title);
    }

    public function test_student_dashboard_does_not_list_work_for_expired_or_foreign_enrollments(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->attachSubscription($tenant);

        $expiredCourse = Course::factory()->create(['tenant_id' => $tenant->id]);
        Enrollment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $expiredCourse->id,
            'user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now()->subMonth(),
            'expires_at' => now()->subDay(),
            'progress_percent' => 0,
        ]);
        $expiredAssignment = $this->makeAssignment($tenant, $expiredCourse, 'Expired enrollment work', now()->addDay());
        $this->makeExam($tenant, $expiredCourse, 'Expired enrollment exam', now()->addDay());

        $otherTenant = $this->makeTenant();
        $otherCourse = Course::factory()->create(['tenant_id' => $otherTenant->id]);
        $this->makeAssignment($otherTenant, $otherCourse, 'Foreign work', now()->addDay());
        $this->makeExam($otherTenant, $otherCourse, 'Foreign exam', now()->addDay());

        $response = $this->getJson('/api/v1/dashboard', $this->authHeaders($student, $tenant));

        $response->assertOk()
            ->assertJsonCount(0, 'learning_tasks.due_assignments')
            ->assertJsonCount(0, 'learning_tasks.upcoming_exams')
            ->assertJsonMissing(['id' => $expiredAssignment->id]);
    }

    private function makeAssignment(
        Tenant $tenant,
        Course $course,
        string $title,
        $dueAt,
        bool $allowLate = false,
        bool $published = true,
    ): Assignment {
        return Assignment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'title' => $title,
            'points' => 10,
            'due_at' => $dueAt,
            'allow_late' => $allowLate,
            'is_published' => $published,
            'sort_order' => 0,
            'metadata' => [],
        ]);
    }

    private function makeExam(Tenant $tenant, Course $course, string $title, $endsAt, bool $published = true): Exam
    {
        return Exam::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'title' => $title,
            'duration_minutes' => 30,
            'pass_mark_percent' => 50,
            'max_attempts' => 3,
            'is_published' => $published,
            'starts_at' => now()->subHour(),
            'ends_at' => $endsAt,
            'metadata' => [],
        ]);
    }
}
