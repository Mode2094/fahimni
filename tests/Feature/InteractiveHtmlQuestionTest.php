<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamQuestion;
use App\Models\Question;
use App\Models\Section;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Uid\UuidV7;

/**
 * Covers the interactive HTML question type end to end: authoring validation,
 * the student-safe allowlist (no answer key ever leaks), server-side auto
 * grading across every rule, manual review, oversized/forged answers, tenant
 * isolation, and term-bound attempts. The eight pre-existing types must keep
 * behaving exactly as before.
 */
class InteractiveHtmlQuestionTest extends TenantTestCase
{
    use RefreshDatabase;

    private ?Tenant $memoTenant = null;

    private function tenant(): Tenant
    {
        return $this->memoTenant ??= $this->makeTenant();
    }

    private function teacher(Tenant $tenant) {
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        return $teacher;
    }

    private function studentWithCourse(Tenant $tenant, string $slug = 'c'): array
    {
        $course = Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'title' => 'Course', 'slug' => $slug.'-'.str()->random(4), 'is_published' => true,
        ]);
        $section = Section::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'course_id' => $course->id, 'title' => 'S', 'sort_order' => 0,
        ]);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        return [$course, $section, $student];
    }

    /** @param array<int, array{0:Question,1:int}> $questions */
    private function examWith(Tenant $tenant, Course $course, Section $section, array $questions, int $passMark = 50): Exam
    {
        $exam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'section_id' => $section->id, 'title' => 'E', 'pass_mark_percent' => $passMark,
            'max_attempts' => 0, 'is_published' => true, 'duration_minutes' => 30, 'metadata' => [],
        ]);

        foreach ($questions as $i => [$question, $points]) {
            ExamQuestion::create([
                'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'exam_id' => $exam->id,
                'question_id' => $question->id, 'sort_order' => $i, 'points_override' => $points,
            ]);
        }

        return $exam;
    }

    private function interactive(Tenant $tenant, array $grading, string $html = '<div id="q">pick</div>', string $js = ''): Question
    {
        return Question::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'question_type' => Question::TYPE_INTERACTIVE_HTML,
            'question_text' => 'Interactive', 'points' => 10, 'is_active' => true,
            'metadata' => [
                'interactive' => ['html' => $html, 'css' => 'body{margin:0}', 'js' => $js, 'height' => 300, 'auto_capture' => true],
                'grading' => $grading,
            ],
        ]);
    }

    private function enroll(Tenant $tenant, Course $course, $student, ?string $termId = null): Enrollment
    {
        return Enrollment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now(),
            'academic_term_id' => $termId,
        ]);
    }

    // ─────────────────────────── (1) authoring validation ───────────────────────────

    public function test_teacher_can_create_and_update_an_interactive_html_question(): void
    {
        $tenant = $this->tenant();
        $this->attachSubscription($tenant);
        $teacher = $this->teacher($tenant);
        $headers = $this->authHeaders($teacher, $tenant);

        $payload = [
            'question_type' => 'interactive_html',
            'question_text' => 'Click the correct point',
            'points' => 5,
            'metadata' => [
                'image_url' => 'https://cdn.example/x.png',
                'interactive' => ['html' => '<svg id="s"></svg>', 'css' => 'svg{width:100%}', 'js' => 'FahimniQuestion.setAnswer(3)', 'height' => 320, 'auto_capture' => true],
                'grading' => ['mode' => 'auto', 'rule' => 'single_choice', 'key' => 3],
            ],
        ];

        $create = $this->withHeaders($headers)->postJson('/api/v1/questions', $payload);
        $create->assertCreated();
        $id = $create->json('question.id');

        $this->assertDatabaseHas('questions', ['id' => $id, 'question_type' => 'interactive_html']);
        $stored = Question::withoutTenant()->findOrFail($id);
        $this->assertSame('<svg id="s"></svg>', $stored->metadata['interactive']['html']);
        $this->assertSame(3, $stored->metadata['grading']['key']);

        // Update must persist the whole metadata payload (including nested keys
        // that carry no rule of their own, like image_url) — not just the
        // validated subset.
        $update = $this->withHeaders($headers)->putJson("/api/v1/questions/{$id}", [
            'question_type' => 'interactive_html',
            'question_text' => 'Updated',
            'metadata' => [
                'image_url' => 'https://cdn.example/y.png',
                'interactive' => ['html' => '<svg id="s2"></svg>', 'css' => '', 'js' => 'FahimniQuestion.setAnswer(4)', 'height' => 400, 'auto_capture' => false],
                'grading' => ['mode' => 'auto', 'rule' => 'numeric', 'key' => 4, 'tolerance' => 0.5],
            ],
        ]);
        $update->assertOk();

        $stored = Question::withoutTenant()->findOrFail($id);
        // `html`/`css`/`js` may be stored as-is; JSONB round-trips '' faithfully.
        $this->assertSame('<svg id="s2"></svg>', $stored->metadata['interactive']['html']);
        $this->assertSame('https://cdn.example/y.png', $stored->metadata['image_url']);
        $this->assertSame('numeric', $stored->metadata['grading']['rule']);
        $this->assertSame(4, $stored->metadata['grading']['key']);
    }

    public function test_interactive_question_requires_a_payload_and_a_key_when_auto_graded(): void
    {
        $tenant = $this->tenant();
        $this->attachSubscription($tenant);
        $headers = $this->authHeaders($this->teacher($tenant), $tenant);

        // Missing the interactive payload entirely.
        $this->withHeaders($headers)->postJson('/api/v1/questions', [
            'question_type' => 'interactive_html',
            'question_text' => 'x',
            'metadata' => ['grading' => ['mode' => 'manual']],
        ])->assertStatus(422)->assertJsonValidationErrors('metadata.interactive.html');

        // Claims auto grading but defines no hidden key.
        $this->withHeaders($headers)->postJson('/api/v1/questions', [
            'question_type' => 'interactive_html',
            'question_text' => 'x',
            'metadata' => [
                'interactive' => ['html' => '<div></div>'],
                'grading' => ['mode' => 'auto', 'rule' => 'single_choice'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('metadata.grading.key');

        // Unknown rule.
        $this->withHeaders($headers)->postJson('/api/v1/questions', [
            'question_type' => 'interactive_html',
            'question_text' => 'x',
            'metadata' => [
                'interactive' => ['html' => '<div></div>'],
                'grading' => ['mode' => 'auto', 'rule' => 'telepathy', 'key' => 1],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('metadata.grading.rule');
    }

    public function test_unknown_question_type_is_rejected(): void
    {
        $tenant = $this->tenant();
        $this->attachSubscription($tenant);
        $headers = $this->authHeaders($this->teacher($tenant), $tenant);

        $this->withHeaders($headers)->postJson('/api/v1/questions', [
            'question_type' => 'interactive_html_evil',
            'question_text' => 'x',
        ])->assertStatus(422)->assertJsonValidationErrors('question_type');
    }

    // ─────────────────────────── (2) no answer-key leak ───────────────────────────

    public function test_start_payload_never_leaks_the_interactive_answer_key(): void
    {
        $tenant = $this->tenant();
        $this->attachSubscription($tenant);
        [$course, $section, $student] = $this->studentWithCourse($tenant);
        $this->enroll($tenant, $course, $student);

        $question = $this->interactive(
            $tenant,
            ['mode' => 'auto', 'rule' => 'single_choice', 'key' => 'TOP_SECRET_KEY'],
            '<div id="q">secret html</div>',
            'FahimniQuestion.setAnswer("student value")'
        );
        $exam = $this->examWith($tenant, $course, $section, [[$question, 10]]);

        $response = $this->withHeaders($this->authHeaders($student, $tenant))
            ->postJson("/api/v1/exams/{$exam->id}/attempt");
        $response->assertOk();

        $q = collect($response->json('questions'))->firstWhere('question_type', 'interactive_html');
        $this->assertArrayHasKey('interactive', $q['metadata']);
        $this->assertArrayNotHasKey('grading', $q['metadata']);
        $this->assertSame('<div id="q">secret html</div>', $q['metadata']['interactive']['html']);

        // The hidden key must not appear anywhere in the serialized response.
        $this->assertStringNotContainsString('TOP_SECRET_KEY', $response->getContent());
    }

    public function test_student_question_resource_never_exposes_metadata(): void
    {
        $tenant = $this->tenant();
        $this->attachSubscription($tenant);
        [$course, $section, $student] = $this->studentWithCourse($tenant);

        $question = $this->interactive($tenant, ['mode' => 'auto', 'rule' => 'single_choice', 'key' => 'HIDDEN']);
        $exam = $this->examWith($tenant, $course, $section, [[$question, 10]]);

        // A student who can view the exam must not receive raw metadata from the
        // QuestionResource (only the safe attempt payload does).
        $response = $this->withHeaders($this->authHeaders($student, $tenant))
            ->getJson("/api/v1/exams/{$exam->id}/questions");
        // Students are not staff, so the endpoint is forbidden; assert that.
        $response->assertForbidden();
        $this->assertStringNotContainsString('HIDDEN', $response->getContent());
    }

    // ─────────────────────────── (3) auto grading ───────────────────────────

    public function test_auto_grading_evaluates_every_rule_server_side(): void
    {
        $tenant = $this->tenant();
        $this->attachSubscription($tenant);
        [$course, $section, $student] = $this->studentWithCourse($tenant);
        $this->enroll($tenant, $course, $student);

        $cases = [
            // [grading, given, expectCorrect|null]
            [['mode' => 'auto', 'rule' => 'single_choice', 'key' => 'A'], 'a', true],
            [['mode' => 'auto', 'rule' => 'numeric', 'key' => 3.14, 'tolerance' => 0.01], '3.141', true],
            [['mode' => 'auto', 'rule' => 'numeric', 'key' => 3.14, 'tolerance' => 0.001], '3.5', false],
            [['mode' => 'auto', 'rule' => 'exact_set', 'key' => ['x', 'y', 'z']], ['z', 'x', 'y'], true],
            [['mode' => 'auto', 'rule' => 'ordered', 'key' => ['1', '2', '3']], ['1', '2', '3'], true],
            [['mode' => 'auto', 'rule' => 'ordered', 'key' => ['1', '2', '3']], ['1', '3', '2'], false],
            [['mode' => 'auto', 'rule' => 'json_equals', 'key' => ['a' => 1, 'b' => 2]], ['b' => 2, 'a' => 1], true],
            [['mode' => 'auto', 'rule' => 'contains_all', 'key' => ['green', 'blue']], ['red', 'blue', 'green'], true],
            [['mode' => 'auto', 'rule' => 'contains_all', 'key' => ['green', 'blue']], ['green'], false],
            [['mode' => 'manual'], ['anything' => 1], null],
        ];

        $examQuestions = [];
        $questions = [];
        foreach ($cases as $i => [$grading, $given, $expect]) {
            $q = $this->interactive($tenant, $grading, "<div>q{$i}</div>");
            $questions[$i] = $q;
            $examQuestions[$i] = [$q, 10];
        }

        $exam = $this->examWith($tenant, $course, $section, $examQuestions, 0);

        $headers = $this->authHeaders($student, $tenant);
        $start = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempt");
        $attemptId = $start->json('attempt.id');
        $this->assertNotNull($attemptId);

        // Map each submitted exam_question by its question id.
        $byQuestion = collect($start->json('questions'))->keyBy('question_id');
        $answers = [];
        foreach ($cases as $i => [$grading, $given]) {
            $answers[] = [
                'exam_question_id' => $byQuestion[$questions[$i]->id]['exam_question_id'],
                'question_id' => $questions[$i]->id,
                'given_answer' => is_string($given) ? $given : json_encode($given),
            ];
        }

        $submit = $this->withHeaders($headers)->postJson(
            "/api/v1/exams/{$exam->id}/attempts/{$attemptId}/submit",
            ['answers' => $answers]
        );
        $submit->assertOk();

        // 6 correct out of 10 → 60/100.
        $this->assertEquals(60.0, (float) $submit->json('result.score'));
        $this->assertTrue($submit->json('result.needs_review'));
        $this->assertFalse($submit->json('result.passed'));

        // Per-answer server-side verdicts.
        foreach ($cases as $i => [$grading, $given, $expect]) {
            $row = ExamAnswer::withoutTenant()
                ->where('tenant_id', $tenant->id)
                ->where('question_id', $questions[$i]->id)
                ->firstOrFail();

            if ($expect === null) {
                $this->assertNull($row->is_correct, "case {$i} should be left for manual review");
                $this->assertEquals(0.0, (float) $row->points_earned);
            } else {
                $this->assertSame($expect, $row->is_correct, "case {$i} verdict");
                $this->assertEquals($expect ? 10.0 : 0.0, (float) $row->points_earned);
            }
        }
    }

    public function test_attempt_view_after_submit_never_leaks_the_interactive_key(): void
    {
        $tenant = $this->tenant();
        $this->attachSubscription($tenant);
        [$course, $section, $student] = $this->studentWithCourse($tenant);
        $this->enroll($tenant, $course, $student);

        $question = $this->interactive(
            $tenant,
            ['mode' => 'auto', 'rule' => 'single_choice', 'key' => 'TOP_SECRET_KEY'],
            '<div>q</div>'
        );

        // Even with "show correct answers" enabled, the hidden grading rule must
        // not be serialized to the student's attempt view (the question relation
        // is staff-only in ExamAnswerResource).
        $exam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'section_id' => $section->id, 'title' => 'E', 'pass_mark_percent' => 50,
            'max_attempts' => 0, 'is_published' => true, 'duration_minutes' => 30, 'metadata' => [],
            'show_correct_answers' => true, 'show_result_immediately' => true,
        ]);
        ExamQuestion::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'exam_id' => $exam->id,
            'question_id' => $question->id, 'sort_order' => 0, 'points_override' => 10,
        ]);

        $headers = $this->authHeaders($student, $tenant);
        $start = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempt");
        $attemptId = $start->json('attempt.id');
        $eq = $start->json('questions.0.exam_question_id');

        $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempts/{$attemptId}/submit", [
            'answers' => [['exam_question_id' => $eq, 'question_id' => $question->id, 'given_answer' => 'WRONG_ANSWER']],
        ])->assertOk();

        $show = $this->withHeaders($headers)->getJson("/api/v1/exam-attempts/{$attemptId}");
        $show->assertOk();
        $this->assertStringNotContainsString('TOP_SECRET_KEY', $show->getContent());
        $this->assertStringNotContainsString('grading', $show->getContent());
    }

    public function test_manual_grading_requires_a_teacher_and_updates_the_attempt(): void
    {
        $tenant = $this->tenant();
        $this->attachSubscription($tenant);
        [$course, $section, $student] = $this->studentWithCourse($tenant);
        $this->enroll($tenant, $course, $student);

        $question = $this->interactive($tenant, ['mode' => 'manual'], '<div>sim</div>');
        $exam = $this->examWith($tenant, $course, $section, [[$question, 10]], 50);

        $headers = $this->authHeaders($student, $tenant);
        $start = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempt");
        $attemptId = $start->json('attempt.id');
        $eq = $start->json('questions.0.exam_question_id');

        $submit = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempts/{$attemptId}/submit", [
            'answers' => [['exam_question_id' => $eq, 'question_id' => $question->id, 'given_answer' => json_encode(['temp' => 80])]],
        ]);
        $submit->assertOk();
        $this->assertTrue($submit->json('result.needs_review'));
        $this->assertFalse($submit->json('result.passed'));

        $answerId = ExamAnswer::withoutTenant()->where('question_id', $question->id)->value('id');

        $teacher = $this->teacher($tenant);
        $grade = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson("/api/v1/exams/{$exam->id}/attempts/{$attemptId}/grade", [
                'answers' => [['exam_answer_id' => $answerId, 'points_earned' => 10]],
            ]);
        $grade->assertOk();
        $this->assertEquals(100.0, (float) $grade->json('attempt.percentage'));
        $this->assertTrue($grade->json('attempt.passed'));
    }

    public function test_oversized_and_forged_answers_are_rejected_or_failed(): void
    {
        $tenant = $this->tenant();
        $this->attachSubscription($tenant);
        [$course, $section, $student] = $this->studentWithCourse($tenant);
        $this->enroll($tenant, $course, $student);

        $question = $this->interactive($tenant, ['mode' => 'auto', 'rule' => 'single_choice', 'key' => 'A']);
        $exam = $this->examWith($tenant, $course, $section, [[$question, 10]], 0);

        $headers = $this->authHeaders($student, $tenant);

        // Oversized answer → 422, nothing stored.
        $start = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempt");
        $attemptId = $start->json('attempt.id');
        $eq = $start->json('questions.0.exam_question_id');

        $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempts/{$attemptId}/submit", [
            'answers' => [['exam_question_id' => $eq, 'question_id' => $question->id, 'given_answer' => str_repeat('x', 70000)]],
        ])->assertStatus(422);

        $this->assertSame(0, ExamAnswer::withoutTenant()->where('tenant_id', $tenant->id)->count());
    }

    public function test_a_garbage_answer_is_not_marked_correct(): void
    {
        $tenant = $this->tenant();
        $this->attachSubscription($tenant);
        [$course, $section, $student] = $this->studentWithCourse($tenant);
        $this->enroll($tenant, $course, $student);

        $question = $this->interactive($tenant, ['mode' => 'auto', 'rule' => 'single_choice', 'key' => 'A']);
        $exam = $this->examWith($tenant, $course, $section, [[$question, 10]], 0);

        $headers = $this->authHeaders($student, $tenant);
        $start = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempt");
        $attemptId = $start->json('attempt.id');
        $eq = $start->json('questions.0.exam_question_id');

        $submit = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempts/{$attemptId}/submit", [
            // Not valid JSON, and not equal to the key either.
            'answers' => [['exam_question_id' => $eq, 'question_id' => $question->id, 'given_answer' => '{not-json']],
        ]);
        $submit->assertOk();
        $this->assertEquals(0.0, (float) $submit->json('result.score'));

        $row = ExamAnswer::withoutTenant()->where('question_id', $question->id)->firstOrFail();
        $this->assertFalse($row->is_correct);
    }

    // ─────────────────────────── (4) isolation ───────────────────────────

    public function test_a_teacher_cannot_touch_another_tenants_interactive_question(): void
    {
        $tenantA = $this->tenant();
        $this->attachSubscription($tenantA);
        $question = $this->interactive($tenantA, ['mode' => 'auto', 'rule' => 'single_choice', 'key' => 'A']);

        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantB);
        $headersB = $this->authHeaders($this->teacher($tenantB), $tenantB);

        $this->withHeaders($headersB)->getJson("/api/v1/questions/{$question->id}")->assertNotFound();
        $this->withHeaders($headersB)->putJson("/api/v1/questions/{$question->id}", [
            'question_text' => 'hijacked',
        ])->assertNotFound();
    }

    // ─────────────────────────── (5) term-bound attempts ───────────────────────────

    public function test_interactive_attempt_is_scoped_to_the_academic_term(): void
    {
        $tenant = $this->tenant();
        $this->attachSubscription($tenant);
        [$course, $section, $student] = $this->studentWithCourse($tenant);

        $term = AcademicTerm::create([
            'tenant_id' => $tenant->id, 'name' => 'T1', 'status' => AcademicTerm::STATUS_ACTIVE,
            'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(),
        ]);
        $this->enroll($tenant, $course, $student, $term->id);

        $question = $this->interactive($tenant, ['mode' => 'auto', 'rule' => 'single_choice', 'key' => 'A']);
        $exam = $this->examWith($tenant, $course, $section, [[$question, 10]], 50);

        $headers = $this->authHeaders($student, $tenant);
        $start = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempt");
        $start->assertOk();
        $this->assertSame($term->id, $start->json('attempt.academic_term_id'));

        $attemptId = $start->json('attempt.id');
        $eq = $start->json('questions.0.exam_question_id');
        $submit = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempts/{$attemptId}/submit", [
            'answers' => [['exam_question_id' => $eq, 'question_id' => $question->id, 'given_answer' => 'a']],
        ]);
        $submit->assertOk();
        $this->assertTrue($submit->json('result.passed'));

        $this->assertDatabaseHas('exam_attempts', ['id' => $attemptId, 'academic_term_id' => $term->id, 'passed' => true]);
    }

    // ─────────────────────────── (6) regression: existing types ───────────────────────────

    public function test_existing_question_types_still_grade_the_same(): void
    {
        $tenant = $this->tenant();
        $this->attachSubscription($tenant);
        [$course, $section, $student] = $this->studentWithCourse($tenant);
        $this->enroll($tenant, $course, $student);

        $ordering = Question::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'question_type' => 'ordering',
            'question_text' => 'order', 'points' => 10, 'is_active' => true,
            'metadata' => ['items' => ['1', '2', '3']],
        ]);
        $fill = Question::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'question_type' => 'fill_blank',
            'question_text' => 'fill', 'points' => 10, 'is_active' => true,
            'metadata' => ['answers' => ['Paris']],
        ]);

        $exam = $this->examWith($tenant, $course, $section, [[$ordering, 10], [$fill, 10]], 0);

        $headers = $this->authHeaders($student, $tenant);
        $start = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempt");
        $attemptId = $start->json('attempt.id');
        $byQuestion = collect($start->json('questions'))->keyBy('question_id');

        $submit = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempts/{$attemptId}/submit", [
            'answers' => [
                ['exam_question_id' => $byQuestion[$ordering->id]['exam_question_id'], 'question_id' => $ordering->id, 'given_answer' => json_encode(['1', '2', '3'])],
                ['exam_question_id' => $byQuestion[$fill->id]['exam_question_id'], 'question_id' => $fill->id, 'given_answer' => json_encode(['paris'])],
            ],
        ]);
        $submit->assertOk();
        // Two questions at 10 points each, both correct → 20/20 points.
        $this->assertEquals(20.0, (float) $submit->json('result.score'));
        $this->assertEquals(100.0, (float) $submit->json('result.percentage'));
        $this->assertFalse($submit->json('result.needs_review'));
    }
}
