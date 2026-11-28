<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseGroup;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Lesson;
use App\Models\Section;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Course groups: in-course student groups. A lesson/exam shared to a group is
 * hidden from non-members, membership never creates an enrolment (so a student
 * in several groups still holds one seat), and results are per group vs all.
 */
class CourseGroupTest extends TenantTestCase
{
    private Tenant $tenant;
    private User $teacher;
    private Course $course;
    private Section $section;
    private Lesson $sharedLesson;
    private Lesson $openLesson;
    private Exam $sharedExam;
    private Exam $openExam;
    private User $member;
    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
        $this->attachSubscription($this->tenant);
        $this->teacher = $this->makeUser();
        $this->attachToTenant($this->teacher, $this->tenant, 'teacher');

        $this->course = Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'title' => 'Grade 10', 'slug' => 'g10-'.Str::random(6), 'is_published' => true,
        ]);
        $this->section = Section::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $this->course->id, 'title' => 'Unit 1', 'is_published' => true, 'sort_order' => 0,
        ]);
        $this->sharedLesson = Lesson::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id, 'section_id' => $this->section->id,
            'title' => 'Group A only', 'lesson_type' => 'text', 'is_published' => true, 'sort_order' => 0,
        ]);
        $this->openLesson = Lesson::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id, 'section_id' => $this->section->id,
            'title' => 'Everyone', 'lesson_type' => 'text', 'is_published' => true, 'sort_order' => 1,
        ]);
        $this->sharedExam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id, 'course_id' => $this->course->id,
            'section_id' => $this->section->id, 'title' => 'Group A quiz', 'pass_mark_percent' => 50,
            'max_attempts' => 3, 'is_published' => true, 'duration_minutes' => 20, 'metadata' => [],
        ]);
        $this->openExam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id, 'course_id' => $this->course->id,
            'section_id' => $this->section->id, 'title' => 'Open quiz', 'pass_mark_percent' => 50,
            'max_attempts' => 3, 'is_published' => true, 'duration_minutes' => 20, 'metadata' => [],
        ]);

        $this->member = $this->makeUser(['name' => 'Member']);
        $this->attachToTenant($this->member, $this->tenant, 'student');
        $this->outsider = $this->makeUser(['name' => 'Outsider']);
        $this->attachToTenant($this->outsider, $this->tenant, 'student');

        foreach ([$this->member, $this->outsider] as $student) {
            Enrollment::create([
                'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
                'course_id' => $this->course->id, 'user_id' => $student->id,
                'status' => 'active', 'enrolled_at' => now(),
            ]);
        }
    }

    private function makeGroup(): CourseGroup
    {
        return CourseGroup::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $this->course->id, 'name' => 'Grade 10 — A', 'sort_order' => 0, 'is_active' => true,
        ]);
    }

    /** @test */
    public function test_teacher_can_create_a_group_and_assign_enrolled_members(): void
    {
        $res = $this->withHeaders($this->authHeaders($this->teacher, $this->tenant))
            ->postJson("/api/v1/courses/{$this->course->id}/groups", ['name' => 'Grade 10 — A']);
        $res->assertStatus(201);
        $groupId = $res->json('group.id');

        $this->withHeaders($this->authHeaders($this->teacher, $this->tenant))
            ->putJson("/api/v1/course-groups/{$groupId}/members", ['user_ids' => [$this->member->id]])
            ->assertOk();

        $this->assertSame([$this->member->id], CourseGroup::find($groupId)->members()->pluck('users.id')->all());
    }

    /** @test */
    public function test_non_enrolled_students_cannot_be_assigned_to_a_group(): void
    {
        $group = $this->makeGroup();
        $stranger = $this->makeUser();
        $this->attachToTenant($stranger, $this->tenant, 'student');

        $this->withHeaders($this->authHeaders($this->teacher, $this->tenant))
            ->putJson("/api/v1/course-groups/{$group->id}/members", ['user_ids' => [$this->member->id, $stranger->id]])
            ->assertOk();

        $ids = CourseGroup::find($group->id)->members()->pluck('users.id')->all();
        $this->assertContains($this->member->id, $ids);
        $this->assertNotContains($stranger->id, $ids, 'a non-enrolled user must never be assigned');
    }

    /** @test */
    public function test_lesson_shared_to_a_group_is_hidden_from_non_members(): void
    {
        $group = $this->makeGroup();
        $group->lessons()->sync([$this->sharedLesson->id]);
        $group->members()->sync([$this->member->id]);

        $memberSections = $this->withHeaders($this->authHeaders($this->member, $this->tenant))
            ->getJson("/api/v1/public/courses/{$this->course->id}/sections")->json('sections');
        $memberLessons = collect($memberSections)->flatMap(fn ($s) => $s['lessons'])->pluck('id')->all();
        $this->assertContains($this->sharedLesson->id, $memberLessons);
        $this->assertContains($this->openLesson->id, $memberLessons);

        $outsiderSections = $this->withHeaders($this->authHeaders($this->outsider, $this->tenant))
            ->getJson("/api/v1/public/courses/{$this->course->id}/sections")->json('sections');
        $outsiderLessons = collect($outsiderSections)->flatMap(fn ($s) => $s['lessons'])->pluck('id')->all();
        $this->assertNotContains($this->sharedLesson->id, $outsiderLessons, 'restricted lesson must be hidden');
        $this->assertContains($this->openLesson->id, $outsiderLessons, 'unrestricted lesson stays visible');
    }

    /** @test */
    public function test_group_member_can_open_a_shared_lesson_but_outsider_gets_403(): void
    {
        $group = $this->makeGroup();
        $group->lessons()->sync([$this->sharedLesson->id]);
        $group->members()->sync([$this->member->id]);

        $this->withHeaders($this->authHeaders($this->member, $this->tenant))
            ->getJson("/api/v1/public/courses/{$this->course->id}/lessons/{$this->sharedLesson->id}")
            ->assertOk();

        $this->withHeaders($this->authHeaders($this->outsider, $this->tenant))
            ->getJson("/api/v1/public/courses/{$this->course->id}/lessons/{$this->sharedLesson->id}")
            ->assertStatus(403);
    }

    /** @test */
    public function test_exam_shared_to_a_group_is_hidden_and_not_attemptable_by_outsiders(): void
    {
        $group = $this->makeGroup();
        $group->exams()->sync([$this->sharedExam->id]);
        $group->members()->sync([$this->member->id]);

        $memberExams = $this->withHeaders($this->authHeaders($this->member, $this->tenant))
            ->getJson("/api/v1/exams?course_id={$this->course->id}")->json('exams');
        $this->assertContains($this->sharedExam->id, array_column($memberExams, 'id'));

        $outsiderExams = $this->withHeaders($this->authHeaders($this->outsider, $this->tenant))
            ->getJson("/api/v1/exams?course_id={$this->course->id}")->json('exams');
        $this->assertNotContains($this->sharedExam->id, array_column($outsiderExams, 'id'), 'group exam must be hidden');
        $this->assertContains($this->openExam->id, array_column($outsiderExams, 'id'));

        $this->withHeaders($this->authHeaders($this->outsider, $this->tenant))
            ->postJson("/api/v1/exams/{$this->sharedExam->id}/attempt")
            ->assertStatus(403);
    }

    /** @test */
    public function test_multi_group_membership_does_not_add_a_seat(): void
    {
        $seatService = app(\App\Services\StudentSeatService::class);
        $before = $seatService->count($this->tenant->id);

        $g1 = $this->makeGroup();
        $g2 = CourseGroup::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id, 'course_id' => $this->course->id,
            'name' => 'Grade 10 — B', 'sort_order' => 1, 'is_active' => true,
        ]);
        $g1->members()->sync([$this->member->id]);
        $g2->members()->sync([$this->member->id]);

        $this->assertSame($before, $seatService->count($this->tenant->id), 'group membership must never consume a seat');
    }

    /** @test */
    public function test_moving_a_student_between_groups_keeps_their_grades(): void
    {
        $groupA = $this->makeGroup();
        $groupB = CourseGroup::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id, 'course_id' => $this->course->id,
            'name' => 'Grade 10 — B', 'sort_order' => 1, 'is_active' => true,
        ]);
        $groupA->members()->sync([$this->member->id]);

        $attempt = ExamAttempt::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'exam_id' => $this->openExam->id, 'user_id' => $this->member->id,
            'attempt_number' => 1, 'status' => 'submitted',
            'score' => 8, 'total_points' => 10, 'percentage' => 80, 'passed' => true,
            'started_at' => now()->subMinutes(5), 'submitted_at' => now(),
        ]);

        // Move: remove from A, add to B.
        $groupA->members()->sync([]);
        $groupB->members()->sync([$this->member->id]);

        $this->assertDatabaseHas('exam_attempts', ['id' => $attempt->id, 'percentage' => 80, 'user_id' => $this->member->id]);
    }

    /** @test */
    public function test_results_are_reported_per_group_and_for_all(): void
    {
        $group = $this->makeGroup();
        $group->members()->sync([$this->member->id]);

        ExamAttempt::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id, 'exam_id' => $this->openExam->id,
            'user_id' => $this->member->id, 'attempt_number' => 1, 'status' => 'submitted',
            'score' => 9, 'total_points' => 10, 'percentage' => 90, 'passed' => true,
            'started_at' => now()->subMinutes(5), 'submitted_at' => now(),
        ]);
        ExamAttempt::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id, 'exam_id' => $this->openExam->id,
            'user_id' => $this->outsider->id, 'attempt_number' => 1, 'status' => 'submitted',
            'score' => 5, 'total_points' => 10, 'percentage' => 50, 'passed' => true,
            'started_at' => now()->subMinutes(5), 'submitted_at' => now(),
        ]);

        $res = $this->withHeaders($this->authHeaders($this->teacher, $this->tenant))
            ->getJson("/api/v1/course-groups/{$group->id}/results");

        $res->assertOk();
        $this->assertSame(1, $res->json('group.submitted_attempts'));
        $this->assertEquals(90.0, $res->json('group.average_percentage'));
        $this->assertSame(2, $res->json('all.submitted_attempts'));
        $this->assertEquals(70.0, $res->json('all.average_percentage'));
    }

    /** @test */
    public function test_another_tenants_teacher_cannot_manage_this_courses_groups(): void
    {
        $group = $this->makeGroup();
        $otherTenant = $this->makeTenant();
        $this->attachSubscription($otherTenant);
        $otherTeacher = $this->makeUser();
        $this->attachToTenant($otherTeacher, $otherTenant, 'teacher');

        $this->withHeaders($this->authHeaders($otherTeacher, $otherTenant))
            ->postJson("/api/v1/courses/{$this->course->id}/groups", ['name' => 'Hijack'])
            ->assertStatus(404);
    }
}
