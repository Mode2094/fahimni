<?php

namespace Tests\Feature;

use App\Models\Course;
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
 * A video lesson may carry ONE attached exercise. It must reuse the real exam
 * engine (attempts + grading), never leak an unpublished exam or a foreign
 * tenant's exam, and report the real remaining attempts.
 */
class LessonExerciseTest extends TenantTestCase
{
    private function tree(Tenant $tenant, ?string $examId = null): array
    {
        $course = Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'title' => 'Exercise Course', 'slug' => 'ex-'.Str::random(6), 'is_published' => true,
        ]);
        $section = Section::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'course_id' => $course->id, 'title' => 'S1', 'is_published' => true, 'sort_order' => 0,
        ]);
        $lesson = Lesson::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'section_id' => $section->id, 'title' => 'Video lesson', 'lesson_type' => 'video',
            'is_published' => true, 'sort_order' => 0,
            'metadata' => $examId ? ['exercise_exam_id' => $examId, 'exercise_position' => 'after_video'] : [],
        ]);

        return compact('course', 'section', 'lesson');
    }

    private function exam(Tenant $tenant, Course $course, Section $section, bool $published = true): Exam
    {
        return Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'course_id' => $course->id, 'section_id' => $section->id,
            'title' => 'Lesson practice', 'pass_mark_percent' => 60, 'max_attempts' => 2,
            'is_published' => $published, 'duration_minutes' => 15, 'metadata' => [],
        ]);
    }

    private function enroll(Tenant $tenant, Course $course, User $student): void
    {
        Enrollment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'course_id' => $course->id, 'user_id' => $student->id,
            'status' => 'active', 'enrolled_at' => now(),
        ]);
    }

    /** @test */
    public function test_lesson_payload_exposes_the_published_attached_exercise(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'section' => $section] = $this->tree($tenant, 'tmp');
        $exam = $this->exam($tenant, $course, $section);
        $lesson = Lesson::where('section_id', $section->id)->first();
        $lesson->update(['metadata' => ['exercise_exam_id' => $exam->id, 'exercise_position' => 'after_video']]);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $course, $student);

        $res = $this->getJson(
            "/api/v1/public/courses/{$course->id}/lessons/{$lesson->id}",
            $this->authHeaders($student, $tenant),
        );

        $res->assertOk();
        $this->assertSame($exam->id, $res->json('exercise.exam_id'));
        $this->assertSame('after_video', $res->json('exercise.position'));
        $this->assertSame(2, $res->json('exercise.attempts_left'));
        $this->assertSame(0, $res->json('exercise.attempts_used'));
    }

    /** @test */
    public function test_unpublished_attached_exercise_is_hidden_from_students(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'section' => $section] = $this->tree($tenant, 'tmp');
        $exam = $this->exam($tenant, $course, $section, published: false);
        $lesson = Lesson::where('section_id', $section->id)->first();
        $lesson->update(['metadata' => ['exercise_exam_id' => $exam->id]]);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $course, $student);

        $res = $this->getJson(
            "/api/v1/public/courses/{$course->id}/lessons/{$lesson->id}",
            $this->authHeaders($student, $tenant),
        );

        $res->assertOk();
        $this->assertNull($res->json('exercise'), 'a draft exam must never be shown to a student');
    }

    /** @test */
    public function test_submitted_attempts_reduce_reported_remaining_attempts(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'section' => $section] = $this->tree($tenant, 'tmp');
        $exam = $this->exam($tenant, $course, $section);
        $lesson = Lesson::where('section_id', $section->id)->first();
        $lesson->update(['metadata' => ['exercise_exam_id' => $exam->id]]);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $course, $student);

        ExamAttempt::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'exam_id' => $exam->id, 'user_id' => $student->id,
            'attempt_number' => 1, 'status' => 'submitted',
            'score' => 6, 'total_points' => 10, 'percentage' => 60, 'passed' => true,
            'started_at' => now()->subMinutes(5), 'submitted_at' => now(),
        ]);

        $res = $this->getJson(
            "/api/v1/public/courses/{$course->id}/lessons/{$lesson->id}",
            $this->authHeaders($student, $tenant),
        );

        $this->assertSame(1, $res->json('exercise.attempts_used'));
        $this->assertSame(1, $res->json('exercise.attempts_left'));
        $this->assertTrue($res->json('exercise.last.passed'));
    }

    /** @test */
    public function test_attaching_another_tenants_exam_is_rejected(): void
    {
        $tenantA = $this->makeTenant();
        $this->attachSubscription($tenantA);
        ['course' => $course, 'section' => $section] = $this->tree($tenantA);
        $lesson = Lesson::where('section_id', $section->id)->first();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenantA, 'teacher');

        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantB);
        ['course' => $courseB, 'section' => $sectionB] = $this->tree($tenantB);
        $foreignExam = $this->exam($tenantB, $courseB, $sectionB);

        $res = $this->withHeaders($this->authHeaders($teacher, $tenantA))
            ->putJson("/api/v1/lessons/{$lesson->id}", [
                'metadata' => ['exercise_exam_id' => $foreignExam->id],
            ]);

        $res->assertStatus(422);
        $this->assertNull(Lesson::find($lesson->id)->metadata['exercise_exam_id'] ?? null);
    }

    /** @test */
    public function test_teacher_can_attach_an_exam_and_metadata_is_merged(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'section' => $section] = $this->tree($tenant, 'tmp');
        $exam = $this->exam($tenant, $course, $section);
        $lesson = Lesson::where('section_id', $section->id)->first();
        $lesson->update(['metadata' => ['existing_key' => 'keep-me']]);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->putJson("/api/v1/lessons/{$lesson->id}", [
                'metadata' => ['exercise_exam_id' => $exam->id, 'exercise_position' => 'after_content'],
            ])
            ->assertOk();

        $meta = Lesson::find($lesson->id)->metadata;
        $this->assertSame($exam->id, $meta['exercise_exam_id']);
        $this->assertSame('after_content', $meta['exercise_position']);
        $this->assertSame('keep-me', $meta['existing_key'], 'merging must preserve unrelated metadata keys');
    }
}
