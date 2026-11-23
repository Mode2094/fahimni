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
 * Lesson notes & Q&A.
 *
 * Guarantees: notes are private to their author unless explicitly shared;
 * students never see another student's private question; the teacher can read
 * and answer every question of the tenant; and one student can never edit or
 * delete another student's note/question.
 */
class LessonNotesQuestionsTest extends TenantTestCase
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
    public function test_student_creates_and_reads_only_their_own_notes(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        [$course, $lesson] = $this->tree($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $this->postJson("/api/v1/lessons/{$lesson->id}/notes", [
            'body' => 'نقطة مهمة',
            'timestamp_seconds' => 42,
        ], $this->authHeaders($student, $tenant))->assertStatus(201);

        $res = $this->getJson('/api/v1/lesson-notes', $this->authHeaders($student, $tenant));
        $res->assertOk();
        $this->assertCount(1, $res->json('notes'));
        $this->assertSame(42, $res->json('notes.0.timestamp_seconds'));

        // Another student sees nothing.
        $other = $this->makeUser();
        $this->attachToTenant($other, $tenant, 'student');
        $otherRes = $this->getJson('/api/v1/lesson-notes', $this->authHeaders($other, $tenant));
        $this->assertCount(0, $otherRes->json('notes'));
    }

    /** @test */
    public function test_teacher_only_sees_shared_notes(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        [$course, $lesson] = $this->tree($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $this->postJson("/api/v1/lessons/{$lesson->id}/notes", ['body' => 'خاصة'], $this->authHeaders($student, $tenant));
        $this->postJson("/api/v1/lessons/{$lesson->id}/notes", ['body' => 'مشتركة', 'is_shared' => true], $this->authHeaders($student, $tenant));

        $res = $this->getJson('/api/v1/lesson-notes', $this->authHeaders($teacher, $tenant));
        $res->assertOk();
        $this->assertCount(1, $res->json('notes'));
        $this->assertSame('مشتركة', $res->json('notes.0.body'));
    }

    /** @test */
    public function test_student_cannot_edit_or_delete_another_students_note(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        [$course, $lesson] = $this->tree($tenant);

        $owner = $this->makeUser();
        $this->attachToTenant($owner, $tenant, 'student');
        $intruder = $this->makeUser();
        $this->attachToTenant($intruder, $tenant, 'student');

        $noteId = $this->postJson("/api/v1/lessons/{$lesson->id}/notes", ['body' => 'ملكي'], $this->authHeaders($owner, $tenant))
            ->json('note.id');

        $this->putJson("/api/v1/lesson-notes/{$noteId}", ['body' => 'معدّل'], $this->authHeaders($intruder, $tenant))
            ->assertStatus(403);
        $this->deleteJson("/api/v1/lesson-notes/{$noteId}", [], $this->authHeaders($intruder, $tenant))
            ->assertStatus(403);
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

        $this->postJson("/api/v1/lessons/{$lessonB->id}/notes", ['body' => 'x'], $this->authHeaders($student, $tenantA))
            ->assertStatus(404);
    }

    /** @test */
    public function test_public_question_is_visible_to_other_students_but_private_is_not(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        [$course, $lesson] = $this->tree($tenant);

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
        [$course, $lesson] = $this->tree($tenant);

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
        [$course, $lesson] = $this->tree($tenant);

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
}
