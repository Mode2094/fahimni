<?php

namespace Tests\Feature;

use App\Models\ActivationCard;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Lesson;
use App\Models\Question;
use App\Models\Section;
use Symfony\Component\Uid\UuidV7;

/**
 * Regression coverage for the final teacher-request release (2026-11-28).
 *
 *   - Request 1: an HTML exercise submission is only ever "submitted" to the
 *     student — never auto-passed, never a percentage — while the teacher's
 *     grading view keeps the student name, answers and history.
 *   - Request 2: the lesson exercise payload exposes the assessment mode so the
 *     client can hide the pass mark on an ungraded practice.
 *   - Request 3: email verification is optional — an unverified student can
 *     sign in, open their course and redeem a card.
 */
class TeacherFinalRequests2026_11Test extends TenantTestCase
{
    /**
     * @return array{0: \App\Models\Tenant, 1: \App\Models\User, 2: Course, 3: Section}
     */
    private function workspace(): array
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $course = Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'teacher_id' => $teacher->id,
            'title' => 'Physics', 'slug' => 'physics-'.UuidV7::generate(), 'is_published' => true,
        ]);
        $section = Section::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'title' => 'S', 'sort_order' => 0, 'is_published' => true,
        ]);

        return [$tenant, $teacher, $course, $section];
    }

    private function enroll($tenant, Course $course, $student): Enrollment
    {
        return Enrollment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now(),
        ]);
    }

    /** An exam with one interactive-HTML question left to manual review. */
    private function htmlExam($tenant, Course $course, Section $section, string $mode = Exam::MODE_GRADED): Exam
    {
        $exam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'section_id' => $section->id, 'title' => 'HTML exercise', 'pass_mark_percent' => 50,
            'max_attempts' => 3, 'is_published' => true, 'duration_minutes' => 30,
            'assessment_mode' => $mode, 'metadata' => [],
        ]);
        $question = Question::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'question_type' => Question::TYPE_INTERACTIVE_HTML, 'question_text' => 'Build the page',
            'points' => 10, 'is_active' => true,
            'metadata' => [
                'interactive' => ['html' => '<div></div>', 'css' => '', 'js' => '', 'height' => 300, 'auto_capture' => true],
                'grading' => ['mode' => 'manual'],
            ],
        ]);
        ExamQuestion::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'exam_id' => $exam->id,
            'question_id' => $question->id, 'sort_order' => 0, 'points_override' => null,
        ]);

        return $exam;
    }

    // ─── Request 1: HTML submission is only "submitted" ──────────────

    /** @test */
    public function test_an_html_exercise_submission_is_never_auto_passed(): void
    {
        [$tenant, , $course, $section] = $this->workspace();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $course, $student);
        $exam = $this->htmlExam($tenant, $course, $section);

        $headers = $this->authHeaders($student, $tenant);
        $attemptId = $this->withHeaders($headers)
            ->postJson("/api/v1/exams/{$exam->id}/attempt")->json('attempt.id');

        $eq = ExamQuestion::where('exam_id', $exam->id)->firstOrFail();
        $result = $this->withHeaders($headers)
            ->postJson("/api/v1/exams/{$exam->id}/attempts/{$attemptId}/submit", [
                'answers' => [[
                    'exam_question_id' => $eq->id,
                    'question_id' => $eq->question_id,
                    'given_answer' => '<html>submitted</html>',
                ]],
            ])
            ->assertOk();

        // The student-facing result is a submission, never a pass and never a
        // hidden 0%: needs_review is true and passed is false.
        $result->assertJsonPath('result.needs_review', true)
            ->assertJsonPath('result.passed', false);

        // The answer is preserved for the teacher (never silently dropped).
        $this->assertDatabaseHas('exam_answers', [
            'exam_question_id' => $eq->id, 'is_correct' => null,
        ]);
    }

    /** @test */
    public function test_the_teacher_grading_view_keeps_the_student_and_answer(): void
    {
        [$tenant, $teacher, $course, $section] = $this->workspace();
        $student = $this->makeUser(['name' => 'سارة أحمد']);
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $course, $student);
        $exam = $this->htmlExam($tenant, $course, $section);

        $studentHeaders = $this->authHeaders($student, $tenant);
        $attemptId = $this->withHeaders($studentHeaders)
            ->postJson("/api/v1/exams/{$exam->id}/attempt")->json('attempt.id');
        $eq = ExamQuestion::where('exam_id', $exam->id)->firstOrFail();
        $this->withHeaders($studentHeaders)
            ->postJson("/api/v1/exams/{$exam->id}/attempts/{$attemptId}/submit", [
                'answers' => [[
                    'exam_question_id' => $eq->id, 'question_id' => $eq->question_id,
                    'given_answer' => '<html>answer</html>',
                ]],
            ])->assertOk();

        $res = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->getJson("/api/v1/exams/{$exam->id}/attempts")
            ->assertOk();

        $row = collect($res->json('attempts'))->firstWhere('id', $attemptId);
        $this->assertNotNull($row, 'the teacher must see the attempt');
        $this->assertSame('سارة أحمد', $row['student']['name']);
        $this->assertSame(1, $row['manual_review_count']);
        $this->assertCount(1, $row['answers']);
        $this->assertNull($row['answers'][0]['is_correct']);
    }

    // ─── Request 2: lesson exercise payload exposes the mode ─────────

    /** @test */
    public function test_the_lesson_exercise_payload_exposes_assessment_mode(): void
    {
        [$tenant, , $course, $section] = $this->workspace();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $course, $student);

        $practice = $this->htmlExam($tenant, $course, $section, Exam::MODE_PRACTICE);
        $lesson = Lesson::create([
            'id' => UuidV7::generate(), 'section_id' => $section->id, 'tenant_id' => $tenant->id,
            'title' => 'Lesson', 'lesson_type' => 'text', 'is_published' => true,
            'sort_order' => 0, 'is_free_preview' => false,
            'metadata' => ['exercise_exam_id' => $practice->id],
        ]);

        $this->withHeaders($this->authHeaders($student, $tenant))
            ->getJson("/api/v1/public/courses/{$course->id}/lessons/{$lesson->id}")
            ->assertOk()
            ->assertJsonPath('exercise.assessment_mode', 'practice');
    }

    /** @test */
    public function test_a_graded_lesson_exercise_reports_a_graded_mode(): void
    {
        [$tenant, , $course, $section] = $this->workspace();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $course, $student);

        $graded = $this->htmlExam($tenant, $course, $section, Exam::MODE_GRADED);
        $lesson = Lesson::create([
            'id' => UuidV7::generate(), 'section_id' => $section->id, 'tenant_id' => $tenant->id,
            'title' => 'Lesson', 'lesson_type' => 'text', 'is_published' => true,
            'sort_order' => 0, 'is_free_preview' => false,
            'metadata' => ['exercise_exam_id' => $graded->id],
        ]);

        $this->withHeaders($this->authHeaders($student, $tenant))
            ->getJson("/api/v1/public/courses/{$course->id}/lessons/{$lesson->id}")
            ->assertOk()
            ->assertJsonPath('exercise.assessment_mode', 'graded');
    }

    // ─── Request 3: verification is optional ─────────────────────────

    /** @test */
    public function test_an_unverified_student_can_sign_in_and_open_their_course(): void
    {
        [$tenant, , $course, $section] = $this->workspace();
        $student = $this->makeUser(['email_verified_at' => null]);
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $course, $student);

        $lesson = Lesson::create([
            'id' => UuidV7::generate(), 'section_id' => $section->id, 'tenant_id' => $tenant->id,
            'title' => 'Lesson', 'lesson_type' => 'text', 'is_published' => true,
            'sort_order' => 0, 'is_free_preview' => false, 'metadata' => [],
        ]);

        $this->assertNull($student->fresh()->email_verified_at);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $student->email, 'password' => 'password',
        ]);
        $login->assertOk();
        $this->assertNotEmpty($login->json('token'));

        // And the course lesson opens normally while still unverified.
        $this->withHeaders($this->authHeaders($student, $tenant))
            ->getJson("/api/v1/public/courses/{$course->id}/lessons/{$lesson->id}")
            ->assertOk();
    }

    /** @test */
    public function test_an_unverified_student_can_redeem_an_activation_card(): void
    {
        [$tenant, , $course] = $this->workspace();
        $student = $this->makeUser(['email_verified_at' => null]);
        $this->attachToTenant($student, $tenant, 'student');

        $card = ActivationCard::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'code' => 'VERIFY'.strtoupper(substr(UuidV7::generate(), 0, 6)), 'status' => 'generated',
        ]);

        $this->withHeaders($this->authHeaders($student, $tenant))
            ->postJson('/api/v1/cards/redeem', ['code' => $card->code])
            ->assertOk();

        $this->assertDatabaseHas('enrollments', [
            'tenant_id' => $tenant->id, 'course_id' => $course->id, 'user_id' => $student->id,
        ]);
        $this->assertSame('redeemed', $card->fresh()->status);
    }
}
