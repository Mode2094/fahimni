<?php

namespace App\Content;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\ExamSection;
use App\Models\FileContent;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\Question;
use App\Models\Section;
use App\Models\TextContent;
use App\Models\VideoContent;
use App\Plans\QuotaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Duplicator — ينشئ نسخة مستقلة (مسودة) من كورس أو درس أو اختبار.
 *
 * مبادئ التصميم:
 *  - كل نسخة تبدأ غير منشورة (`is_published = false`).
 *  - لا تُنسخ أبداً بيانات الطلاب: التسجيلات، المحاولات، الدرجات، التقدّم،
 *    الدفعات، الإحصائيات، أو روابط الدفع. النسخ هيكلي فقط.
 *  - الوسائط الثقيلة (فيديو Bunny، الملفات الخاصة) تُعاد استخدامها بالمرجع:
 *    لا رفع ثانٍ ولا نسخ بايتات. تُعلَّم `is_shared_reference` حتى لا تُحتسب
 *    مرتين في الحصص/المساحة، ويمنع حذف الأصل أثناء استخدام النسخة (MediaService).
 *  - أسئلة الاختبارات تُعاد ربطها من بنك الأسئلة (لا تُضاعف) — وهي الطريقة
 *    المعتمدة في التطبيق، وتحافظ على كل الأنواع (التفاعلية/HTML/الخيارات).
 *  - العملية كلها داخل معاملة واحدة: أي فشل يُلغي كل شيء (لا كورسات جزئية).
 */
class ContentDuplicator
{
    public function __construct(
        private readonly QuotaService $quota,
    ) {}

    // ─────────────────────────────── Course ───────────────────────────────

    /**
     * @return array{model: Course, report: array}
     */
    public function duplicateCourse(Course $source, ?string $title = null): array
    {
        // Always work from the persisted row so DB defaults (duration_hours,
        // max_students, completion_criteria…) are real values, never null.
        $source = $source->fresh() ?? $source;

        $report = $this->emptyReport();

        return DB::transaction(function () use ($source, $title, &$report) {
            $this->quota->assertCan($source->tenant_id, 'courses');

            $examsCount = Exam::withoutTenant()->where('course_id', $source->id)->count();
            $assignmentsCount = Assignment::withoutTenant()->where('course_id', $source->id)->count();

            if ($examsCount > 0) {
                $this->quota->assertCan($source->tenant_id, 'exams', $examsCount);
            }
            if ($assignmentsCount > 0) {
                $this->quota->assertCan($source->tenant_id, 'assignments', $assignmentsCount);
            }

            $course = Course::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $source->tenant_id,
                'teacher_id' => $source->teacher_id,
                'title' => $this->copyTitle($title, $source->title),
                'slug' => $this->uniqueSlug($source->tenant_id, $title ?? $source->title),
                'description' => $source->description,
                'short_description' => $source->short_description,
                'thumbnail_url' => $source->thumbnail_url,
                'cover_url' => $source->cover_url,
                'level' => $source->level,
                'category' => $source->category,
                'tags' => $source->tags,
                'duration_hours' => $source->duration_hours,
                // نسخة جديدة = مسودة دائماً.
                'is_published' => false,
                'is_featured' => false,
                'sort_order' => $source->sort_order,
                'price' => $source->price,
                'max_students' => $source->max_students,
                'completion_criteria' => $source->completion_criteria,
                'certificate_enabled' => $source->certificate_enabled,
                'certificate_template' => $source->certificate_template,
                'certificate_accent_color' => $source->certificate_accent_color,
                'certificate_signature_name' => $source->certificate_signature_name,
                'certificate_signature_title' => $source->certificate_signature_title,
                'prerequisites' => $source->prerequisites,
                'drip_enabled' => $source->drip_enabled,
                'drip_interval_days' => $source->drip_interval_days,
                'metadata' => $source->metadata,
            ]);

            // 1) الأقسام.
            $sectionMap = [];
            foreach (Section::withoutTenant()->where('course_id', $source->id)->orderBy('sort_order')->get() as $section) {
                $newSection = Section::create([
                    'id' => UuidV7::generate(),
                    'tenant_id' => $source->tenant_id,
                    'course_id' => $course->id,
                    'title' => $section->title,
                    'description' => $section->description,
                    'sort_order' => $section->sort_order,
                    'is_published' => false,
                    'metadata' => $section->metadata,
                ]);
                $sectionMap[$section->id] = $newSection->id;
                $report['sections']++;
            }

            // 2) الاختبارات (قبل الدروس حتى نُعيد ربط تمارين الدروس بالنسخ الجديدة).
            $examMap = [];
            foreach (Exam::withoutTenant()->where('course_id', $source->id)->orderBy('sort_order')->get() as $exam) {
                $newExam = $this->copyExam($exam, $course->id, $sectionMap[$exam->section_id] ?? null, null, $report);
                $examMap[$exam->id] = $newExam->id;
                $report['exams']++;
            }

            // 3) الدروس ومحتوياتها.
            $lessonMap = [];
            foreach (Section::withoutTenant()->where('course_id', $source->id)->orderBy('sort_order')->get() as $section) {
                foreach (Lesson::withoutTenant()->where('section_id', $section->id)->orderBy('sort_order')->get() as $lesson) {
                    $newLesson = $this->copyLessonRow($lesson, $sectionMap[$section->id], $report, $examMap);
                    $lessonMap[$lesson->id] = $newLesson->id;
                    $report['lessons']++;
                    $this->copyLessonContents($lesson, $newLesson, $report);
                }
            }

            // 4) الواجبات (هيكل فقط — بلا تسليمات أو درجات).
            foreach (Assignment::withoutTenant()->where('course_id', $source->id)->orderBy('sort_order')->get() as $assignment) {
                $this->copyAssignment($assignment, $course->id, $sectionMap, $lessonMap);
                $report['assignments']++;
            }

            return ['model' => $course->fresh(), 'report' => $report];
        });
    }

    // ─────────────────────────────── Lesson ───────────────────────────────

    /**
     * @return array{model: Lesson, report: array}
     */
    public function duplicateLesson(Lesson $source, ?string $title = null): array
    {
        $source = $source->fresh() ?? $source;

        $report = $this->emptyReport();

        return DB::transaction(function () use ($source, $title, &$report) {
            $newLesson = Lesson::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $source->tenant_id,
                'section_id' => $source->section_id,
                'title' => $this->copyTitle($title, $source->title),
                'description' => $source->description,
                'duration_minutes' => $source->duration_minutes,
                'sort_order' => ((int) Lesson::withoutTenant()->where('section_id', $source->section_id)->max('sort_order')) + 1,
                'is_published' => false,
                'is_free_preview' => $source->is_free_preview,
                'lesson_type' => $source->lesson_type,
                'metadata' => $source->metadata,
            ]);
            $report['lessons'] = 1;

            $this->copyLessonContents($source, $newLesson, $report);

            // واجبات هذا الدرس تحديداً (هيكل فقط).
            foreach (Assignment::withoutTenant()->where('lesson_id', $source->id)->orderBy('sort_order')->get() as $assignment) {
                $this->copyAssignment($assignment, $assignment->course_id, [$source->section_id => $source->section_id], [$source->id => $newLesson->id]);
                $report['assignments']++;
            }

            return ['model' => $newLesson->fresh(), 'report' => $report];
        });
    }

    // ─────────────────────────────── Exam ───────────────────────────────

    /**
     * @return array{model: Exam, report: array}
     */
    public function duplicateExam(Exam $source, ?string $title = null): array
    {
        $source = $source->fresh() ?? $source;

        $report = $this->emptyReport();

        return DB::transaction(function () use ($source, $title, &$report) {
            $this->quota->assertCan($source->tenant_id, 'exams');

            $newExam = $this->copyExam($source, $source->course_id, $source->section_id, $title, $report);
            $report['exams'] = 1;

            return ['model' => $newExam, 'report' => $report];
        });
    }

    // ─────────────────────────────── Internals ───────────────────────────────

    private function copyExam(Exam $source, ?string $courseId, ?string $sectionId, ?string $title, array &$report): Exam
    {
        $exam = Exam::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $source->tenant_id,
            'course_id' => $courseId,
            'section_id' => $sectionId,
            'title' => $this->copyTitle($title, $source->title),
            'description' => $source->description,
            'duration_minutes' => $source->duration_minutes,
            'pass_mark_percent' => $source->pass_mark_percent,
            'max_attempts' => $source->max_attempts,
            'random_questions_count' => $source->random_questions_count,
            'anti_cheat_enabled' => $source->anti_cheat_enabled,
            'shuffle_questions' => $source->shuffle_questions,
            'shuffle_options' => $source->shuffle_options,
            'show_result_immediately' => $source->show_result_immediately,
            'show_correct_answers' => $source->show_correct_answers,
            'is_published' => false,
            // نسخة جديدة: لا نواريخ جدولة قديمة (كانت للنسخة الأصلية).
            'starts_at' => null,
            'ends_at' => null,
            'sort_order' => $source->sort_order,
            'metadata' => $source->metadata,
        ]);

        // أقسام الاختبار.
        $examSectionMap = [];
        foreach (ExamSection::withoutTenant()->where('exam_id', $source->id)->orderBy('sort_order')->get() as $examSection) {
            $newExamSection = ExamSection::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $source->tenant_id,
                'exam_id' => $exam->id,
                'title' => $examSection->title,
                'instructions' => $examSection->instructions,
                'sort_order' => $examSection->sort_order,
            ]);
            $examSectionMap[$examSection->id] = $newExamSection->id;
        }

        // الأسئلة: تُعاد ربطها من بنك الأسئلة (لا تُضاعف) — كل الأنواع والخيارات محفوظة.
        foreach (ExamQuestion::withoutTenant()->where('exam_id', $source->id)->orderBy('sort_order')->get() as $examQuestion) {
            if (! Question::withoutTenant()->whereKey($examQuestion->question_id)->exists()) {
                $report['skipped'][] = ['type' => 'question', 'title' => 'missing question'];

                continue;
            }

            ExamQuestion::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $source->tenant_id,
                'exam_id' => $exam->id,
                'exam_section_id' => $examSectionMap[$examQuestion->exam_section_id] ?? null,
                'question_id' => $examQuestion->question_id,
                'sort_order' => $examQuestion->sort_order,
                'points_override' => $examQuestion->points_override,
            ]);
            $report['questions_reused']++;
        }

        return $exam;
    }

    private function copyLessonRow(Lesson $source, string $sectionId, array &$report, array $examMap = []): Lesson
    {
        $metadata = $source->metadata ?? [];

        // تمرين الدرس (اختبار مرتبط) يجب أن يشير إلى نسخة الاختبار الجديدة
        // داخل نفس الكورس، لا إلى اختبار الكورس الأصلي.
        if (! empty($metadata['exercise_exam_id']) && isset($examMap[$metadata['exercise_exam_id']])) {
            $metadata['exercise_exam_id'] = $examMap[$metadata['exercise_exam_id']];
        }

        return Lesson::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $source->tenant_id,
            'section_id' => $sectionId,
            'title' => $source->title,
            'description' => $source->description,
            'duration_minutes' => $source->duration_minutes,
            'sort_order' => $source->sort_order,
            'is_published' => false,
            'is_free_preview' => $source->is_free_preview,
            'lesson_type' => $source->lesson_type,
            'metadata' => $metadata,
        ]);
    }

    private function copyLessonContents(Lesson $source, Lesson $target, array &$report): void
    {
        $contents = LessonContent::withoutTenant()
            ->where('lesson_id', $source->id)
            ->orderBy('sort_order')
            ->get();

        foreach ($contents as $content) {
            $newContent = LessonContent::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $target->tenant_id,
                'lesson_id' => $target->id,
                'content_type' => $content->content_type,
                'sort_order' => $content->sort_order,
                'is_required' => $content->is_required,
                'title' => $content->title,
                'metadata' => $this->stripContentIds($content->metadata),
            ]);

            match ($content->content_type) {
                'text' => $this->copyText($content, $newContent, $target, $report),
                'video' => $this->copyVideo($content, $newContent, $target, $report),
                'file' => $this->copyFile($content, $newContent, $target, $report),
                // بث مباشر: غرفة المزوّد/الجدولة لا يمكن نسخها بأمان — نتخطاها.
                'live' => $report['skipped'][] = ['type' => 'live', 'title' => $content->title ?? $source->title],
                default => $report['skipped'][] = ['type' => $content->content_type, 'title' => $content->title ?? $source->title],
            };
        }
    }

    private function copyText(LessonContent $content, LessonContent $target, Lesson $lesson, array &$report): void
    {
        $text = TextContent::withoutTenant()->where('lesson_content_id', $content->id)->first();

        if (! $text) {
            return;
        }

        TextContent::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $lesson->tenant_id,
            'lesson_content_id' => $target->id,
            'body' => $text->body,
            'format' => $text->format,
            'word_count' => $text->word_count,
            'reading_time_minutes' => $text->reading_time_minutes,
        ]);
        $report['contents']++;
    }

    private function copyVideo(LessonContent $content, LessonContent $target, Lesson $lesson, array &$report): void
    {
        $video = VideoContent::withoutTenant()->where('lesson_content_id', $content->id)->first();

        if (! $video) {
            return;
        }

        VideoContent::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $lesson->tenant_id,
            'lesson_content_id' => $target->id,
            'lesson_id' => $lesson->id,
            'provider' => $video->provider,
            // نفس الفيديو على المزوّد — بلا رفع أو نسخة Bunny ثانية.
            'provider_video_id' => $video->provider_video_id,
            'title' => $video->title,
            'description' => $video->description,
            'thumbnail_url' => $video->thumbnail_url,
            'duration' => $video->duration,
            'status' => $video->status,
            'file_size_bytes' => $video->file_size_bytes,
            'config' => $video->config,
            'storage_path' => $video->storage_path,
            'original_name' => $video->original_name,
            'mime_type' => $video->mime_type,
            'is_uploaded' => $video->is_uploaded,
            'is_shared_reference' => true,
        ]);
        $report['contents']++;
        $report['videos_reused']++;
    }

    private function copyFile(LessonContent $content, LessonContent $target, Lesson $lesson, array &$report): void
    {
        $file = FileContent::withoutTenant()->where('lesson_content_id', $content->id)->first();

        if (! $file) {
            return;
        }

        FileContent::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $lesson->tenant_id,
            'lesson_content_id' => $target->id,
            // نفس المسار على القرص — لا نسخ بايتات، ولا مساحة مضاعفة.
            'storage_path' => $file->storage_path,
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'size_bytes' => $file->size_bytes,
            'extension' => $file->extension,
            'is_downloadable' => $file->is_downloadable,
            'thumbnail_url' => $file->thumbnail_url,
            'metadata' => $this->stripContentIds($file->metadata),
            'is_shared_reference' => true,
        ]);
        $report['contents']++;
        $report['files_reused']++;
    }

    private function copyAssignment(Assignment $source, ?string $courseId, array $sectionMap, array $lessonMap): Assignment
    {
        return Assignment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $source->tenant_id,
            'course_id' => $courseId,
            'section_id' => $source->section_id ? ($sectionMap[$source->section_id] ?? null) : null,
            'lesson_id' => $source->lesson_id ? ($lessonMap[$source->lesson_id] ?? null) : null,
            'title' => $source->title,
            'description' => $source->description,
            'points' => $source->points,
            // نسخة جديدة = مسودة لن تطبيق جديد: لا نرث موعد تسليم النسخة
            // الأصلية (قد يكون في الماضي أو مرتبطاً بفصلٍ آخر) — يحدده المعلم.
            'due_at' => null,
            'allow_late' => $source->allow_late,
            // نسخة جديدة = مسودة.
            'is_published' => false,
            'sort_order' => $source->sort_order,
            'file_url' => $source->file_url,
            'file_name' => $source->file_name,
            'file_size_bytes' => $source->file_size_bytes,
            'metadata' => $source->metadata,
        ]);
    }

    /**
     * نسخة قديمة قد تحمل معرّفات محتوى الأصل داخل metadata (مثل media_asset_id
     * أو مسار الملف) — نزيلها حتى لا ترتبط النسخة بأصل أصلٍ قابل للحذف.
     */
    private function stripContentIds(?array $metadata): array
    {
        $metadata = $metadata ?? [];
        unset($metadata['media_asset_id'], $metadata['reservation_id'], $metadata['lesson_id']);

        return $metadata;
    }

    private function copyTitle(?string $title, string $fallback): string
    {
        $title = trim((string) $title);

        return $title !== '' ? $title : $fallback.' — نسخة';
    }

    private function uniqueSlug(string $tenantId, string $title): string
    {
        $base = Str::slug($title);
        $base = $base !== '' ? $base : 'copy';
        $slug = $base;

        while (Course::withoutTenant()->where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }

    private function emptyReport(): array
    {
        return [
            'sections' => 0,
            'lessons' => 0,
            'contents' => 0,
            'videos_reused' => 0,
            'files_reused' => 0,
            'exams' => 0,
            'questions_reused' => 0,
            'assignments' => 0,
            'skipped' => [],
        ];
    }
}
