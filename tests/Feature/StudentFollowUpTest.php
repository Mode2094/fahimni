<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\CourseGroup;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Section;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * متابعة الطلاب — تحليل قواعدي من سجلات حقيقية، بلا فبركة:
 *   • الطالب النشط لا يُعلَّم؛ المتغيّب يُعلَّم بعدد أيام حقيقي.
 *   • غياب السجل ليس غياباً: طالب بلا أي إشارة يُصنّف «جديد/بلا بيانات».
 *   • الواجبات/الاختبارات المتأخرة تُحتسب فقط عند وجود موعد منتهٍ وبلا تسليم،
 *     ومع احترام تقييد المجموعات.
 *   • العزل بين المعلمين والصلاحيات.
 */
class StudentFollowUpTest extends TenantTestCase
{
    private Tenant $tenant;

    private User $teacher;

    private Course $course;

    private Section $section;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant();
        $this->attachSubscription($this->tenant);

        $this->teacher = $this->makeUser(['name' => 'Teacher A']);
        $this->attachToTenant($this->teacher, $this->tenant, 'teacher');

        $this->course = Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'title' => 'Algebra', 'slug' => 'algebra-'.Str::random(6), 'is_published' => true,
        ]);
        $this->section = Section::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $this->course->id, 'title' => 'Unit 1', 'is_published' => true, 'sort_order' => 0,
        ]);
        $this->lesson = Lesson::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id, 'section_id' => $this->section->id,
            'title' => 'Intro', 'lesson_type' => 'text', 'is_published' => true, 'sort_order' => 0,
        ]);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function student(string $name, int $enrolledDaysAgo = 10, float $progress = 0): array
    {
        $user = $this->makeUser(['name' => $name]);
        $this->attachToTenant($user, $this->tenant, 'student');

        $enrollment = Enrollment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $this->course->id, 'user_id' => $user->id,
            'status' => 'active', 'progress_percent' => $progress,
            'enrolled_at' => now()->subDays($enrolledDaysAgo),
        ]);

        return [$user, $enrollment];
    }

    private function logProgress(Enrollment $enrollment, int $daysAgo, bool $completed = false): LessonProgress
    {
        $progress = LessonProgress::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'enrollment_id' => $enrollment->id, 'user_id' => $enrollment->user_id,
            'lesson_id' => $this->lesson->id, 'status' => $completed ? 'completed' : 'in_progress',
            'watch_time_seconds' => $completed ? 600 : 120,
            'completed_at' => $completed ? now()->subDays($daysAgo) : null,
        ]);

        DB::table('lesson_progress')->where('id', $progress->id)
            ->update(['updated_at' => now()->subDays($daysAgo)]);

        return $progress;
    }

    private function report(array $query = [])
    {
        $url = '/api/v1/teacher/follow-up'.($query ? '?'.http_build_query($query) : '');

        return $this->withHeaders($this->authHeaders($this->teacher, $this->tenant))->getJson($url);
    }

    private function row($res, string $userId): ?array
    {
        foreach ($res->json('data') as $row) {
            if ($row['id'] === $userId) {
                return $row;
            }
        }

        return null;
    }

    private function codes(?array $row): array
    {
        return $row ? array_column($row['reasons'], 'code') : [];
    }

    // ── tests ───────────────────────────────────────────────────────────

    /** @test */
    public function test_students_cannot_read_the_follow_up_report(): void
    {
        [$user] = $this->student('Curious');

        $this->withHeaders($this->authHeaders($user, $this->tenant))
            ->getJson('/api/v1/teacher/follow-up')
            ->assertStatus(403);
    }

    /** @test */
    public function test_teacher_is_isolated_to_their_own_tenant(): void
    {
        $this->student('Mine');

        // مِلك معلم آخر: مستأجر مختلف بطالب مختلف.
        $otherTenant = $this->makeTenant();
        $this->attachSubscription($otherTenant);
        $course = Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $otherTenant->id,
            'title' => 'Other', 'slug' => 'other-'.Str::random(6), 'is_published' => true,
        ]);
        $outsider = $this->makeUser(['name' => 'Not Mine']);
        $this->attachToTenant($outsider, $otherTenant, 'student');
        Enrollment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $otherTenant->id,
            'course_id' => $course->id, 'user_id' => $outsider->id,
            'status' => 'active', 'enrolled_at' => now()->subDays(30),
        ]);

        $res = $this->report()->assertOk();

        $this->assertSame(1, $res->json('meta.counts.total'));
        $this->assertNull($this->row($res, $outsider->id), 'a foreign student must never appear');
    }

    /** @test */
    public function test_active_student_is_not_flagged(): void
    {
        [$user, $enrollment] = $this->student('Active', 10, 60);
        $user->update(['last_login_at' => now()->subDay()]);
        $this->logProgress($enrollment, 1, true);

        $row = $this->row($this->report(), $user->id);

        $this->assertSame('active', $row['status']);
        $this->assertFalse($row['at_risk']);
        $this->assertSame([], $row['reasons']);
        $this->assertSame(1, $row['lessons_completed']);
    }

    /** @test */
    public function test_inactive_student_is_flagged_with_real_days(): void
    {
        [$user, $enrollment] = $this->student('Inactive', 40, 60);
        $user->update(['last_login_at' => now()->subDays(21)]);
        $this->logProgress($enrollment, 21, true);

        $row = $this->row($this->report(), $user->id);

        $this->assertSame('inactive', $row['status']);
        $this->assertTrue($row['at_risk']);
        $this->assertContains('inactive', $this->codes($row));
        $this->assertSame(21, $row['days_inactive']);
    }

    /** @test */
    public function test_newly_enrolled_student_without_activity_is_new_not_inactive(): void
    {
        [$user] = $this->student('Fresh', 1, 0);

        $row = $this->row($this->report(), $user->id);

        $this->assertSame('new', $row['status']);
        $this->assertFalse($row['at_risk']);
        $this->assertNull($row['days_inactive'], 'no activity record must not fabricate days');
        $this->assertFalse($row['data_available']);
        $this->assertContains('no_activity_record', $this->codes($row));
    }

    /** @test */
    public function test_long_enrolled_student_without_any_activity_is_flagged_as_no_data(): void
    {
        [$user] = $this->student('Ghost', 45, 0);

        $row = $this->row($this->report(), $user->id);

        $this->assertSame('no_activity_data', $row['status']);
        $this->assertTrue($row['at_risk']);
        $this->assertNull($row['days_inactive']);
        $this->assertContains('never_signed_in', $this->codes($row));
    }

    /** @test */
    public function test_low_progress_student_is_flagged(): void
    {
        [$user, $enrollment] = $this->student('Struggling', 30, 8);
        $this->logProgress($enrollment, 1);

        $row = $this->row($this->report(), $user->id);

        $this->assertSame('low_progress', $row['status']);
        $this->assertContains('low_progress', $this->codes($row));
        $this->assertSame(8.0, (float) $row['progress_percent']);
    }

    /** @test */
    public function test_brand_new_low_progress_is_not_flagged_before_grace_period(): void
    {
        // ملتحق للتوّ وتقدّمه 0% — لا يُعلَّم (مهلة دنيا 3 أيام).
        [$user] = $this->student('JustJoined', 1, 0);

        $row = $this->row($this->report(), $user->id);

        $this->assertNotContains('low_progress', $this->codes($row));
    }

    /** @test */
    public function test_overdue_assignment_and_exam_are_flagged(): void
    {
        [$user, $enrollment] = $this->student('Overdue', 30, 60);
        $this->logProgress($enrollment, 1);

        Assignment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $this->course->id, 'title' => 'HW1', 'points' => 10,
            'due_at' => now()->subDays(3), 'is_published' => true, 'sort_order' => 0,
        ]);
        // اختبار انتهى ولا محاولة له.
        Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $this->course->id, 'section_id' => $this->section->id,
            'title' => 'Closed quiz', 'pass_mark_percent' => 50, 'max_attempts' => 3,
            'is_published' => true, 'ends_at' => now()->subDays(2), 'metadata' => [],
        ]);

        $row = $this->row($this->report(), $user->id);

        $this->assertSame('overdue', $row['status']);
        $this->assertSame(1, $row['overdue_assignments']);
        $this->assertSame(1, $row['overdue_exams']);
        $this->assertContains('overdue_assignments', $this->codes($row));
        $this->assertContains('overdue_exams', $this->codes($row));
    }

    /** @test */
    public function test_submitted_exam_and_assignment_are_not_overdue(): void
    {
        [$user, $enrollment] = $this->student('OnTime', 30, 60);
        $this->logProgress($enrollment, 1);

        $assignment = Assignment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $this->course->id, 'title' => 'HW1', 'points' => 10,
            'due_at' => now()->subDays(3), 'is_published' => true, 'sort_order' => 0,
        ]);
        AssignmentSubmission::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'assignment_id' => $assignment->id, 'user_id' => $user->id,
            'submitted_at' => now()->subDays(4), 'status' => 'submitted',
        ]);
        $exam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $this->course->id, 'section_id' => $this->section->id,
            'title' => 'Closed quiz', 'pass_mark_percent' => 50, 'max_attempts' => 3,
            'is_published' => true, 'ends_at' => now()->subDays(2), 'metadata' => [],
        ]);
        ExamAttempt::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'exam_id' => $exam->id, 'user_id' => $user->id, 'attempt_number' => 1,
            'status' => 'submitted', 'score' => 8, 'total_points' => 10, 'percentage' => 80,
            'passed' => true, 'started_at' => now()->subDays(3), 'submitted_at' => now()->subDays(3),
        ]);

        $row = $this->row($this->report(), $user->id);

        $this->assertSame(0, $row['overdue_assignments']);
        $this->assertSame(0, $row['overdue_exams']);
        $this->assertNotContains('overdue_assignments', $this->codes($row));
        $this->assertNotContains('overdue_exams', $this->codes($row));
    }

    /** @test */
    public function test_group_restricted_exam_is_not_overdue_for_non_members(): void
    {
        [$outsider, $outsiderEnrollment] = $this->student('Outsider', 30, 60);
        $this->logProgress($outsiderEnrollment, 1);

        $exam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $this->course->id, 'section_id' => $this->section->id,
            'title' => 'Group A only', 'pass_mark_percent' => 50, 'max_attempts' => 3,
            'is_published' => true, 'ends_at' => now()->subDays(2), 'metadata' => [],
        ]);
        $group = CourseGroup::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $this->course->id, 'name' => 'A', 'sort_order' => 0, 'is_active' => true,
        ]);
        $group->exams()->sync([$exam->id]);

        $row = $this->row($this->report(), $outsider->id);

        $this->assertSame(0, $row['overdue_exams'], 'a group-only exam must not count against non-members');
    }

    /** @test */
    public function test_group_member_sees_the_restricted_exam_as_overdue(): void
    {
        [$member, $memberEnrollment] = $this->student('Member', 30, 60);
        $this->logProgress($memberEnrollment, 1);

        $exam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $this->course->id, 'section_id' => $this->section->id,
            'title' => 'Group A only', 'pass_mark_percent' => 50, 'max_attempts' => 3,
            'is_published' => true, 'ends_at' => now()->subDays(2), 'metadata' => [],
        ]);
        $group = CourseGroup::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $this->course->id, 'name' => 'A', 'sort_order' => 0, 'is_active' => true,
        ]);
        $group->exams()->sync([$exam->id]);
        $group->members()->sync([$member->id]);

        $row = $this->row($this->report(), $member->id);

        $this->assertSame(1, $row['overdue_exams']);
    }

    /** @test */
    public function test_filters_by_status_course_group_and_search(): void
    {
        [$inactive, $inactiveEnrollment] = $this->student('Layla Inactive', 40, 60);
        $inactive->update(['last_login_at' => now()->subDays(30)]);
        $this->logProgress($inactiveEnrollment, 30, true);

        [$active, $activeEnrollment] = $this->student('Omar Active', 10, 60);
        $this->logProgress($activeEnrollment, 1);

        // status=inactive
        $res = $this->report(['status' => 'inactive'])->assertOk();
        $this->assertNotNull($this->row($res, $inactive->id));
        $this->assertNull($this->row($res, $active->id));

        // search
        $res = $this->report(['q' => 'Layla'])->assertOk();
        $this->assertSame(1, $res->json('meta.pagination.total'));
        $this->assertNotNull($this->row($res, $inactive->id));

        // course filter (شامل)
        $res = $this->report(['course_id' => $this->course->id])->assertOk();
        $this->assertSame(2, $res->json('meta.pagination.total'));

        // group filter: عضو واحد فقط
        $group = CourseGroup::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $this->course->id, 'name' => 'B', 'sort_order' => 0, 'is_active' => true,
        ]);
        $group->members()->sync([$active->id]);
        $res = $this->report(['group_id' => $group->id])->assertOk();
        $this->assertSame(1, $res->json('meta.pagination.total'));
        $this->assertNotNull($this->row($res, $active->id));
    }

    /** @test */
    public function test_meta_counts_and_pagination(): void
    {
        [$inactive, $e1] = $this->student('A Inactive', 40, 60);
        $inactive->update(['last_login_at' => now()->subDays(20)]);
        $this->logProgress($e1, 20, true);

        [$active, $e2] = $this->student('B Active', 10, 60);
        $this->logProgress($e2, 1);

        $this->student('C Fresh', 1, 0);

        $res = $this->report(['per_page' => 2, 'page' => 1])->assertOk();

        $this->assertSame(3, $res->json('meta.counts.total'));
        $this->assertGreaterThanOrEqual(1, $res->json('meta.counts.inactive'));
        $this->assertSame(3, $res->json('meta.pagination.total'));
        $this->assertSame(2, $res->json('meta.pagination.per_page'));
        $this->assertCount(2, $res->json('data'));
        $this->assertSame(2, $res->json('meta.pagination.last_page'));
        $this->assertArrayHasKey('courses', $res->json('meta'));
        $this->assertArrayHasKey('data_quality', $res->json('meta'));

        $res2 = $this->report(['per_page' => 2, 'page' => 2])->assertOk();
        $this->assertCount(1, $res2->json('data'));
    }

    /** @test */
    public function test_device_heartbeat_counts_as_activity(): void
    {
        [$user] = $this->student('AppUser', 40, 0);
        UserDevice::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'device_id' => 'dev-1', 'device_name' => 'iPhone', 'last_seen_at' => now()->subHours(4),
        ]);

        $row = $this->row($this->report(), $user->id);

        $this->assertSame('device', $row['last_activity_source']);
        $this->assertTrue($row['data_available']);
        $this->assertSame(0, $row['days_inactive']);
    }
}
