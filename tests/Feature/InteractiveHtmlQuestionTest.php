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

    // ─────────────────── (5b) boolean key + update hardening ───────────────────

    public function test_boolean_answer_keys_only_match_real_booleans(): void
    {
        $tenant = $this->tenant();
        $grader = app(\App\Services\InteractiveHtmlGrader::class);

        $cases = [
            // [key, given, expected]
            [true, true, true],
            [true, 'true', true],
            [true, 1, true],
            [true, '1', true],
            [true, false, false],
            [true, 'false', false],
            [true, 0, false],
            [true, '', false],
            [true, 'anything', false],
            [true, 'yes', false],
            [true, 2, false],
            [true, 'TRUE', true],
            [false, false, true],
            [false, 'false', true],
            [false, 0, true],
            [false, '0', true],
            [false, true, false],
            [false, 'true', false],
            [false, '', false],
            [false, 'anything', false],
            [false, 'no', false],
            [false, 1, false],
        ];

        foreach ($cases as $i => [$key, $given, $expected]) {
            $question = $this->interactive($tenant, ['mode' => 'auto', 'rule' => 'single_choice', 'key' => $key]);
            $this->assertSame(
                $expected,
                $grader->grade($question, $given),
                "case {$i}: key=".var_export($key, true).' given='.var_export($given, true)
            );
        }
    }

    public function test_partial_update_without_question_type_still_enforces_interactive_rules(): void
    {
        $tenant = $this->tenant();
        $this->attachSubscription($tenant);
        $headers = $this->authHeaders($this->teacher($tenant), $tenant);

        $create = $this->withHeaders($headers)->postJson('/api/v1/questions', [
            'question_type' => 'interactive_html',
            'question_text' => 'q',
            'metadata' => [
                'interactive' => ['html' => '<div></div>'],
                'grading' => ['mode' => 'auto', 'rule' => 'single_choice', 'key' => 'A'],
            ],
        ]);
        $create->assertCreated();
        $id = $create->json('question.id');

        // No question_type in the partial update, yet auto-grading without a key
        // must still be rejected (the rules follow the persisted type).
        $this->withHeaders($headers)->putJson("/api/v1/questions/{$id}", [
            'question_text' => 'renamed',
            'metadata' => [
                'interactive' => ['html' => '<div></div>'],
                'grading' => ['mode' => 'auto', 'rule' => 'single_choice'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('metadata.grading.key');

        // Oversized HTML is rejected even without question_type.
        $this->withHeaders($headers)->putJson("/api/v1/questions/{$id}", [
            'metadata' => [
                'interactive' => ['html' => str_repeat('h', 200001)],
                'grading' => ['mode' => 'manual'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('metadata.interactive.html');

        // An unknown grading rule is rejected.
        $this->withHeaders($headers)->putJson("/api/v1/questions/{$id}", [
            'metadata' => [
                'interactive' => ['html' => '<div></div>'],
                'grading' => ['mode' => 'auto', 'rule' => 'telepathy', 'key' => 'A'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('metadata.grading.rule');

        // A metadata update may not silently drop the interactive payload.
        $this->withHeaders($headers)->putJson("/api/v1/questions/{$id}", [
            'metadata' => ['grading' => ['mode' => 'manual']],
        ])->assertStatus(422)->assertJsonValidationErrors('metadata.interactive');

        // A valid partial update (still no question_type) is accepted.
        $ok = $this->withHeaders($headers)->putJson("/api/v1/questions/{$id}", [
            'question_text' => 'renamed',
            'metadata' => [
                'interactive' => ['html' => '<div id="ok"></div>'],
                'grading' => ['mode' => 'auto', 'rule' => 'single_choice', 'key' => 'A'],
            ],
        ]);
        $ok->assertOk();

        $stored = Question::withoutTenant()->findOrFail($id);
        $this->assertSame('interactive_html', $stored->question_type);
        $this->assertSame('<div id="ok"></div>', $stored->metadata['interactive']['html']);
    }

    public function test_update_persists_only_validated_metadata(): void
    {
        $tenant = $this->tenant();
        $this->attachSubscription($tenant);
        $headers = $this->authHeaders($this->teacher($tenant), $tenant);

        $create = $this->withHeaders($headers)->postJson('/api/v1/questions', [
            'question_type' => 'interactive_html',
            'question_text' => 'q',
            'metadata' => [
                'interactive' => ['html' => '<div></div>'],
                'grading' => ['mode' => 'auto', 'rule' => 'single_choice', 'key' => 'A'],
            ],
        ]);
        $create->assertCreated();
        $id = $create->json('question.id');

        // Known keys survive, an unknown key is not smuggled into JSONB.
        $this->withHeaders($headers)->putJson("/api/v1/questions/{$id}", [
            'metadata' => [
                'image_url' => 'https://cdn.example/z.png',
                'interactive' => ['html' => '<div></div>', 'css' => 'x{}', 'js' => 'FahimniQuestion.setAnswer(1)'],
                'grading' => ['mode' => 'auto', 'rule' => 'single_choice', 'key' => 'A'],
                'evil' => 'should-be-dropped',
            ],
        ])->assertOk();

        $stored = Question::withoutTenant()->findOrFail($id);
        $this->assertSame('https://cdn.example/z.png', $stored->metadata['image_url']);
        $this->assertSame('A', $stored->metadata['grading']['key']);
        $this->assertArrayNotHasKey('evil', $stored->metadata);
    }

    public function test_full_teaching_flow_create_attempt_view_submit_and_grade(): void
    {
        $tenant = $this->tenant();
        $this->attachSubscription($tenant);
        $teacher = $this->teacher($tenant);
        $teacherHeaders = $this->authHeaders($teacher, $tenant);

        [$course, $section, $student] = $this->studentWithCourse($tenant);
        $this->enroll($tenant, $course, $student);

        // 1) Teacher creates the interactive question through the public API.
        $create = $this->withHeaders($teacherHeaders)->postJson('/api/v1/questions', [
            'question_type' => 'interactive_html',
            'question_text' => 'Pick the point',
            'course_id' => $course->id,
            'section_id' => $section->id,
            'points' => 10,
            'metadata' => [
                'interactive' => ['html' => '<svg id="s"></svg>', 'css' => 'svg{width:100%}', 'js' => '', 'height' => 320, 'auto_capture' => true],
                'grading' => ['mode' => 'auto', 'rule' => 'json_equals', 'key' => ['x' => 2, 'y' => 5]],
            ],
        ]);
        $create->assertCreated();
        $questionId = $create->json('question.id');

        $question = Question::withoutTenant()->findOrFail($questionId);
        $exam = $this->examWith($tenant, $course, $section, [[$question, 10]], 50);

        // 2) Student starts an attempt and receives the student-safe view.
        $studentHeaders = $this->authHeaders($student, $tenant);
        $start = $this->withHeaders($studentHeaders)->postJson("/api/v1/exams/{$exam->id}/attempt");
        $start->assertOk();
        $attemptId = $start->json('attempt.id');
        $row = collect($start->json('questions'))->firstWhere('question_id', $questionId);

        $this->assertNotNull($row);
        $this->assertSame('interactive_html', $row['question_type']);
        $this->assertSame('<svg id="s"></svg>', $row['metadata']['interactive']['html']);
        // The hidden answer key must never reach the student view.
        $this->assertArrayNotHasKey('grading', $row['metadata']);
        $this->assertStringNotContainsString('json_equals', json_encode($start->json('questions')));

        // 3) Student submits the correct structured answer and is auto-graded.
        $submit = $this->withHeaders($studentHeaders)->postJson(
            "/api/v1/exams/{$exam->id}/attempts/{$attemptId}/submit",
            ['answers' => [[
                'exam_question_id' => $row['exam_question_id'],
                'question_id' => $questionId,
                'given_answer' => json_encode(['y' => 5, 'x' => 2]),
            ]]]
        );
        $submit->assertOk();
        $this->assertEquals(10.0, (float) $submit->json('result.score'));
        $this->assertEquals(100.0, (float) $submit->json('result.percentage'));
        $this->assertTrue($submit->json('result.passed'));
        $this->assertFalse($submit->json('result.needs_review'));

        $this->assertDatabaseHas('exam_answers', [
            'tenant_id' => $tenant->id,
            'question_id' => $questionId,
            'is_correct' => true,
        ]);
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

    // ─────────────── (7) the independent "سؤال HTML" (custom_html) type ───────────────

    /**
     * The HTML question is a SEPARATE teacher-facing type that reuses the same
     * sandbox + grader. These tests lock the split so a future change cannot
     * quietly merge it back into `interactive_html` (the regression we fix).
     */
    public function test_question_type_registry_keeps_both_sandbox_types_independent(): void
    {
        $this->assertContains('interactive_html', Question::TYPES);
        $this->assertContains('custom_html', Question::TYPES);
        $this->assertCount(10, Question::TYPES);

        $this->assertSame(['interactive_html', 'custom_html'], Question::SANDBOX_TYPES);
        $this->assertNotSame(Question::TYPE_INTERACTIVE_HTML, Question::TYPE_CUSTOM_HTML);
        $this->assertTrue(Question::isSandboxType('custom_html'));
        $this->assertTrue(Question::isSandboxType('interactive_html'));
        $this->assertFalse(Question::isSandboxType('multiple_choice'));
        $this->assertFalse(Question::isSandboxType(null));
    }

    private function customHtml(Tenant $tenant, array $grading, string $html = '<h1>Hi</h1>', string $js = ''): Question
    {
        return Question::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'question_type' => Question::TYPE_CUSTOM_HTML,
            'question_text' => 'HTML question', 'points' => 10, 'is_active' => true,
            'metadata' => [
                'interactive' => ['html' => $html, 'css' => 'body{margin:0}', 'js' => $js, 'height' => 320, 'auto_capture' => true],
                'grading' => $grading,
            ],
        ]);
    }

    public function test_teacher_can_create_a_custom_html_question_through_the_api(): void
    {
        $tenant = $this->tenant();
        $this->attachSubscription($tenant);
        $headers = $this->authHeaders($this->teacher($tenant), $tenant);

        $create = $this->withHeaders($headers)->postJson('/api/v1/questions', [
            'question_type' => 'custom_html',
            'question_text' => 'Paste a full HTML document',
            'points' => 5,
            'metadata' => [
                'interactive' => ['html' => '<!doctype html><h1>x</h1>', 'css' => 'h1{color:red}', 'js' => 'FahimniQuestion.setAnswer(7)', 'height' => 300, 'auto_capture' => true],
                'grading' => ['mode' => 'auto', 'rule' => 'single_choice', 'key' => 7],
            ],
        ]);

        $create->assertCreated();
        $id = $create->json('question.id');
        $this->assertDatabaseHas('questions', ['id' => $id, 'question_type' => 'custom_html']);

        $stored = Question::withoutTenant()->findOrFail($id);
        $this->assertSame('<!doctype html><h1>x</h1>', $stored->metadata['interactive']['html']);
        $this->assertSame(7, $stored->metadata['grading']['key']);
    }

    public function test_custom_html_enforces_the_same_payload_and_key_rules(): void
    {
        $tenant = $this->tenant();
        $this->attachSubscription($tenant);
        $headers = $this->authHeaders($this->teacher($tenant), $tenant);

        // Missing the payload.
        $this->withHeaders($headers)->postJson('/api/v1/questions', [
            'question_type' => 'custom_html',
            'question_text' => 'x',
            'metadata' => ['grading' => ['mode' => 'manual']],
        ])->assertStatus(422)->assertJsonValidationErrors('metadata.interactive.html');

        // Auto grading without a hidden key.
        $this->withHeaders($headers)->postJson('/api/v1/questions', [
            'question_type' => 'custom_html',
            'question_text' => 'x',
            'metadata' => [
                'interactive' => ['html' => '<div></div>'],
                'grading' => ['mode' => 'auto', 'rule' => 'single_choice'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('metadata.grading.key');
    }

    public function test_custom_html_grades_and_never_leaks_its_key(): void
    {
        $tenant = $this->tenant();
        $this->attachSubscription($tenant);
        [$course, $section, $student] = $this->studentWithCourse($tenant);
        $this->enroll($tenant, $course, $student);

        $question = $this->customHtml(
            $tenant,
            ['mode' => 'auto', 'rule' => 'single_choice', 'key' => 'SECRET_HTML_KEY'],
            '<button>answer</button>',
            'FahimniQuestion.setAnswer("student")'
        );
        $exam = $this->examWith($tenant, $course, $section, [[$question, 10]], 50);

        $studentHeaders = $this->authHeaders($student, $tenant);
        $start = $this->withHeaders($studentHeaders)->postJson("/api/v1/exams/{$exam->id}/attempt");
        $start->assertOk();
        $attemptId = $start->json('attempt.id');
        $row = collect($start->json('questions'))->firstWhere('question_id', $question->id);

        $this->assertSame('custom_html', $row['question_type']);
        $this->assertSame('<button>answer</button>', $row['metadata']['interactive']['html']);
        $this->assertArrayNotHasKey('grading', $row['metadata']);
        $this->assertStringNotContainsString('SECRET_HTML_KEY', json_encode($start->json('questions')));

        $submit = $this->withHeaders($studentHeaders)->postJson(
            "/api/v1/exams/{$exam->id}/attempts/{$attemptId}/submit",
            ['answers' => [[
                'exam_question_id' => $row['exam_question_id'],
                'question_id' => $question->id,
                'given_answer' => 'SECRET_HTML_KEY',
            ]]]
        );
        $submit->assertOk();
        $this->assertEquals(10.0, (float) $submit->json('result.score'));
        $this->assertFalse($submit->json('result.needs_review'));
    }

    public function test_custom_html_and_interactive_html_coexist_in_one_exam_without_sharing_type(): void
    {
        $tenant = $this->tenant();
        $this->attachSubscription($tenant);
        [$course, $section, $student] = $this->studentWithCourse($tenant);
        $this->enroll($tenant, $course, $student);

        $interactive = $this->interactive($tenant, ['mode' => 'auto', 'rule' => 'single_choice', 'key' => 1]);
        $html = $this->customHtml($tenant, ['mode' => 'auto', 'rule' => 'single_choice', 'key' => 2]);

        $exam = $this->examWith($tenant, $course, $section, [[$interactive, 10], [$html, 10]], 0);

        $headers = $this->authHeaders($student, $tenant);
        $start = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempt");
        $attemptId = $start->json('attempt.id');
        $byQuestion = collect($start->json('questions'))->keyBy('question_id');

        // Both types survive side by side, each keeping its own id.
        $this->assertSame('interactive_html', $byQuestion[$interactive->id]['question_type']);
        $this->assertSame('custom_html', $byQuestion[$html->id]['question_type']);

        $submit = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempts/{$attemptId}/submit", [
            'answers' => [
                ['exam_question_id' => $byQuestion[$interactive->id]['exam_question_id'], 'question_id' => $interactive->id, 'given_answer' => '1'],
                ['exam_question_id' => $byQuestion[$html->id]['exam_question_id'], 'question_id' => $html->id, 'given_answer' => '2'],
            ],
        ]);
        $submit->assertOk();
        $this->assertEquals(20.0, (float) $submit->json('result.score'));
        $this->assertFalse($submit->json('result.needs_review'));
    }
}
