<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Question;
use App\Models\Section;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Uid\UuidV7;

/**
 * The teacher review list must show every student's attempt for their exam.
 * Previously `ExamAttemptController::index()` narrowed every non-super-admin
 * to `where('user_id', self)`, so a teacher saw an empty list (their own
 * user_id never appears on student attempts). These tests lock the correct
 * behaviour in: staff see all attempts in their tenant, a student still sees
 * only their own, and another tenant's teacher sees nothing.
 */
class ExamAttemptReviewTest extends TenantTestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $teacher;
    private User $otherTeacher;
    private User $studentA;
    private User $studentB;
    private Course $course;
    private Section $section;
    private Exam $exam;
    private Question $skillQuestion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant();
        $this->teacher = $this->makeUser();
        $this->attachToTenant($this->teacher, $this->tenant, 'teacher');
        $this->attachSubscription($this->tenant);

        // A second, unrelated tenant whose teacher must never see these rows.
        $otherTenant = $this->makeTenant();
        $this->otherTeacher = $this->makeUser();
        $this->attachToTenant($this->otherTeacher, $otherTenant, 'teacher');
        $this->attachSubscription($otherTenant);

        $this->course = Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'title' => 'Physics 101', 'slug' => 'physics-'.UuidV7::generate(), 'is_published' => true,
        ]);
        $this->section = Section::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $this->course->id, 'title' => 'Unit 1 — Motion', 'sort_order' => 0,
        ]);
        $this->exam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $this->course->id, 'section_id' => $this->section->id,
            'title' => 'Quiz 1', 'pass_mark_percent' => 50, 'max_attempts' => 3,
            'is_published' => true, 'duration_minutes' => 30, 'metadata' => [],
        ]);

        $this->studentA = $this->makeUser();
        $this->attachToTenant($this->studentA, $this->tenant, 'student');
        $this->studentB = $this->makeUser();
        $this->attachToTenant($this->studentB, $this->tenant, 'student');

        foreach ([$this->studentA, $this->studentB] as $student) {
            Enrollment::create([
                'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
                'course_id' => $this->course->id, 'user_id' => $student->id,
                'status' => 'active', 'progress_percent' => 0,
                'enrolled_at' => now(), 'source' => 'manual', 'metadata' => [],
            ]);
        }

        // Two questions, one carrying a skill so performance can be derived.
        $plain = Question::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'question_type' => 'multiple_choice', 'question_text' => 'Plain',
            'points' => 2, 'is_active' => true, 'metadata' => [],
        ]);
        $this->skillQuestion = Question::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'question_type' => 'multiple_choice', 'question_text' => 'Skilled',
            'points' => 8, 'is_active' => true,
            'metadata' => ['skills' => ['Density', 'Graphing']],
        ]);

        foreach ([$plain, $this->skillQuestion] as $i => $q) {
            ExamQuestion::create([
                'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
                'exam_id' => $this->exam->id, 'question_id' => $q->id,
                'sort_order' => $i, 'points_override' => null,
            ]);
        }
    }

    private function makeAttempt(User $student, float $earnedSkill, float $maxSkill, ?float $skillCorrect): ExamAttempt
    {
        $attempt = ExamAttempt::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'exam_id' => $this->exam->id, 'user_id' => $student->id,
            'attempt_number' => 1, 'status' => 'submitted',
            'score' => $earnedSkill, 'total_points' => 10, 'percentage' => $earnedSkill * 10,
            'passed' => false, 'needs_review' => true,
            'started_at' => now()->subMinutes(12), 'submitted_at' => now(), 'time_spent_seconds' => 700,
        ]);

        ExamAnswer::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'exam_attempt_id' => $attempt->id,
            'exam_question_id' => ExamQuestion::where('question_id', $this->skillQuestion->id)->value('id'),
            'question_id' => $this->skillQuestion->id,
            'given_answer' => ['answer' => 'A'], 'is_correct' => $skillCorrect,
            'points_earned' => $earnedSkill, 'points_max' => $maxSkill, 'time_spent_seconds' => 700,
        ]);

        return $attempt;
    }

    public function test_teacher_sees_every_students_attempt_for_their_exam(): void
    {
        $this->makeAttempt($this->studentA, 8, 8, true);
        $this->makeAttempt($this->studentB, 4, 8, false);

        $res = $this->withHeaders($this->authHeaders($this->teacher, $this->tenant))
            ->getJson("/api/v1/exams/{$this->exam->id}/attempts");

        $res->assertOk();
        $attempts = $res->json('attempts');
        $this->assertCount(2, $attempts, 'teacher must see both students, not an empty list');

        $studentIds = array_column(array_column($attempts, 'student'), 'id');
        $this->assertContains($this->studentA->id, $studentIds);
        $this->assertContains($this->studentB->id, $studentIds);
    }

    public function test_student_sees_only_their_own_attempts(): void
    {
        $mine = $this->makeAttempt($this->studentA, 8, 8, true);
        $this->makeAttempt($this->studentB, 4, 8, false);

        $res = $this->withHeaders($this->authHeaders($this->studentA, $this->tenant))
            ->getJson("/api/v1/exams/{$this->exam->id}/attempts");

        $res->assertOk();
        $attempts = $res->json('attempts');
        $this->assertCount(1, $attempts);
        $this->assertSame($mine->id, $attempts[0]['id']);
        // A student must never receive the question/answer key payload.
        $this->assertArrayNotHasKey('question', $attempts[0]['answers'][0]);
        $this->assertArrayNotHasKey('is_correct', $attempts[0]['answers'][0]);
    }

    public function test_another_tenants_teacher_cannot_read_the_attempts(): void
    {
        $this->makeAttempt($this->studentA, 8, 8, true);

        $res = $this->withHeaders($this->authHeaders($this->otherTeacher, $this->makeTenant()))
            ->getJson("/api/v1/exams/{$this->exam->id}/attempts");

        // The exam is invisible to the foreign tenant → 403/404, never a leak.
        $this->assertContains($res->status(), [403, 404]);
    }

    public function test_teacher_receives_skill_performance_and_manual_review_count(): void
    {
        $this->makeAttempt($this->studentA, 6, 8, null);

        $res = $this->withHeaders($this->authHeaders($this->teacher, $this->tenant))
            ->getJson("/api/v1/exams/{$this->exam->id}/attempts");

        $res->assertOk();
        $attempt = $res->json('attempts.0');

        $this->assertSame('Physics 101', $attempt['course']['title']);
        $this->assertSame('Unit 1 — Motion', $attempt['section']['title']);
        $this->assertSame(1, $attempt['manual_review_count'], 'the null verdict is queued for manual review');

        $skills = collect($attempt['skills'])->keyBy('name');
        $this->assertSame(75.0, (float) $skills['Density']['accuracy']);
        $this->assertSame('proficient', $skills['Density']['level']);
        $this->assertSame('Graphing', $skills['Graphing']['name']);
    }

    public function test_teacher_can_preview_an_unpublished_exam_without_recording_an_attempt(): void
    {
        $this->exam->update(['is_published' => false]);

        // An interactive question carrying a hidden answer key.
        $html = Question::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'question_type' => 'custom_html', 'question_text' => 'HTML',
            'points' => 5, 'is_active' => true,
            'metadata' => [
                'interactive' => ['html' => '<b>x</b>', 'css' => '', 'js' => 'FahimniQuestion.setAnswer(3)', 'height' => 400, 'auto_capture' => true],
                'grading' => ['mode' => 'auto', 'rule' => 'numeric', 'key' => 3],
            ],
        ]);
        ExamQuestion::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'exam_id' => $this->exam->id, 'question_id' => $html->id,
            'sort_order' => 2, 'points_override' => null,
        ]);

        $res = $this->withHeaders($this->authHeaders($this->teacher, $this->tenant))
            ->postJson("/api/v1/exams/{$this->exam->id}/preview");

        $res->assertOk();
        $this->assertTrue($res->json('preview'));
        $this->assertNull($res->json('attempt'));

        $questions = collect($res->json('questions'))->keyBy('question_id');
        $payload = $questions[$html->id];
        // The display payload is present, the grading key is NOT.
        $this->assertSame('<b>x</b>', $payload['metadata']['interactive']['html']);
        $this->assertSame(400, $payload['metadata']['interactive']['height']);
        $this->assertArrayNotHasKey('grading', $payload['metadata']);

        // Nothing was persisted and no attempt slot was burned.
        $this->assertSame(0, ExamAttempt::where('exam_id', $this->exam->id)->count());
    }

    public function test_preview_grading_runs_the_auto_grader_without_persisting(): void
    {
        $res = $this->withHeaders($this->authHeaders($this->teacher, $this->tenant))
            ->postJson("/api/v1/exams/{$this->exam->id}/preview/grade", [
                'answers' => [
                    ['exam_question_id' => ExamQuestion::where('question_id', $this->skillQuestion->id)->value('id'), 'given_answer' => 'wrong'],
                ],
            ]);

        $res->assertOk();
        $this->assertTrue($res->json('preview'));
        $this->assertFalse($res->json('result.passed'));
        $this->assertSame(0, ExamAnswer::count(), 'preview grading must not write answers');
    }

    public function test_student_cannot_call_the_preview_endpoints(): void
    {
        $res = $this->withHeaders($this->authHeaders($this->studentA, $this->tenant))
            ->postJson("/api/v1/exams/{$this->exam->id}/preview");
        $res->assertForbidden();

        $grade = $this->withHeaders($this->authHeaders($this->studentA, $this->tenant))
            ->postJson("/api/v1/exams/{$this->exam->id}/preview/grade", ['answers' => []]);
        $grade->assertForbidden();
    }
}
