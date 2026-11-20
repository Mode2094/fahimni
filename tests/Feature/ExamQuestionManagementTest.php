<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Section;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Uid\UuidV7;

/**
 * The unified exam editor attaches, reorders and detaches questions through the
 * exam_questions pivot. These tests lock in the data-safety rules behind it:
 * reordering persists, attaching appends, detaching never deletes the question
 * (or its use in other exams), and a question that already has graded answers
 * cannot be detached (which would cascade-delete those answers).
 */
class ExamQuestionManagementTest extends TenantTestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $teacher;
    private Course $course;
    private Section $section;
    private Exam $exam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant();
        $this->teacher = $this->makeUser();
        $this->attachToTenant($this->teacher, $this->tenant, 'teacher');
        $this->attachSubscription($this->tenant);

        $this->course = Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'title' => 'C', 'slug' => 'c-'.UuidV7::generate(), 'is_published' => true,
        ]);
        $this->section = Section::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $this->course->id, 'title' => 'S', 'sort_order' => 0,
        ]);
        $this->exam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $this->course->id, 'section_id' => $this->section->id,
            'title' => 'E', 'pass_mark_percent' => 50, 'max_attempts' => 3,
            'is_published' => true, 'duration_minutes' => 30, 'metadata' => [],
        ]);
    }

    private function makeQuestion(array $attrs = []): Question
    {
        return Question::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $this->tenant->id,
            'question_type' => 'multiple_choice',
            'question_text' => 'Q?',
            'points' => 10,
            'is_active' => true,
            'metadata' => [],
            ...$attrs,
        ]);
    }

    private function attach(Question $question, int $sort = 0, ?Exam $exam = null): ExamQuestion
    {
        return ExamQuestion::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'exam_id' => ($exam ?? $this->exam)->id, 'question_id' => $question->id,
            'sort_order' => $sort, 'points_override' => null,
        ]);
    }

    private function headers(): array
    {
        return $this->authHeaders($this->teacher, $this->tenant);
    }

    public function test_reordering_questions_persists_pivot_order_and_is_returned(): void
    {
        $q1 = $this->makeQuestion(['question_text' => 'first']);
        $q2 = $this->makeQuestion(['question_text' => 'second']);
        $this->attach($q1, 0);
        $this->attach($q2, 1);

        $this->withHeaders($this->headers())
            ->postJson("/api/v1/exams/{$this->exam->id}/questions/reorder", ['question_ids' => [$q2->id, $q1->id]])
            ->assertOk();

        $this->assertDatabaseHas('exam_questions', ['exam_id' => $this->exam->id, 'question_id' => $q2->id, 'sort_order' => 0]);
        $this->assertDatabaseHas('exam_questions', ['exam_id' => $this->exam->id, 'question_id' => $q1->id, 'sort_order' => 1]);

        // The endpoint now returns them in the saved order.
        $res = $this->withHeaders($this->headers())->getJson("/api/v1/exams/{$this->exam->id}/questions");
        $res->assertOk();
        $this->assertSame([$q2->id, $q1->id], collect($res->json('questions'))->pluck('id')->all());
    }

    public function test_attaching_questions_appends_after_the_existing_order(): void
    {
        $q1 = $this->makeQuestion();
        $this->attach($q1, 0);

        $q2 = $this->makeQuestion();
        $this->withHeaders($this->headers())
            ->postJson("/api/v1/exams/{$this->exam->id}/add-questions", ['questions' => [['question_id' => $q2->id]]])
            ->assertOk();

        $this->assertDatabaseHas('exam_questions', ['exam_id' => $this->exam->id, 'question_id' => $q2->id, 'sort_order' => 1]);
    }

    public function test_detaching_a_question_keeps_the_question_and_its_other_exams(): void
    {
        $otherExam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id, 'course_id' => $this->course->id,
            'title' => 'E2', 'pass_mark_percent' => 50, 'max_attempts' => 3, 'is_published' => false,
            'duration_minutes' => 30, 'metadata' => [],
        ]);
        $question = $this->makeQuestion();
        $this->attach($question, 0);
        $this->attach($question, 0, $otherExam);

        $this->withHeaders($this->headers())
            ->deleteJson("/api/v1/exams/{$this->exam->id}/questions/{$question->id}")
            ->assertOk();

        // Gone from THIS exam…
        $this->assertDatabaseMissing('exam_questions', ['exam_id' => $this->exam->id, 'question_id' => $question->id]);
        // …still in the other exam and still in the bank.
        $this->assertDatabaseHas('exam_questions', ['exam_id' => $otherExam->id, 'question_id' => $question->id]);
        $this->assertDatabaseHas('questions', ['id' => $question->id]);
    }

    public function test_detaching_is_refused_when_the_question_has_graded_answers(): void
    {
        $question = $this->makeQuestion();
        $eq = $this->attach($question, 0);

        $student = $this->makeUser();
        $this->attachToTenant($student, $this->tenant, 'student');
        $attempt = ExamAttempt::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id, 'exam_id' => $this->exam->id,
            'user_id' => $student->id, 'attempt_number' => 1, 'status' => 'submitted',
            'started_at' => now(), 'metadata' => [],
        ]);
        ExamAnswer::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'exam_attempt_id' => $attempt->id, 'exam_question_id' => $eq->id, 'question_id' => $question->id,
            'given_answer' => ['x' => 1], 'is_correct' => true, 'points_earned' => 10, 'points_max' => 10,
        ]);

        $this->withHeaders($this->headers())
            ->deleteJson("/api/v1/exams/{$this->exam->id}/questions/{$question->id}")
            ->assertStatus(409);

        // Nothing was destroyed.
        $this->assertDatabaseHas('exam_questions', ['exam_id' => $this->exam->id, 'question_id' => $question->id]);
        $this->assertDatabaseHas('exam_answers', ['exam_question_id' => $eq->id]);
    }

    public function test_updating_a_multiple_choice_question_replaces_its_options(): void
    {
        $question = $this->makeQuestion();
        QuestionOption::create(['id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id, 'question_id' => $question->id, 'option_text' => 'old-a', 'is_correct' => true, 'sort_order' => 0]);
        QuestionOption::create(['id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id, 'question_id' => $question->id, 'option_text' => 'old-b', 'is_correct' => false, 'sort_order' => 1]);

        $this->withHeaders($this->headers())
            ->putJson("/api/v1/questions/{$question->id}", [
                'question_text' => 'updated',
                'options' => [
                    ['option_text' => 'new-a', 'is_correct' => false],
                    ['option_text' => 'new-b', 'is_correct' => true],
                    ['option_text' => 'new-c', 'is_correct' => false],
                ],
            ])
            ->assertOk();

        $options = QuestionOption::where('question_id', $question->id)->get();
        $this->assertCount(3, $options);
        $this->assertSame(['new-a', 'new-b', 'new-c'], $options->sortBy('sort_order')->pluck('option_text')->all());
        $this->assertSame('new-b', $options->firstWhere('is_correct', true)->option_text);
        $this->assertDatabaseMissing('question_options', ['question_id' => $question->id, 'option_text' => 'old-a']);
    }

    public function test_creating_a_true_false_question_honors_the_selected_correct_answer(): void
    {
        $res = $this->withHeaders($this->headers())->postJson('/api/v1/questions', [
            'course_id' => $this->course->id,
            'question_text' => 'The sky is green',
            'question_type' => 'true_false',
            'points' => 1,
            'options' => [
                ['option_text' => 'True', 'is_correct' => false],
                ['option_text' => 'False', 'is_correct' => true],
            ],
        ]);
        $res->assertCreated();

        $id = $res->json('question.id');
        $correct = QuestionOption::where('question_id', $id)->where('is_correct', true)->first();
        $this->assertSame('False', $correct?->option_text);
    }
}
