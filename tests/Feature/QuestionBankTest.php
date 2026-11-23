<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Section;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Uid\UuidV7;

/**
 * The shared question bank: server-side search/filter/pagination, drawing a
 * random set of bank questions into an exam, and the attempt-paper freeze that
 * keeps a live attempt safe from a later bank edit.
 */
class QuestionBankTest extends TenantTestCase
{
    use RefreshDatabase;

    private function makeCourse(Tenant $tenant): Course
    {
        return Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'title' => 'Course', 'slug' => 'course-'.UuidV7::generate(), 'is_published' => true,
        ]);
    }

    private function makeMcq(Tenant $tenant, array $attrs = []): Question
    {
        return Question::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'question_type' => 'multiple_choice',
            'question_text' => 'Q?',
            'points' => 10,
            'difficulty' => 'medium',
            'is_active' => true,
            'metadata' => [],
            ...$attrs,
        ]);
    }

    private function optionsFor(Question $question, string $correctText = 'A'): array
    {
        $a = QuestionOption::create([
            'id' => UuidV7::generate(), 'tenant_id' => $question->tenant_id, 'question_id' => $question->id,
            'option_text' => 'A', 'is_correct' => $correctText === 'A', 'sort_order' => 0,
        ]);
        $b = QuestionOption::create([
            'id' => UuidV7::generate(), 'tenant_id' => $question->tenant_id, 'question_id' => $question->id,
            'option_text' => 'B', 'is_correct' => $correctText === 'B', 'sort_order' => 1,
        ]);

        return [$a, $b];
    }

    private function makeExam(Tenant $tenant, Course $course): Exam
    {
        return Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'course_id' => $course->id, 'title' => 'E',
            'pass_mark_percent' => 50, 'max_attempts' => 3, 'is_published' => true,
            'duration_minutes' => 30, 'metadata' => [],
        ]);
    }

    private function enroll(Tenant $tenant, Course $course, User $student): void
    {
        Enrollment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'course_id' => $course->id, 'user_id' => $student->id,
            'status' => 'active', 'enrolled_at' => now(),
        ]);
    }

    private function teacher(Tenant $tenant): User
    {
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        return $teacher;
    }

    // ── Bank listing ────────────────────────────────────────────

    public function test_bank_listing_searches_filters_and_paginates(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->teacher($tenant);
        $course = $this->makeCourse($tenant);

        $this->makeMcq($tenant, ['question_text' => 'الكثافة والكتلة', 'category' => 'فيزياء', 'difficulty' => 'easy', 'course_id' => $course->id]);
        $this->makeMcq($tenant, ['question_text' => 'التفاعلات الكيميائية', 'category' => 'كيمياء', 'difficulty' => 'hard', 'question_type' => 'writing']);
        $this->makeMcq($tenant, ['question_text' => 'قانون أوم', 'category' => 'فيزياء', 'difficulty' => 'medium', 'is_active' => false]);

        $headers = $this->authHeaders($teacher, $tenant);

        // Free-text search matches the Arabic question text.
        $search = $this->withHeaders($headers)->getJson('/api/v1/questions?q='.urlencode('الكثافة'));
        $search->assertOk();
        $this->assertSame(1, count($search->json('questions')));
        $this->assertSame('الكثافة والكتلة', $search->json('questions.0.question_text'));

        // Category filter + the categories meta spans the whole bank.
        $byCategory = $this->withHeaders($headers)->getJson('/api/v1/questions?category='.urlencode('فيزياء'));
        $byCategory->assertOk();
        $this->assertSame(2, count($byCategory->json('questions')));
        $this->assertEqualsCanonicalizing(['فيزياء', 'كيمياء'], $byCategory->json('categories'));

        // is_active filter hides the deactivated question.
        $active = $this->withHeaders($headers)->getJson('/api/v1/questions?is_active=1');
        $this->assertSame(2, count($active->json('questions')));

        // Difficulty + type filters compose.
        $combo = $this->withHeaders($headers)->getJson('/api/v1/questions?difficulty=hard&question_type=writing');
        $this->assertSame(1, count($combo->json('questions')));

        // per_page turns on server-side pagination.
        $page = $this->withHeaders($headers)->getJson('/api/v1/questions?per_page=2');
        $page->assertOk();
        $this->assertSame(2, count($page->json('questions')));
        $this->assertSame(3, $page->json('pagination.total'));
        $this->assertSame(2, $page->json('pagination.last_page'));

        // Without per_page the full list is returned (exam editor contract).
        $all = $this->withHeaders($headers)->getJson('/api/v1/questions');
        $this->assertSame(3, count($all->json('questions')));
    }

    public function test_bank_is_isolated_between_teachers(): void
    {
        $tenantA = $this->makeTenant();
        $this->attachSubscription($tenantA);
        $teacherA = $this->teacher($tenantA);
        $this->makeMcq($tenantA, ['question_text' => 'سرّي جداً']);

        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantB);
        $teacherB = $this->teacher($tenantB);

        $listing = $this->withHeaders($this->authHeaders($teacherB, $tenantB))->getJson('/api/v1/questions');
        $listing->assertOk();
        $this->assertSame([], $listing->json('questions'));
    }

    // ── Draw from bank ──────────────────────────────────────────

    public function test_draw_from_bank_attaches_random_questions_without_duplicates_or_new_rows(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->teacher($tenant);
        $course = $this->makeCourse($tenant);
        $exam = $this->makeExam($tenant, $course);

        for ($i = 0; $i < 5; $i++) {
            $this->makeMcq($tenant, ['question_text' => "بنك {$i}", 'course_id' => $course->id]);
        }

        $before = Question::withoutTenant()->where('tenant_id', $tenant->id)->count();

        $headers = $this->authHeaders($teacher, $tenant);

        $draw = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/draw-questions", [
            'count' => 3,
            'course_id' => $course->id,
        ]);
        $draw->assertOk();
        $this->assertSame(3, $draw->json('added_count'));
        $this->assertSame(3, ExamQuestion::where('exam_id', $exam->id)->count());

        // Drawing again adds nothing — already-attached questions are excluded.
        $again = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/draw-questions", [
            'count' => 3,
            'course_id' => $course->id,
        ]);
        $again->assertOk();
        $this->assertSame(2, $again->json('added_count'));
        $this->assertSame(5, ExamQuestion::where('exam_id', $exam->id)->count());

        // No duplicate question rows are created (reuse, never copy).
        $this->assertSame($before, Question::withoutTenant()->where('tenant_id', $tenant->id)->count());
    }

    public function test_draw_from_bank_respects_filters(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->teacher($tenant);
        $course = $this->makeCourse($tenant);
        $exam = $this->makeExam($tenant, $course);

        $this->makeMcq($tenant, ['question_text' => 'سهل', 'difficulty' => 'easy']);
        $this->makeMcq($tenant, ['question_text' => 'صعب ١', 'difficulty' => 'hard']);
        $this->makeMcq($tenant, ['question_text' => 'صعب ٢', 'difficulty' => 'hard']);
        $this->makeMcq($tenant, ['question_text' => 'معطّل', 'difficulty' => 'hard', 'is_active' => false]);

        $draw = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson("/api/v1/exams/{$exam->id}/draw-questions", ['count' => 10, 'difficulty' => 'hard']);
        $draw->assertOk();
        $this->assertSame(2, $draw->json('added_count')); // inactive + easy excluded

        $drawn = Question::withoutTenant()
            ->whereIn('id', ExamQuestion::where('exam_id', $exam->id)->pluck('question_id'))
            ->get();
        $this->assertTrue($drawn->every(fn ($q) => $q->difficulty === 'hard' && $q->is_active));
    }

    // ── Attempt stability ───────────────────────────────────────

    public function test_editing_the_bank_mid_attempt_cannot_change_the_live_paper_or_its_grading(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->teacher($tenant);
        $course = $this->makeCourse($tenant);
        $section = Section::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'title' => 'S', 'sort_order' => 0]);
        $exam = $this->makeExam($tenant, $course);

        $question = $this->makeMcq($tenant, ['question_text' => 'النص الأصلي']);
        [$optA] = $this->optionsFor($question, 'A');

        ExamQuestion::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'exam_id' => $exam->id, 'question_id' => $question->id, 'sort_order' => 0, 'points_override' => 10,
        ]);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $course, $student);

        $studentHeaders = $this->authHeaders($student, $tenant);
        $start = $this->withHeaders($studentHeaders)->postJson("/api/v1/exams/{$exam->id}/attempt");
        $start->assertOk();
        $attemptId = $start->json('attempt.id');
        $examQuestionId = $start->json('questions.0.exam_question_id');
        $this->assertSame('النص الأصلي', $start->json('questions.0.question_text'));

        // Teacher rewrites the bank question and flips which option is correct.
        $question->update(['question_text' => 'النص المعدّل']);
        $question->options()->delete();
        $this->optionsFor($question->fresh(), 'B');

        // The student was served the ORIGINAL paper: resume still shows it.
        $resume = $this->withHeaders($studentHeaders)->postJson("/api/v1/exams/{$exam->id}/attempt");
        $resume->assertOk();
        $this->assertTrue($resume->json('resumed'));
        $this->assertSame('النص الأصلي', $resume->json('questions.0.question_text'));

        // And answering with the ORIGINAL correct option is still graded correct,
        // even though that option no longer exists in the live bank.
        $submit = $this->withHeaders($studentHeaders)->postJson(
            "/api/v1/exams/{$exam->id}/attempts/{$attemptId}/submit",
            ['answers' => [['exam_question_id' => $examQuestionId, 'question_id' => $question->id, 'given_answer' => $optA->id]]]
        );
        $submit->assertOk();
        $this->assertEquals(100, (float) $submit->json('result.percentage'));
        $this->assertTrue((bool) $submit->json('result.passed'));
    }

    public function test_shuffle_options_keeps_correct_answer_matchable(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->teacher($tenant);
        $course = $this->makeCourse($tenant);
        $exam = $this->makeExam($tenant, $course);
        $exam->update(['shuffle_options' => true]);

        $question = $this->makeMcq($tenant, ['question_text' => 'لون السماء']);
        [$optA, $optB] = $this->optionsFor($question, 'B');

        $target = ExamQuestion::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'exam_id' => $exam->id, 'question_id' => $question->id, 'sort_order' => 0, 'points_override' => 10,
        ]);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $course, $student);
        $headers = $this->authHeaders($student, $tenant);

        $start = $this->withHeaders($headers)->postJson("/api/v1/exams/{$exam->id}/attempt");
        $start->assertOk();
        $attemptId = $start->json('attempt.id');

        $served = collect($start->json('questions'))->firstWhere('exam_question_id', $target->id);
        $servedOptionIds = collect($served['options'])->pluck('id')->all();
        // Options are only re-ordered, never dropped or swapped to another id.
        $this->assertEqualsCanonicalizing([$optA->id, $optB->id], $servedOptionIds);
        // The correct option is never revealed in the payload.
        $this->assertArrayNotHasKey('is_correct', $served['options'][0]);

        // B is the correct option the student saw — grading still matches after
        // the option order was shuffled.
        $submit = $this->withHeaders($headers)->postJson(
            "/api/v1/exams/{$exam->id}/attempts/{$attemptId}/submit",
            ['answers' => [['exam_question_id' => $target->id, 'question_id' => $question->id, 'given_answer' => $optB->id]]]
        );
        $submit->assertOk();
        $this->assertTrue((bool) $submit->json('result.passed'));
        $this->assertEquals(100, (float) $submit->json('result.percentage'));
    }
}
