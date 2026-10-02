<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Section;
use App\Models\Tenant;
use App\Services\CourseAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Uid\UuidV7;

class ExamIntegrityTest extends TenantTestCase
{
    use RefreshDatabase;

    private function makeExamQuestion(Exam $exam, Question $question, int $points = 10): ExamQuestion
    {
        return ExamQuestion::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $exam->tenant_id,
            'exam_id' => $exam->id,
            'question_id' => $question->id,
            'sort_order' => 0,
            'points_override' => $points,
        ]);
    }

    private function makeMcq(array $attrs = []): Question
    {
        return Question::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenant()->id,
            'question_type' => 'multiple_choice',
            'question_text' => 'Q?',
            'points' => 10,
            'is_active' => true,
            'metadata' => [],
            ...$attrs,
        ]);
    }

    private ?Tenant $memoTenant = null;

    private function tenant(): Tenant
    {
        return $this->memoTenant ??= $this->makeTenant();
    }

    public function test_start_payload_never_leaks_answer_key_metadata(): void
    {
        $tenant = $this->tenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $course = Course::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'title' => 'C', 'slug' => 'c', 'is_published' => true]);
        $section = Section::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'title' => 'S', 'sort_order' => 0]);
        $lesson = Lesson::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'section_id' => $section->id, 'title' => 'L', 'is_published' => true, 'sort_order' => 0]);

        $writing = $this->makeMcq(['question_type' => 'writing', 'metadata' => ['keywords' => ['secretword']]]);
        $fill = $this->makeMcq(['question_type' => 'fill_blank', 'metadata' => ['answers' => ['hidden']]]);
        $ordering = $this->makeMcq(['question_type' => 'ordering', 'metadata' => ['items' => ['a', 'b', 'c', 'd', 'e', 'f']]]);

        $exam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'section_id' => $section->id,
            'title' => 'E', 'pass_mark_percent' => 50, 'max_attempts' => 3, 'is_published' => true, 'duration_minutes' => 30,
            'metadata' => [],
        ]);
        $this->makeExamQuestion($exam, $writing);
        $this->makeExamQuestion($exam, $fill);
        $this->makeExamQuestion($exam, $ordering);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        Enrollment::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now()]);

        $response = $this->withHeaders($this->authHeaders($student, $tenant))->postJson("/api/v1/exams/{$exam->id}/attempt");
        $response->assertOk();

        $questions = collect($response->json('questions'))->keyBy('question_type');

        $this->assertArrayNotHasKey('keywords', $questions['writing']['metadata']);
        $this->assertArrayNotHasKey('answers', $questions['fill_blank']['metadata']);
        // Ordering items are shuffled — never the canonical order.
        $this->assertNotEquals(['a', 'b', 'c', 'd', 'e', 'f'], $questions['ordering']['metadata']['items']);
        $this->assertEquals(6, count($questions['ordering']['metadata']['items']));
    }

    public function test_partial_submission_is_graded_against_full_question_set(): void
    {
        $tenant = $this->tenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $course = Course::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'title' => 'C', 'slug' => 'c', 'is_published' => true]);
        $section = Section::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'title' => 'S', 'sort_order' => 0]);

        $q1 = $this->makeMcq();
        $opt1 = QuestionOption::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'question_id' => $q1->id, 'option_text' => 'A', 'is_correct' => true, 'sort_order' => 0]);
        QuestionOption::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'question_id' => $q1->id, 'option_text' => 'B', 'is_correct' => false, 'sort_order' => 1]);

        $q2 = $this->makeMcq();
        QuestionOption::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'question_id' => $q2->id, 'option_text' => 'X', 'is_correct' => true, 'sort_order' => 0]);
        QuestionOption::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'question_id' => $q2->id, 'option_text' => 'Y', 'is_correct' => false, 'sort_order' => 1]);

        $exam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'section_id' => $section->id,
            'title' => 'E', 'pass_mark_percent' => 90, 'max_attempts' => 3, 'is_published' => true, 'duration_minutes' => 30,
            'metadata' => [],
        ]);
        $eq1 = $this->makeExamQuestion($exam, $q1, 10);
        $this->makeExamQuestion($exam, $q2, 10);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        Enrollment::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now()]);

        $headers = $this->authHeaders($student, $tenant);
        $start = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempt");
        $attemptId = $start->json('attempt.id');

        // Submit only ONE of two questions (correctly) — must NOT be 100%.
        $submit = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempts/{$attemptId}/submit", [
            'answers' => [
                ['exam_question_id' => $eq1->id, 'question_id' => $q1->id, 'given_answer' => $opt1->id],
            ],
        ]);

        $submit->assertOk();
        $this->assertEquals(50, (float) $submit->json('result.percentage'));
        $this->assertFalse($submit->json('result.passed'));
        $this->assertEquals(20, (float) $submit->json('result.total'));
    }

    public function test_certificate_requires_all_live_lessons(): void
    {
        $tenant = $this->tenant();
        $course = Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'title' => 'C', 'slug' => 'c',
            'is_published' => true, 'certificate_enabled' => true, 'completion_criteria' => 'all_lessons', 'metadata' => [],
        ]);
        $section = Section::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'title' => 'S', 'sort_order' => 0]);
        $l1 = Lesson::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'section_id' => $section->id, 'title' => 'L1', 'is_published' => true, 'sort_order' => 0]);
        $l2 = Lesson::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'section_id' => $section->id, 'title' => 'L2', 'is_published' => true, 'sort_order' => 1]);
        // Draft lesson must NOT be required.
        Lesson::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'section_id' => $section->id, 'title' => 'L3', 'is_published' => false, 'sort_order' => 2]);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->attachSubscription($tenant);
        $enrollment = Enrollment::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now()]);

        $access = app(CourseAccessService::class);
        $this->assertFalse($access->completionRequirementsMet($enrollment));

        $headers = $this->authHeaders($student, $tenant);

        // Completing only ONE of the two live lessons must NOT issue a
        // certificate.
        $this->withHeaders($headers)->postJson("/api/v1/enrollments/{$enrollment->id}/progress/{$l1->id}/complete")->assertOk();
        $this->assertDatabaseMissing('student_certificates', ['user_id' => $student->id, 'course_id' => $course->id]);

        // Completing the second lesson completes the course -> certificate.
        $this->withHeaders($headers)->postJson("/api/v1/enrollments/{$enrollment->id}/progress/{$l2->id}/complete")->assertOk();
        $this->assertDatabaseHas('student_certificates', ['user_id' => $student->id, 'course_id' => $course->id, 'status' => 'issued']);
    }

    public function test_certificate_not_issued_when_drip_locked_lessons_missing(): void
    {
        $tenant = $this->tenant();
        $course = Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'title' => 'C', 'slug' => 'c',
            'is_published' => true, 'certificate_enabled' => true, 'completion_criteria' => 'all_lessons',
            'drip_enabled' => true, 'drip_interval_days' => 1, 'metadata' => [],
        ]);
        $section = Section::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'title' => 'S', 'sort_order' => 0]);
        $l1 = Lesson::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'section_id' => $section->id, 'title' => 'L1', 'is_published' => true, 'sort_order' => 0]);
        $l2 = Lesson::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'section_id' => $section->id, 'title' => 'L2', 'is_published' => true, 'sort_order' => 1]);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $enrollment = Enrollment::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now()]);

        // Lesson 2 is drip-locked — a student cannot complete it.
        $response = $this->withHeaders($this->authHeaders($student, $tenant))
            ->postJson("/api/v1/enrollments/{$enrollment->id}/progress/{$l2->id}/complete");

        $response->assertStatus(422);
        $this->assertDatabaseMissing('student_certificates', ['user_id' => $student->id]);

        // Completing lesson 1 (first in sequence) works.
        $this->withHeaders($this->authHeaders($student, $tenant))
            ->postJson("/api/v1/enrollments/{$enrollment->id}/progress/{$l1->id}/complete")
            ->assertOk();
    }
}
