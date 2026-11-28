<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Booking;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Section;
use App\Models\User;
use Symfony\Component\Uid\UuidV7;

class TeacherDashboardWorkItemsTest extends TenantTestCase
{
    public function test_teacher_work_items_are_real_and_scoped_to_their_tenant(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $course = Course::factory()->create(['tenant_id' => $tenant->id]);
        $assignment = Assignment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'title' => 'Worksheet',
            'is_published' => true,
        ]);

        foreach (['submitted', 'late', 'graded'] as $status) {
            $submissionStudent = $this->makeUser();
            $this->attachToTenant($submissionStudent, $tenant, 'student');
            AssignmentSubmission::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenant->id,
                'assignment_id' => $assignment->id,
                'user_id' => $submissionStudent->id,
                'status' => $status,
                'submitted_at' => now(),
            ]);
        }

        $section = Section::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'title' => 'Unit 1',
            'sort_order' => 0,
        ]);
        $exam = Exam::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'section_id' => $section->id,
            'title' => 'Quiz',
            'duration_minutes' => 30,
            'pass_mark_percent' => 50,
            'max_attempts' => 3,
            'is_published' => true,
            'metadata' => [],
        ]);
        ExamAttempt::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'exam_id' => $exam->id,
            'user_id' => $student->id,
            'attempt_number' => 1,
            'status' => 'submitted',
            'needs_review' => true,
            'started_at' => now()->subMinutes(15),
            'submitted_at' => now(),
        ]);
        ExamAttempt::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'exam_id' => $exam->id,
            'user_id' => $student->id,
            'attempt_number' => 2,
            'status' => 'submitted',
            'needs_review' => false,
            'started_at' => now()->subMinutes(10),
            'submitted_at' => now(),
        ]);

        $otherTenant = $this->makeTenant();
        $otherTeacher = $this->makeUser();
        $this->attachToTenant($otherTeacher, $otherTenant, 'teacher');
        $otherStudent = $this->makeUser();
        $this->attachToTenant($otherStudent, $otherTenant, 'student');
        $otherCourse = Course::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherAssignment = Assignment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $otherTenant->id,
            'course_id' => $otherCourse->id,
            'title' => 'Other worksheet',
            'is_published' => true,
        ]);
        AssignmentSubmission::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $otherTenant->id,
            'assignment_id' => $otherAssignment->id,
            'user_id' => $otherStudent->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/dashboard', $this->authHeaders($teacher, $tenant));

        $response->assertOk()
            ->assertJsonPath('pending_assignment_submissions', 2)
            ->assertJsonPath('pending_exam_reviews', 1)
            ->assertJsonPath('unread_admin_notifications', 0);
    }

    public function test_owner_sees_team_booking_requests_but_teacher_sees_only_their_own(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser();
        $this->attachToTenant($owner, $tenant, 'owner');
        $this->attachSubscription($tenant);

        $teacherA = $this->makeUser();
        $this->attachToTenant($teacherA, $tenant, 'teacher');
        $teacherB = $this->makeUser();
        $this->attachToTenant($teacherB, $tenant, 'teacher');
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        foreach ([$teacherA, $teacherB] as $index => $teacher) {
            Booking::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenant->id,
                'teacher_id' => $teacher->id,
                'student_id' => $student->id,
                'kind' => Booking::KIND_APPOINTMENT,
                'starts_at' => now()->addDays($index + 1),
                'ends_at' => now()->addDays($index + 1)->addHour(),
                'status' => Booking::STATUS_PENDING,
                'price' => 0,
                'currency' => 'JOD',
                'metadata' => [],
            ]);
        }

        $ownerResponse = $this->getJson('/api/v1/dashboard', $this->authHeaders($owner, $tenant));
        $ownerResponse->assertOk()->assertJsonPath('pending_bookings', 2);

        $teacherResponse = $this->getJson('/api/v1/dashboard', $this->authHeaders($teacherA, $tenant));
        $teacherResponse->assertOk()->assertJsonPath('pending_bookings', 1);
    }

    public function test_student_dashboard_does_not_expose_teacher_work_items(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $response = $this->getJson('/api/v1/dashboard', $this->authHeaders($student, $tenant));

        $response->assertOk()
            ->assertJsonMissingPath('pending_assignment_submissions')
            ->assertJsonMissingPath('pending_exam_reviews')
            ->assertJsonMissingPath('pending_bookings');
    }
}
