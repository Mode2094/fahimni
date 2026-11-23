<?php

namespace Tests\Feature;

use App\Content\ContentDuplicator;
use App\Media\MediaService;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\ExamSection;
use App\Models\FileContent;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\LessonProgress;
use App\Models\MediaAsset;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Section;
use App\Models\Tenant;
use App\Models\TextContent;
use App\Models\User;
use App\Models\VideoContent;
use App\Models\PlanFeature;
use App\Plans\UsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * استنساخ الكورسات والدروس والاختبارات — سلامة العلاقات، الأصول المشتركة،
 * الحدود، الصلاحيات، والفشل في المنتصف.
 *
 * القاعدة الذهبية: النسخة هيكل مستقل يبدأ مسودة، بلا أي بيانات طلاب، وبلا
 * نسخ فيديو Bunny ثانية (إعادة استخدام بالمرجع مع حماية الأصل من الحذف).
 */
class ContentDuplicationTest extends TenantTestCase
{
    use RefreshDatabase;

    private const PROVIDER_VIDEO = 'bunny-video-guid-001';

    private function makeCourseTree(Tenant $tenant, array $opts = []): array
    {
        $course = Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'title' => 'Source course', 'slug' => 'src-'.Str::random(6),
            'description' => 'desc', 'level' => 'all', 'is_published' => true, 'sort_order' => 0,
            'metadata' => [],
        ]);

        $section = Section::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'title' => 'Unit 1', 'sort_order' => 0, 'is_published' => true, 'metadata' => [],
        ]);

        // درس فيديو + أصل وسائط (كما ينتجه رفع Bunny الحقيقي).
        $videoLesson = Lesson::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'section_id' => $section->id,
            'title' => 'Video lesson', 'lesson_type' => 'video', 'is_published' => true, 'sort_order' => 0,
            'metadata' => [],
        ]);
        $videoContent = LessonContent::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'lesson_id' => $videoLesson->id,
            'content_type' => 'video', 'sort_order' => 0, 'is_required' => true, 'metadata' => [],
        ]);
        VideoContent::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'lesson_content_id' => $videoContent->id, 'lesson_id' => $videoLesson->id,
            'provider' => 'bunny', 'provider_video_id' => self::PROVIDER_VIDEO,
            'title' => 'Video', 'status' => 'ready', 'duration' => 600, 'file_size_bytes' => 1_500_000,
            'config' => [],
        ]);
        $asset = MediaAsset::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'course_id' => $course->id, 'lesson_content_id' => $videoContent->id,
            'provider' => 'bunny', 'provider_asset_id' => self::PROVIDER_VIDEO,
            'kind' => 'video', 'status' => MediaAsset::STATUS_READY,
            'title' => 'Video', 'size_bytes' => 1_500_000, 'storage_bytes' => 1_500_000,
            'metadata' => [],
        ]);

        // درس نصي.
        $textLesson = Lesson::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'section_id' => $section->id,
            'title' => 'Text lesson', 'lesson_type' => 'text', 'is_published' => true, 'sort_order' => 1,
            'metadata' => [],
        ]);
        $textContent = LessonContent::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'lesson_id' => $textLesson->id,
            'content_type' => 'text', 'sort_order' => 0, 'is_required' => true, 'metadata' => [],
        ]);
        TextContent::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'lesson_content_id' => $textContent->id,
            'body' => 'Hello world', 'format' => 'plain', 'word_count' => 2, 'reading_time_minutes' => 1,
        ]);

        // درس ملف (يُعاد استخدام المسار بلا نسخ بايتات).
        $fileLesson = Lesson::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'section_id' => $section->id,
            'title' => 'File lesson', 'lesson_type' => 'file', 'is_published' => true, 'sort_order' => 2,
            'metadata' => [],
        ]);
        $fileContent = LessonContent::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'lesson_id' => $fileLesson->id,
            'content_type' => 'file', 'sort_order' => 0, 'is_required' => true, 'metadata' => [],
        ]);
        FileContent::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'lesson_content_id' => $fileContent->id,
            'original_name' => 'notes.pdf', 'storage_path' => 'lesson-files/'.$tenant->id.'/notes.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 250_000, 'extension' => 'pdf',
            'is_downloadable' => true, 'metadata' => [],
        ]);

        // اختبار مع قسم سؤال، سؤال تفاعلي HTML مع خيارات.
        $exam = Exam::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'section_id' => $section->id, 'title' => 'Source exam', 'description' => 'e',
            'duration_minutes' => 30, 'pass_mark_percent' => 60, 'max_attempts' => 3,
            'random_questions_count' => 0, 'anti_cheat_enabled' => true,
            'shuffle_questions' => true, 'shuffle_options' => true,
            'show_result_immediately' => true, 'show_correct_answers' => true,
            'is_published' => true, 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(),
            'sort_order' => 0, 'metadata' => [],
        ]);

        $examSection = ExamSection::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'exam_id' => $exam->id,
            'title' => 'Part A', 'sort_order' => 0,
        ]);

        $question = Question::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'question_type' => Question::TYPE_INTERACTIVE_HTML, 'question_text' => 'Drag the item',
            'points' => 5, 'is_active' => true,
            'metadata' => [
                'interactive' => ['html' => '<div>x</div>', 'css' => '', 'js' => ''],
                'grading' => ['rules' => [['answer' => 'a']]],
            ],
        ]);
        QuestionOption::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'question_id' => $question->id,
            'option_text' => 'A', 'is_correct' => true, 'sort_order' => 0,
        ]);
        ExamQuestion::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'exam_id' => $exam->id,
            'exam_section_id' => $examSection->id, 'question_id' => $question->id,
            'sort_order' => 0, 'points_override' => 7,
        ]);

        // واجب.
        $assignment = Assignment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'section_id' => $section->id, 'title' => 'Homework', 'description' => 'do it',
            'points' => 10, 'allow_late' => true, 'is_published' => true, 'sort_order' => 0,
            'metadata' => [],
        ]);

        return compact(
            'course', 'section', 'videoLesson', 'textLesson', 'fileLesson',
            'videoContent', 'textContent', 'fileContent', 'asset', 'exam', 'examSection',
            'question', 'assignment'
        );
    }

    private function owner(Tenant $tenant): User
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $this->attachToTenant($user, $tenant, 'owner');

        return $user;
    }

    // ─────────────────────────── deep copy ───────────────────────────

    /** @test */
    public function test_course_duplicate_deep_copies_structure_as_unpublished_drafts(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'question' => $question] = $this->makeCourseTree($tenant);
        $owner = $this->owner($tenant);

        $response = $this->postJson(
            "/api/v1/courses/{$course->id}/duplicate",
            ['title' => 'نسخة الكورس'],
            $this->authHeaders($owner, $tenant),
        );

        $response->assertCreated();
        $newCourseId = $response->json('course.id');
        $this->assertNotSame($course->id, $newCourseId);
        $this->assertSame('نسخة الكورس', $response->json('course.title'));
        $this->assertFalse($response->json('course.is_published'), 'a copy always starts as a draft');

        $newCourse = Course::withoutTenant()->findOrFail($newCourseId);

        // الأقسام والدروس.
        $sections = Section::withoutTenant()->where('course_id', $newCourseId)->get();
        $this->assertCount(1, $sections);
        $this->assertFalse((bool) $sections->first()->is_published);

        $lessons = Lesson::withoutTenant()->where('section_id', $sections->first()->id)->orderBy('sort_order')->get();
        $this->assertSame(['Video lesson', 'Text lesson', 'File lesson'], $lessons->pluck('title')->all());
        foreach ($lessons as $lesson) {
            $this->assertFalse((bool) $lesson->is_published, 'every lesson copy is a draft');
            $this->assertNotSame($course->id, $lesson->section_id);
        }

        // المحتوى.
        $newVideoLesson = $lessons->firstWhere('title', 'Video lesson');
        $newVideo = VideoContent::withoutTenant()->where('lesson_id', $newVideoLesson->id)->firstOrFail();
        $this->assertSame(self::PROVIDER_VIDEO, $newVideo->provider_video_id, 'same provider video — no re-upload');
        $this->assertTrue((bool) $newVideo->is_shared_reference);

        $newTextLesson = $lessons->firstWhere('title', 'Text lesson');
        $textContentRow = LessonContent::withoutTenant()->where('lesson_id', $newTextLesson->id)->firstOrFail();
        $this->assertSame('Hello world', TextContent::withoutTenant()->where('lesson_content_id', $textContentRow->id)->value('body'));

        $newFileLesson = $lessons->firstWhere('title', 'File lesson');
        $fileContentRow = LessonContent::withoutTenant()->where('lesson_id', $newFileLesson->id)->firstOrFail();
        $newFile = FileContent::withoutTenant()->where('lesson_content_id', $fileContentRow->id)->firstOrFail();
        $this->assertSame('lesson-files/'.$tenant->id.'/notes.pdf', $newFile->storage_path, 'file bytes are reused, not copied');
        $this->assertTrue((bool) $newFile->is_shared_reference);

        // الاختبار: نسخة مسودة، نفس السؤال من البنك، نفس الإعدادات، وربط القسم.
        $newExam = Exam::withoutTenant()->where('course_id', $newCourseId)->firstOrFail();
        $this->assertFalse((bool) $newExam->is_published);
        $this->assertSame(30, (int) $newExam->duration_minutes);
        $this->assertSame('60.00', (string) $newExam->pass_mark_percent);
        $this->assertSame(3, (int) $newExam->max_attempts);
        $this->assertTrue((bool) $newExam->anti_cheat_enabled);
        $this->assertNull($newExam->starts_at, 'stale scheduling is not copied');
        $this->assertNull($newExam->ends_at);

        $newExamQuestion = ExamQuestion::withoutTenant()->where('exam_id', $newExam->id)->firstOrFail();
        $this->assertSame($question->id, $newExamQuestion->question_id, 'bank question is reused, not duplicated');
        $this->assertSame('7.00', (string) $newExamQuestion->points_override);
        $this->assertNotNull($newExamQuestion->exam_section_id);
        $this->assertSame($newExam->id, ExamSection::withoutTenant()->find($newExamQuestion->exam_section_id)->exam_id);
        $this->assertSame(1, Question::withoutTenant()->count(), 'no question is duplicated into the bank');

        // الواجب.
        $newAssignment = Assignment::withoutTenant()->where('course_id', $newCourseId)->firstOrFail();
        $this->assertFalse((bool) $newAssignment->is_published);
        $this->assertSame($sections->first()->id, $newAssignment->section_id);

        // لا شيء من بيانات الطلاب.
        $this->assertSame(0, Enrollment::withoutTenant()->where('course_id', $newCourseId)->count());
        $this->assertSame(0, LessonProgress::withoutTenant()->whereIn('lesson_id', $lessons->pluck('id'))->count());
    }

    /** @test */
    public function test_course_duplicate_remaps_lesson_exercise_to_the_new_exam(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'textLesson' => $textLesson, 'exam' => $exam] = $this->makeCourseTree($tenant);
        $textLesson->update(['metadata' => ['exercise_exam_id' => $exam->id, 'exercise_position' => 'after_content']]);
        $owner = $this->owner($tenant);

        $response = $this->postJson("/api/v1/courses/{$course->id}/duplicate", [], $this->authHeaders($owner, $tenant));
        $response->assertCreated();

        $newExam = Exam::withoutTenant()->where('course_id', $response->json('course.id'))->firstOrFail();
        $newLesson = Lesson::withoutTenant()
            ->where('section_id', Section::withoutTenant()->where('course_id', $response->json('course.id'))->value('id'))
            ->where('title', 'Text lesson')->firstOrFail();

        $this->assertSame($newExam->id, $newLesson->metadata['exercise_exam_id'], 'exercise points at the cloned exam, not the source');
    }

    /** @test */
    public function test_lesson_duplicate_copies_its_contents_only(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['videoLesson' => $lesson] = $this->makeCourseTree($tenant);
        $owner = $this->owner($tenant);

        $response = $this->postJson(
            "/api/v1/lessons/{$lesson->id}/duplicate",
            ['title' => 'نسخة الدرس'],
            $this->authHeaders($owner, $tenant),
        );

        $response->assertCreated();
        $this->assertSame('نسخة الدرس', $response->json('lesson.title'));
        $this->assertFalse($response->json('lesson.is_published'));

        $newId = $response->json('lesson.id');
        $this->assertNotSame($lesson->id, $newId);
        $this->assertSame(4, Lesson::withoutTenant()->where('section_id', $lesson->section_id)->count());
        $this->assertSame(1, VideoContent::withoutTenant()->where('lesson_id', $newId)->count());
        $this->assertSame(0, LessonProgress::withoutTenant()->where('lesson_id', $newId)->count());
    }

    /** @test */
    public function test_exam_duplicate_copies_settings_and_reuses_bank_questions(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['exam' => $exam, 'question' => $question] = $this->makeCourseTree($tenant);
        $owner = $this->owner($tenant);

        $response = $this->postJson(
            "/api/v1/exams/{$exam->id}/duplicate",
            ['title' => 'نسخة الاختبار'],
            $this->authHeaders($owner, $tenant),
        );

        $response->assertCreated();
        $this->assertSame('نسخة الاختبار', $response->json('exam.title'));
        $this->assertFalse($response->json('exam.is_published'));

        $newExamId = $response->json('exam.id');
        $this->assertNotSame($exam->id, $newExamId);
        $this->assertSame(1, ExamQuestion::withoutTenant()->where('exam_id', $newExamId)->where('question_id', $question->id)->count());
        $this->assertSame(1, Question::withoutTenant()->count());
        $this->assertSame(0, ExamAttempt::withoutTenant()->where('exam_id', $newExamId)->count());
        $this->assertSame(2, Exam::withoutTenant()->where('course_id', $exam->course_id)->count());
    }

    // ─────────────────────────── shared assets & quota ───────────────────────────

    /** @test */
    public function test_duplicate_reuses_media_without_double_counting_quota(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course] = $this->makeCourseTree($tenant);
        $owner = $this->owner($tenant);

        $usage = app(UsageService::class);
        $videosBefore = $usage->usageOf($tenant->id, 'videos');
        $filesBefore = $usage->usageOf($tenant->id, 'files');
        $storageBefore = $usage->storageBytes($tenant->id);

        $this->postJson("/api/v1/courses/{$course->id}/duplicate", [], $this->authHeaders($owner, $tenant))
            ->assertCreated();

        // صفوف مرجعية أُضيفت فعلاً...
        $this->assertSame(2, VideoContent::withoutTenant()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(2, FileContent::withoutTenant()->where('tenant_id', $tenant->id)->count());

        // ...لكن الحصص/المساحة لا تتضاعف (لا فيديو Bunny ثانٍ فعلي).
        $this->assertSame($videosBefore, $usage->usageOf($tenant->id, 'videos'));
        $this->assertSame($filesBefore, $usage->usageOf($tenant->id, 'files'));
        $this->assertSame($storageBefore, $usage->storageBytes($tenant->id));
    }

    /** @test */
    public function test_original_media_asset_is_protected_while_a_clone_uses_it(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'asset' => $asset] = $this->makeCourseTree($tenant);
        $owner = $this->owner($tenant);

        $this->postJson("/api/v1/courses/{$course->id}/duplicate", [], $this->authHeaders($owner, $tenant))
            ->assertCreated();

        // Teacher deletes the ORIGINAL video content's asset — the clone still plays it.
        app(MediaService::class)->deleteAsset($asset->id);

        $stillThere = MediaAsset::withoutTenant()->find($asset->id);
        $this->assertNotNull($stillThere, 'asset must survive while a duplicated lesson still references it');
        $this->assertNull($stillThere->lesson_content_id, 'the asset is detached, not destroyed');
        $this->assertSame(2, VideoContent::withoutTenant()->where('provider_video_id', self::PROVIDER_VIDEO)->count());

        // Once nothing references it, deletion is allowed again.
        VideoContent::withoutTenant()->where('provider_video_id', self::PROVIDER_VIDEO)->delete();
        app(MediaService::class)->deleteAsset($asset->id);
        $this->assertNull(MediaAsset::withoutTenant()->find($asset->id));
    }

    // ─────────────────────────── limits & permissions ───────────────────────────

    /** @test */
    public function test_duplicate_respects_the_course_limit_and_leaves_nothing_behind(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course] = $this->makeCourseTree($tenant);
        $owner = $this->owner($tenant);

        // سقف الكورسات = العدد الحالي (كورس واحد) → النسخة تتجاوز الحد.
        $planId = $tenant->subscriptions()->first()->plan_id;
        PlanFeature::where('plan_id', $planId)->where('feature_key', 'courses_limit')->firstOrFail()
            ->update(['feature_value' => '1']);

        $before = Course::withoutTenant()->where('tenant_id', $tenant->id)->count();
        $beforeSections = Section::withoutTenant()->where('tenant_id', $tenant->id)->count();

        $this->postJson("/api/v1/courses/{$course->id}/duplicate", [], $this->authHeaders($owner, $tenant))
            ->assertStatus(403);

        $this->assertSame($before, Course::withoutTenant()->where('tenant_id', $tenant->id)->count());
        $this->assertSame($beforeSections, Section::withoutTenant()->where('tenant_id', $tenant->id)->count());
    }

    /** @test */
    public function test_student_cannot_duplicate_and_foreign_teacher_cannot_reach_content(): void
    {
        $tenantA = $this->makeTenant();
        $this->attachSubscription($tenantA);
        ['course' => $courseA] = $this->makeCourseTree($tenantA);

        $student = User::factory()->create();
        $this->attachToTenant($student, $tenantA, 'student');
        $this->postJson("/api/v1/courses/{$courseA->id}/duplicate", [], $this->authHeaders($student, $tenantA))
            ->assertStatus(403);

        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantB);
        $teacherB = User::factory()->create();
        $this->attachToTenant($teacherB, $tenantB, 'teacher');
        // Tenant global scope makes another tenant's course simply "not found".
        $this->postJson("/api/v1/courses/{$courseA->id}/duplicate", [], $this->authHeaders($teacherB, $tenantB))
            ->assertStatus(404);
    }

    // ─────────────────────────── crash mid-way ───────────────────────────

    /** @test */
    public function test_a_failure_mid_duplicate_rolls_back_completely(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course] = $this->makeCourseTree($tenant);

        $beforeCourses = Course::withoutTenant()->where('tenant_id', $tenant->id)->count();
        $beforeSections = Section::withoutTenant()->where('tenant_id', $tenant->id)->count();
        $beforeLessons = Lesson::withoutTenant()->where('tenant_id', $tenant->id)->count();

        // Explode as soon as the copier creates its first lesson — i.e. after the
        // course + sections have already been inserted inside the transaction.
        $explode = true;
        Lesson::creating(function () use (&$explode) {
            if ($explode) {
                throw new \RuntimeException('forced failure');
            }
        });

        try {
            app(ContentDuplicator::class)->duplicateCourse($course);
            $this->fail('expected the duplication to throw');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced failure', $e->getMessage());
        } finally {
            $explode = false;
        }

        $this->assertSame($beforeCourses, Course::withoutTenant()->where('tenant_id', $tenant->id)->count(), 'no partial course left behind');
        $this->assertSame($beforeSections, Section::withoutTenant()->where('tenant_id', $tenant->id)->count());
        $this->assertSame($beforeLessons, Lesson::withoutTenant()->where('tenant_id', $tenant->id)->count());
    }
}
