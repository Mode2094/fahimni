<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Question;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Uid\UuidV7;

/**
 * Ungraded interactive skill practice vs a formal graded exam.
 *
 * A practice exercise shares the exam engine but is a different thing: no
 * timer, no points, no pass/fail, no anti-cheat. Completion is recorded (once,
 * idempotently) and never drags an average down or reads to the student as a
 * "Failed 0%". These tests lock that contract down, and prove the graded path
 * is unchanged.
 */
class PracticeModeTest extends TenantTestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $student;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant();
        $this->attachSubscription($this->tenant);

        $this->student = $this->makeUser();
        $this->attachToTenant($this->student, $this->tenant, 'student');

        $this->course = Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'title' => 'Physics', 'slug' => 'physics-'.UuidV7::generate(), 'is_published' => true,
        ]);
        Enrollment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $this->course->id, 'user_id' => $this->student->id,
            'status' => 'active', 'progress_percent' => 0,
            'enrolled_at' => now(), 'source' => 'manual', 'metadata' => [],
        ]);
    }

    private function makeExam(bool $practice): Exam
    {
        $exam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $this->course->id, 'title' => $practice ? 'Density practice' : 'Unit exam',
            'pass_mark_percent' => 50, 'max_attempts' => 2, 'is_published' => true,
            'duration_minutes' => 30, 'anti_cheat_enabled' => true, 'metadata' => [],
            'assessment_mode' => $practice ? Exam::MODE_PRACTICE : Exam::MODE_GRADED,
        ]);

        $q = Question::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'question_type' => 'multiple_choice', 'question_text' => 'Q1',
            'points' => 5, 'is_active' => true, 'metadata' => [],
        ]);
        ExamQuestion::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'exam_id' => $exam->id, 'question_id' => $q->id,
            'sort_order' => 0, 'points_override' => null,
        ]);

        return $exam;
    }

    private function start(Exam $exam): TestResponse
    {
        return $this->withHeaders($this->authHeaders($this->student, $this->tenant))
            ->postJson("/api/v1/exams/{$exam->id}/attempt");
    }

    private function complete(Exam $exam, string $attemptId): TestResponse
    {
        return $this->withHeaders($this->authHeaders($this->student, $this->tenant))
            ->postJson("/api/v1/exams/{$exam->id}/attempts/{$attemptId}/complete");
    }

    public function test_practice_start_removes_timer_anticheat_and_attempt_limit(): void
    {
        $exam = $this->makeExam(practice: true);

        $res = $this->start($exam);

        $res->assertOk()
            ->assertJsonPath('exam.is_practice', true)
            ->assertJsonPath('exam.assessment_mode', 'practice')
            ->assertJsonPath('exam.duration_minutes', null)
            ->assertJsonPath('exam.anti_cheat_enabled', false)
            ->assertJsonPath('deadline', null);

        $attempt = ExamAttempt::findOrFail($res->json('attempt.id'));
        $this->assertSame(Exam::MODE_PRACTICE, $attempt->assessment_mode);
        $this->assertSame('in_progress', $attempt->status);
    }

    public function test_practice_completion_is_persisted_and_idempotent(): void
    {
        $exam = $this->makeExam(practice: true);
        $attemptId = $this->start($exam)->json('attempt.id');

        $first = $this->complete($exam, $attemptId);
        $first->assertOk()->assertJsonPath('already_completed', false);

        $attempt = ExamAttempt::findOrFail($attemptId);
        $this->assertSame('completed', $attempt->status);
        $this->assertNotNull($attempt->completed_at);

        // A duplicate done:true / a retry / a reload returns the same state —
        // never a second record and never an error.
        $second = $this->complete($exam, $attemptId);
        $second->assertOk()->assertJsonPath('already_completed', true);

        $this->assertSame(1, ExamAttempt::where('exam_id', $exam->id)
            ->where('user_id', $this->student->id)->count());
    }

    public function test_submitting_a_practice_exercise_records_completion_not_a_grade(): void
    {
        $exam = $this->makeExam(practice: true);
        $attemptId = $this->start($exam)->json('attempt.id');

        // A client that submits a practice as if it were an exam must not
        // produce a meaningless "Failed 0%".
        $eq = ExamQuestion::where('exam_id', $exam->id)->firstOrFail();
        $res = $this->withHeaders($this->authHeaders($this->student, $this->tenant))
            ->postJson("/api/v1/exams/{$exam->id}/attempts/{$attemptId}/submit", [
                'answers' => [
                    ['exam_question_id' => $eq->id, 'question_id' => $eq->question_id, 'given_answer' => 'x'],
                ],
            ]);

        $res->assertOk()->assertJsonPath('already_completed', false);

        $attempt = ExamAttempt::findOrFail($attemptId);
        $this->assertSame('completed', $attempt->status);
    }

    public function test_a_graded_exam_cannot_be_completed_through_the_practice_endpoint(): void
    {
        $exam = $this->makeExam(practice: false);
        $attemptId = $this->start($exam)->json('attempt.id');

        $this->complete($exam, $attemptId)->assertStatus(422);
        $this->assertSame('in_progress', ExamAttempt::findOrFail($attemptId)->status);
    }

    public function test_practice_attempts_are_repeatable_and_resume_without_burning_slots(): void
    {
        $exam = $this->makeExam(practice: true);

        $firstId = $this->start($exam)->json('attempt.id');
        // A second start resumes the same live exercise (never a new slot).
        $second = $this->start($exam);
        $second->assertOk()->assertJsonPath('resumed', true);
        $this->assertSame($firstId, $second->json('attempt.id'));

        // Complete, then practice again: a brand new attempt is allowed despite
        // max_attempts = 2, because practice is never capped.
        $this->complete($exam, $firstId)->assertOk();
        $again = $this->start($exam);
        $again->assertOk()->assertJsonPath('resumed', false);
    }

    public function test_the_student_results_feed_never_presents_practice_as_a_grade(): void
    {
        $practice = $this->makeExam(practice: true);
        $practiceAttemptId = $this->start($practice)->json('attempt.id');
        $this->complete($practice, $practiceAttemptId)->assertOk();

        // A graded attempt that is still open, plus an ungraded practice.
        $graded = $this->makeExam(practice: false);
        $this->start($graded)->assertOk();

        $res = $this->withHeaders($this->authHeaders($this->student, $this->tenant))
            ->getJson('/api/v1/student/exam-attempts');

        $res->assertOk();

        $practiceRow = collect($res->json('attempts'))->firstWhere('exam_id', $practice->id);
        $this->assertTrue($practiceRow['is_practice']);
        $this->assertSame('completed', $practiceRow['status']);
        $this->assertNull($practiceRow['percentage']);
        $this->assertNull($practiceRow['passed']);

        $openRow = collect($res->json('attempts'))->firstWhere('exam_id', $graded->id);
        $this->assertSame('in_progress', $openRow['status']);
        $this->assertFalse($openRow['is_practice']);
    }

    public function test_practice_is_excluded_from_graded_aggregates(): void
    {
        $exam = $this->makeExam(practice: true);

        ExamAttempt::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'exam_id' => $exam->id, 'user_id' => $this->student->id,
            'attempt_number' => 1, 'status' => 'completed',
            'assessment_mode' => Exam::MODE_PRACTICE, 'percentage' => 0, 'passed' => false,
            'started_at' => now(),
        ]);
        ExamAttempt::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'exam_id' => $exam->id, 'user_id' => $this->student->id,
            'attempt_number' => 2, 'status' => 'submitted',
            'assessment_mode' => Exam::MODE_GRADED, 'percentage' => 80, 'passed' => true,
            'started_at' => now(),
        ]);

        $this->assertSame(1, ExamAttempt::where('tenant_id', $this->tenant->id)->excludePractice()->count());
    }
}
