<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Question;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Uid\UuidV7;

/**
 * A reload, a dropped connection or a double click must never burn an extra
 * attempt: the API resumes the SAME live attempt, keeps its original clock, and
 * restores whatever was autosaved — while a genuinely timed-out attempt is
 * still auto-submitted and a submitted attempt still opens a new one.
 */
class ExamAttemptResumeTest extends TenantTestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $student;
    private Exam $exam;
    private ExamQuestion $mcQuestion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant();
        $this->attachSubscription($this->tenant);

        $this->student = $this->makeUser();
        $this->attachToTenant($this->student, $this->tenant, 'student');

        $course = Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'title' => 'Physics', 'slug' => 'physics-'.UuidV7::generate(), 'is_published' => true,
        ]);
        Enrollment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $course->id, 'user_id' => $this->student->id,
            'status' => 'active', 'progress_percent' => 0,
            'enrolled_at' => now(), 'source' => 'manual', 'metadata' => [],
        ]);
        $this->exam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $course->id, 'title' => 'Quiz', 'pass_mark_percent' => 50,
            'max_attempts' => 2, 'is_published' => true, 'duration_minutes' => 30, 'metadata' => [],
        ]);

        $q = Question::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'question_type' => 'multiple_choice', 'question_text' => 'Q1',
            'points' => 5, 'is_active' => true, 'metadata' => [],
        ]);
        $this->mcQuestion = ExamQuestion::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'exam_id' => $this->exam->id, 'question_id' => $q->id,
            'sort_order' => 0, 'points_override' => null,
        ]);
    }

    private function start(): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->authHeaders($this->student, $this->tenant))
            ->postJson("/api/v1/exams/{$this->exam->id}/attempt");
    }

    public function test_a_second_start_resumes_the_same_attempt_instead_of_burning_a_slot(): void
    {
        $first = $this->start();
        $first->assertOk();
        $firstAttemptId = $first->json('attempt.id');
        $this->assertFalse($first->json('resumed'));

        $second = $this->start();
        $second->assertOk();
        $this->assertTrue($second->json('resumed'));
        $this->assertSame($firstAttemptId, $second->json('attempt.id'));

        // Still exactly ONE attempt row for the student.
        $this->assertSame(1, ExamAttempt::where('exam_id', $this->exam->id)
            ->where('user_id', $this->student->id)->count());
    }

    public function test_a_resumed_attempt_keeps_its_original_deadline(): void
    {
        $this->start()->assertOk();

        // Pretend the attempt started five minutes ago.
        $attempt = ExamAttempt::where('user_id', $this->student->id)->firstOrFail();
        $attempt->update(['started_at' => now()->subMinutes(5)]);

        $resumed = $this->start();
        $resumed->assertOk();

        $expected = $attempt->fresh()->started_at->copy()->addMinutes(30);
        $actual = \Illuminate\Support\Carbon::parse($resumed->json('deadline'));
        // The deadline follows the real start, not a fresh 30-minute window.
        $this->assertLessThanOrEqual(2, abs($expected->diffInSeconds($actual)));
    }

    public function test_autosaved_answers_come_back_on_resume_and_do_not_duplicate_on_submit(): void
    {
        $attemptId = $this->start()->json('attempt.id');

        $this->withHeaders($this->authHeaders($this->student, $this->tenant))
            ->postJson("/api/v1/exams/{$this->exam->id}/attempts/{$attemptId}/autosave", [
                'answers' => [
                    ['exam_question_id' => $this->mcQuestion->id, 'given_answer' => ['opt-a']],
                ],
            ])
            ->assertOk();

        $resumed = $this->start();
        $resumed->assertOk();
        $this->assertTrue($resumed->json('resumed'));
        $this->assertSame(['opt-a'], $resumed->json("saved_answers.{$this->mcQuestion->id}"));

        // Autosaving again replaces (never duplicates) the draft.
        $this->withHeaders($this->authHeaders($this->student, $this->tenant))
            ->postJson("/api/v1/exams/{$this->exam->id}/attempts/{$attemptId}/autosave", [
                'answers' => [
                    ['exam_question_id' => $this->mcQuestion->id, 'given_answer' => ['opt-b']],
                ],
            ])
            ->assertOk();
        $this->assertSame(1, ExamAnswer::where('exam_attempt_id', $attemptId)->count());

        // The real submit replaces the drafts with the graded set (no dupes).
        $this->withHeaders($this->authHeaders($this->student, $this->tenant))
            ->postJson("/api/v1/exams/{$this->exam->id}/attempts/{$attemptId}/submit", [
                'answers' => [
                    [
                        'exam_question_id' => $this->mcQuestion->id,
                        'question_id' => $this->mcQuestion->question_id,
                        'given_answer' => ['opt-b'],
                        'time_spent_seconds' => 20,
                    ],
                ],
            ])
            ->assertOk();

        $this->assertSame(1, ExamAnswer::where('exam_attempt_id', $attemptId)->count());
    }

    public function test_another_student_cannot_autosave_someone_elses_attempt(): void
    {
        $attemptId = $this->start()->json('attempt.id');

        $intruder = $this->makeUser();
        $this->attachToTenant($intruder, $this->tenant, 'student');

        $this->withHeaders($this->authHeaders($intruder, $this->tenant))
            ->postJson("/api/v1/exams/{$this->exam->id}/attempts/{$attemptId}/autosave", [
                'answers' => [],
            ])
            ->assertNotFound();
    }

    public function test_a_timed_out_attempt_is_auto_submitted_and_a_new_one_can_start(): void
    {
        $firstId = $this->start()->json('attempt.id');
        ExamAttempt::whereKey($firstId)->update(['started_at' => now()->subMinutes(45)]);

        // The stale attempt is closed and the student gets a fresh one.
        $second = $this->start();
        $second->assertOk();
        $this->assertFalse($second->json('resumed'));
        $this->assertNotSame($firstId, $second->json('attempt.id'));

        $this->assertSame('submitted', ExamAttempt::findOrFail($firstId)->status);
    }
}
