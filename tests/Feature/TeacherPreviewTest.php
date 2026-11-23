<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\LessonProgress;
use App\Models\Question;
use App\Models\Section;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * "Preview as student" — the teacher opens the real student experience before
 * publishing. The server authorises preview access (tenant staff / course author
 * only); `?preview=1` is only a UI hint. A student can never use it to read an
 * unpublished course/lesson/exam, and a preview never records an attempt, a
 * score, progress, a watch, a seat or any other write.
 */
class TeacherPreviewTest extends TenantTestCase
{
    use RefreshDatabase;

    private function makeUnpublishedCourse(Tenant $tenant, bool $drip = false): Course
    {
        return Course::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'title' => 'Draft course',
            'slug' => 'draft-'.Str::random(6),
            'is_published' => false,
            'drip_enabled' => $drip,
            'drip_interval_days' => 7,
            'sort_order' => 0,
        ]);
    }

    /** @return array{course: Course, section: Section, lesson: Lesson, exam: Exam, question: Question} */
    private function makeDraftTree(Tenant $tenant, bool $drip = false): array
    {
        $course = $this->makeUnpublishedCourse($tenant, $drip);

        $section = Section::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'course_id' => $course->id, 'title' => 'Unit 1', 'is_published' => true, 'sort_order' => 0,
        ]);

        $lesson = Lesson::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'section_id' => $section->id, 'title' => 'Draft video', 'lesson_type' => 'video',
            'is_published' => true, 'sort_order' => 0,
        ]);

        $content = LessonContent::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'lesson_id' => $lesson->id, 'content_type' => 'text', 'sort_order' => 0,
        ]);
        \App\Models\TextContent::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'lesson_content_id' => $content->id,
            'body' => 'Draft body', 'format' => 'plain',
        ]);

        $exam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'course_id' => $course->id, 'title' => 'Draft exam', 'pass_mark_percent' => 50,
            'max_attempts' => 1, 'is_published' => false, 'duration_minutes' => 20, 'metadata' => [],
        ]);

        $question = Question::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'question_type' => 'multiple_choice', 'question_text' => 'Q1',
            'points' => 5, 'is_active' => true, 'metadata' => [],
        ]);
        ExamQuestion::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'exam_id' => $exam->id, 'question_id' => $question->id,
            'sort_order' => 0, 'points_override' => null,
        ]);

        return compact('course', 'section', 'lesson', 'exam', 'question');
    }

    // ─────────────────────────── draft course / lesson preview ───────────────────────────

    /** @test */
    public function test_course_owner_can_preview_an_unpublished_course(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course] = $this->makeDraftTree($tenant);

        $owner = $this->makeUser();
        $this->attachToTenant($owner, $tenant, 'owner');

        $response = $this->getJson(
            "/api/v1/public/courses/{$course->id}/sections",
            $this->authHeaders($owner, $tenant),
        );

        $response->assertOk();
        $this->assertTrue($response->json('preview'), 'the owner is flagged as previewing');
        $this->assertTrue($response->json('is_enrolled'), 'staff count as enrolled');
        $this->assertFalse($response->json('sections.0.lessons.0.locked'), 'staff preview is never locked');
    }

    /** @test */
    public function test_teacher_staff_can_preview_unpublished_course_and_lesson(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'lesson' => $lesson] = $this->makeDraftTree($tenant);

        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $this->getJson(
            "/api/v1/public/courses/{$course->id}/sections",
            $this->authHeaders($teacher, $tenant),
        )->assertOk()->assertJsonPath('preview', true);

        $lessonResponse = $this->getJson(
            "/api/v1/public/courses/{$course->id}/lessons/{$lesson->id}",
            $this->authHeaders($teacher, $tenant),
        );
        $lessonResponse->assertOk();
        $this->assertTrue($lessonResponse->json('preview'));
        $this->assertSame('Draft body', $lessonResponse->json('contents.0.text.body'));
    }

    /** @test */
    public function test_drip_never_locks_lessons_for_the_previewing_staff(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        // Drip enabled + no enrollment would lock every lesson for a student.
        ['course' => $course] = $this->makeDraftTree($tenant, drip: true);

        $owner = $this->makeUser();
        $this->attachToTenant($owner, $tenant, 'owner');

        $response = $this->getJson(
            "/api/v1/public/courses/{$course->id}/sections",
            $this->authHeaders($owner, $tenant),
        );

        $response->assertOk();
        $this->assertFalse($response->json('drip.enabled'), 'drip is not applied to the author');
        $this->assertFalse($response->json('sections.0.lessons.0.locked'));
        $this->assertNull($response->json('sections.0.lessons.0.unlock_at'));
    }

    // ─────────────────────────── students cannot use it to bypass ───────────────────────────

    /** @test */
    public function test_unenrolled_student_cannot_preview_an_unpublished_course_even_with_the_preview_flag(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'lesson' => $lesson] = $this->makeDraftTree($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $this->getJson(
            "/api/v1/public/courses/{$course->id}/sections?preview=1",
            $this->authHeaders($student, $tenant),
        )->assertStatus(404);

        $this->getJson(
            "/api/v1/public/courses/{$course->id}/lessons/{$lesson->id}?preview=1",
            $this->authHeaders($student, $tenant),
        )->assertStatus(404);
    }

    /** @test */
    public function test_anonymous_cannot_preview_an_unpublished_course(): void
    {
        $tenant = $this->makeTenant();
        ['course' => $course] = $this->makeDraftTree($tenant);

        $this->getJson("/api/v1/public/courses/{$course->id}/sections?preview=1")
            ->assertStatus(404);
    }

    /** @test */
    public function test_cross_tenant_teacher_cannot_preview_a_draft_course(): void
    {
        $tenantA = $this->makeTenant();
        $this->attachSubscription($tenantA);
        ['course' => $courseA] = $this->makeDraftTree($tenantA);

        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantB);
        $teacherB = $this->makeUser();
        $this->attachToTenant($teacherB, $tenantB, 'teacher');

        $this->getJson(
            "/api/v1/public/courses/{$courseA->id}/sections?preview=1",
            $this->authHeaders($teacherB, $tenantB),
        )->assertStatus(404);
    }

    /** @test */
    public function test_an_enrolled_student_reading_a_published_course_is_not_flagged_as_preview(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);

        $course = Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'title' => 'Live course', 'slug' => 'live-'.Str::random(6), 'is_published' => true, 'sort_order' => 0,
        ]);
        Section::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'course_id' => $course->id, 'title' => 'S', 'is_published' => true, 'sort_order' => 0,
        ]);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        Enrollment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'course_id' => $course->id, 'user_id' => $student->id,
            'status' => 'active', 'enrolled_at' => now(), 'progress_percent' => 0,
            'source' => 'manual', 'metadata' => [],
        ]);

        $this->getJson(
            "/api/v1/public/courses/{$course->id}/sections?preview=1",
            $this->authHeaders($student, $tenant),
        )->assertOk()->assertJsonPath('preview', false);
    }

    // ─────────────────────────── preview leaves student data untouched ───────────────────────────

    /** @test */
    public function test_previewing_course_and_lesson_writes_nothing(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        TenantSetting::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'file_downloads_enabled' => true,
        ]);
        ['course' => $course, 'lesson' => $lesson] = $this->makeDraftTree($tenant, drip: true);

        $owner = $this->makeUser();
        $this->attachToTenant($owner, $tenant, 'owner');

        // A student exists with real progress we must not disturb.
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $enrollment = Enrollment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'course_id' => $course->id, 'user_id' => $student->id,
            'status' => 'active', 'enrolled_at' => now(), 'progress_percent' => 42,
            'source' => 'manual', 'metadata' => [],
        ]);

        $headers = $this->authHeaders($owner, $tenant);
        $this->getJson("/api/v1/public/courses/{$course->id}/sections?preview=1", $headers)->assertOk();
        $this->getJson("/api/v1/public/courses/{$course->id}/lessons/{$lesson->id}?preview=1", $headers)->assertOk();

        $this->assertSame(0, LessonProgress::count(), 'preview must not create progress');
        $this->assertSame(0, ExamAttempt::count(), 'preview must not create an attempt');
        $this->assertSame(1, Enrollment::count(), 'preview must not create an enrollment/seat');
        $this->assertSame(42, (int) $enrollment->fresh()->progress_percent, 'student progress is untouched');
    }

    // ─────────────────────────── exam preview ───────────────────────────

    /** @test */
    public function test_exam_preview_is_staff_only(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['exam' => $exam] = $this->makeDraftTree($tenant);

        $owner = $this->makeUser();
        $this->attachToTenant($owner, $tenant, 'owner');
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $this->withHeaders($this->authHeaders($owner, $tenant))
            ->postJson("/api/v1/exams/{$exam->id}/preview")
            ->assertOk()
            ->assertJsonPath('preview', true);

        $this->withHeaders($this->authHeaders($student, $tenant))
            ->postJson("/api/v1/exams/{$exam->id}/preview")
            ->assertStatus(403);
    }

    /** @test */
    public function test_exam_preview_and_grade_record_no_attempt_no_score(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['exam' => $exam, 'question' => $question] = $this->makeDraftTree($tenant);

        $owner = $this->makeUser();
        $this->attachToTenant($owner, $tenant, 'owner');

        // Previewing an unpublished exam returns the real payload.
        $preview = $this->withHeaders($this->authHeaders($owner, $tenant))
            ->postJson("/api/v1/exams/{$exam->id}/preview");
        $preview->assertOk()->assertJsonPath('preview', true);
        $examQuestionId = $preview->json('questions.0.exam_question_id');
        $this->assertNotNull($examQuestionId);

        // Dry-run grading runs the same grader but persists nothing.
        $this->withHeaders($this->authHeaders($owner, $tenant))
            ->postJson("/api/v1/exams/{$exam->id}/preview/grade", [
                'answers' => [
                    ['exam_question_id' => $examQuestionId, 'given_answer' => ['x']],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview', true);

        $this->assertSame(0, ExamAttempt::count(), 'no attempt is recorded by a preview');
        $this->assertSame(0, ExamAnswer::count(), 'no graded answer is stored by a preview');
    }
}
