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
 * منع الغش: العدّاد والحكم على الخادم.
 *
 * Violations are browser-sensed events (tab hidden, window blurred, copy), but
 * the COUNT and the AUTO-SUBMIT DECISION belong to the server: the count is
 * monotonic (a reload/replay can never reset it) and the threshold is the
 * server's constant, never a client guess. Practice exercises are never
 * proctored. These tests lock that down and prove a client cannot talk the
 * count back down at submit.
 */
class ExamAntiCheatViolationTest extends TenantTestCase
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
            'course_id' => $this->course->id, 'title' => $practice ? 'Practice' : 'Unit exam',
            'pass_mark_percent' => 50, 'max_attempts' => 3, 'is_published' => true,
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

    private function violation(Exam $exam, string $attemptId): TestResponse
    {
        return $this->withHeaders($this->authHeaders($this->student, $this->tenant))
            ->postJson("/api/v1/exams/{$exam->id}/attempts/{$attemptId}/violation");
    }

    public function test_a_graded_exam_exposes_the_server_threshold(): void
    {
        $exam = $this->makeExam(practice: false);

        $this->start($exam)
            ->assertOk()
            ->assertJsonPath('exam.anti_cheat_violation_limit', Exam::ANTI_CHEAT_VIOLATION_LIMIT);
    }

    public function test_violations_are_counted_server_side_and_monotonically(): void
    {
        $exam = $this->makeExam(practice: false);
        $attemptId = $this->start($exam)->json('attempt.id');

        $this->violation($exam, $attemptId)
            ->assertOk()
            ->assertJsonPath('violations', 1)
            ->assertJsonPath('limit', Exam::ANTI_CHEAT_VIOLATION_LIMIT)
            ->assertJsonPath('should_submit', false);

        $second = $this->violation($exam, $attemptId);
        $second->assertOk()->assertJsonPath('violations', 2)->assertJsonPath('should_submit', false);

        // The count lives on the attempt itself, so it survives a reload.
        $this->assertSame(2, (int) ExamAttempt::findOrFail($attemptId)->metadata['anti_cheat_violations']);

        // Reaching the threshold is decided by the server.
        $third = $this->violation($exam, $attemptId);
        $third->assertOk()
            ->assertJsonPath('violations', Exam::ANTI_CHEAT_VIOLATION_LIMIT)
            ->assertJsonPath('should_submit', true);

        $this->assertNotNull(ExamAttempt::findOrFail($attemptId)->metadata['anti_cheat_forced_submit_at'] ?? null);
    }

    public function test_a_client_cannot_lower_the_server_recorded_count_at_submit(): void
    {
        $exam = $this->makeExam(practice: false);
        $attemptId = $this->start($exam)->json('attempt.id');

        $this->violation($exam, $attemptId);
        $this->violation($exam, $attemptId);

        $eq = ExamQuestion::where('exam_id', $exam->id)->firstOrFail();

        // The client claims zero violations; the server keeps its own count.
        $this->withHeaders($this->authHeaders($this->student, $this->tenant))
            ->postJson("/api/v1/exams/{$exam->id}/attempts/{$attemptId}/submit", [
                'answers' => [
                    ['exam_question_id' => $eq->id, 'question_id' => $eq->question_id, 'given_answer' => 'x'],
                ],
                'anti_cheat_violations' => 0,
            ])
            ->assertOk();

        $this->assertSame(2, (int) ExamAttempt::findOrFail($attemptId)->metadata['anti_cheat_violations']);
    }

    public function test_a_practice_exercise_is_never_proctored(): void
    {
        $exam = $this->makeExam(practice: true);
        $attemptId = $this->start($exam)->json('attempt.id');

        $this->start($exam)->assertOk()->assertJsonPath('exam.anti_cheat_violation_limit', 0);

        $this->violation($exam, $attemptId)
            ->assertOk()
            ->assertJsonPath('violations', 0)
            ->assertJsonPath('limit', 0)
            ->assertJsonPath('should_submit', false);

        $this->assertNull(ExamAttempt::findOrFail($attemptId)->metadata['anti_cheat_violations'] ?? null);
    }
}
