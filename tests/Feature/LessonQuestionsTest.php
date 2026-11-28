<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonQuestion;
use App\Models\Section;
use App\Models\Tenant;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Per-lesson Q&A.
 *
 * Guarantees: a public question is visible to the tenant's students while a
 * private one is not; the teacher (and only the teacher) can answer; an
 * answered question can no longer be deleted; and a lesson from another tenant
 * is never reachable.
 *
 * (Student notes live in a separate feature — see LessonNoteTest.)
 */
class LessonQuestionsTest extends TenantTestCase
{
    private function tree(Tenant $tenant): array
    {
        $course = Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'title' => 'Course', 'slug' => 'c-'.Str::random(6),
            'is_published' => true, 'sort_order' => 0,
        ]);
        $section = Section::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'course_id' => $course->id, 'title' => 'Unit', 'is_published' => true, 'sort_order' => 0,
        ]);
        $lesson = Lesson::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'section_id' => $section->id, 'title' => 'Lesson',
            'lesson_type' => 'text', 'is_published' => true, 'sort_order' => 0,
        ]);

        return [$course, $lesson];
    }

    /** @test */
    public function test_public_question_is_visible_to_other_students_but_private_is_not(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        [, $lesson] = $this->tree($tenant);

        $asker = $this->makeUser();
        $this->attachToTenant($asker, $tenant, 'student');
        $other = $this->makeUser();
        $this->attachToTenant($other, $tenant, 'student');

        $this->postJson("/api/v1/lessons/{$lesson->id}/questions", ['body' => 'سؤال عام'], $this->authHeaders($asker, $tenant));
        $this->postJson("/api/v1/lessons/{$lesson->id}/questions", ['body' => 'سؤال خاص', 'is_public' => false], $this->authHeaders($asker, $tenant));

        $res = $this->getJson("/api/v1/lessons/{$lesson->id}/questions", $this->authHeaders($other, $tenant));
        $res->assertOk();
        $bodies = collect($res->json('questions'))->pluck('body')->all();
        $this->assertContains('سؤال عام', $bodies);
        $this->assertNotContains('سؤال خاص', $bodies);
    }

    /** @test */
    public function test_teacher_answers_a_question_and_a_student_cannot(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        [, $lesson] = $this->tree($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $questionId = $this->postJson("/api/v1/lessons/{$lesson->id}/questions", ['body' => 'كيف؟'], $this->authHeaders($student, $tenant))
            ->json('question.id');

        // A student may not answer.
        $this->postJson("/api/v1/lesson-questions/{$questionId}/answer", ['answer' => 'هكذا'], $this->authHeaders($student, $tenant))
            ->assertStatus(403);

        $res = $this->postJson("/api/v1/lesson-questions/{$questionId}/answer", ['answer' => 'الجواب'], $this->authHeaders($teacher, $tenant));
        $res->assertOk();
        $this->assertSame('الجواب', $res->json('question.answer'));
        $this->assertNotNull($res->json('question.answered_at'));

        $list = $this->getJson('/api/v1/lesson-questions?status=unanswered', $this->authHeaders($teacher, $tenant));
        $list->assertOk();
        $this->assertSame(0, $list->json('unanswered_count'));
    }

    /** @test */
    public function test_an_answered_question_cannot_be_deleted_but_an_unanswered_one_can(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        [, $lesson] = $this->tree($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $answeredId = $this->postJson("/api/v1/lessons/{$lesson->id}/questions", ['body' => 'أ'], $this->authHeaders($student, $tenant))->json('question.id');
        $this->postJson("/api/v1/lesson-questions/{$answeredId}/answer", ['answer' => 'ج'], $this->authHeaders($teacher, $tenant));

        $this->deleteJson("/api/v1/lesson-questions/{$answeredId}", [], $this->authHeaders($student, $tenant))
            ->assertStatus(422);

        $openId = $this->postJson("/api/v1/lessons/{$lesson->id}/questions", ['body' => 'ب'], $this->authHeaders($student, $tenant))->json('question.id');
        $this->deleteJson("/api/v1/lesson-questions/{$openId}", [], $this->authHeaders($student, $tenant))
            ->assertOk();
        $this->assertNull(LessonQuestion::withoutTenant()->find($openId));
    }

    /** @test */
    public function test_a_lesson_from_another_tenant_is_not_reachable(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantA);
        [, $lessonB] = $this->tree($tenantB);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenantA, 'student');

        $this->postJson("/api/v1/lessons/{$lessonB->id}/questions", ['body' => 'x'], $this->authHeaders($student, $tenantA))
            ->assertStatus(404);
    }
}
