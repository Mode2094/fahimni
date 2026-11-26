<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Section;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * إكمال الدروس: سياسة المشاهدة الحقيقية (~90% من مدة الفيديو) وتقدّم الكورس.
 *
 * The customer saw lessons stuck at 0% after watching. The server engine was
 * already the authority; what was missing was (a) a sane, genuine-coverage
 * watch policy and (b) the client ever calling it. These tests lock the server
 * policy down: a video lesson needs ~90% watched, seeking to the end does not
 * count, a non-video lesson needs nothing, and completion is idempotent.
 */
class LessonProgressCompletionTest extends TenantTestCase
{
    private Tenant $tenant;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant();
        $this->attachSubscription($this->tenant);
        $this->student = $this->makeUser();
        $this->attachToTenant($this->student, $this->tenant, 'student');
    }

    private function courseWithLesson(string $type, int $durationMinutes = 0): array
    {
        $course = Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'title' => 'Physics', 'slug' => 'phys-'.Str::random(6),
            'is_published' => true, 'sort_order' => 0,
        ]);

        $section = Section::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $course->id, 'title' => 'Unit', 'is_published' => true, 'sort_order' => 0,
        ]);

        $lesson = Lesson::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'section_id' => $section->id, 'title' => 'L1', 'lesson_type' => $type,
            'duration_minutes' => $durationMinutes, 'is_published' => true, 'sort_order' => 0,
        ]);

        $enrollment = Enrollment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $this->tenant->id,
            'course_id' => $course->id, 'user_id' => $this->student->id,
            'status' => 'active', 'progress_percent' => 0, 'enrolled_at' => now(), 'metadata' => [],
        ]);

        return compact('course', 'section', 'lesson', 'enrollment');
    }

    private function complete(array $tree, int $watchSeconds)
    {
        return $this->withHeaders($this->authHeaders($this->student, $this->tenant))
            ->putJson("/api/v1/enrollments/{$tree['enrollment']->id}/progress/{$tree['lesson']->id}", [
                'status' => 'completed',
                'watch_time_seconds' => $watchSeconds,
            ]);
    }

    private function progressRow(string $enrollmentId, string $lessonId): ?LessonProgress
    {
        return LessonProgress::withoutTenant()
            ->where('enrollment_id', $enrollmentId)
            ->where('lesson_id', $lessonId)
            ->first();
    }

    public function test_a_video_lesson_requires_genuine_near_full_coverage_not_a_seek(): void
    {
        // 10-minute lesson → ~90% = 540s required.
        $tree = $this->courseWithLesson('video', 10);

        // A quick seek-to-the-end report (5s) is refused.
        $this->complete($tree, 5)->assertStatus(422);

        $this->assertNull($this->progressRow($tree['enrollment']->id, $tree['lesson']->id));

        // Genuine coverage reaches the bar.
        $this->complete($tree, 540)->assertOk();

        $row = $this->progressRow($tree['enrollment']->id, $tree['lesson']->id);
        $this->assertSame('completed', $row->status);
        $this->assertNotNull($row->completed_at);
        $this->assertSame(540, (int) $row->watch_time_seconds);

        // Progress percent is recomputed on the enrollment.
        $this->assertSame(100.0, (float) $tree['enrollment']->fresh()->progress_percent);
    }

    public function test_a_text_lesson_needs_no_watch_time(): void
    {
        $tree = $this->courseWithLesson('text', 0);

        $this->complete($tree, 0)->assertOk();

        $this->assertSame('completed', $this->progressRow($tree['enrollment']->id, $tree['lesson']->id)->status);
    }

    public function test_completion_is_idempotent_and_never_double_awards(): void
    {
        $tree = $this->courseWithLesson('text', 0);

        $this->complete($tree, 0)->assertOk();
        $this->complete($tree, 0)->assertOk();

        $this->assertSame(1, LessonProgress::withoutTenant()
            ->where('enrollment_id', $tree['enrollment']->id)
            ->where('lesson_id', $tree['lesson']->id)
            ->count());
    }

    public function test_the_public_curriculum_exposes_the_watch_policy_and_completion_to_the_enrolled_student(): void
    {
        $tree = $this->courseWithLesson('video', 10);

        $res = $this->withHeaders($this->authHeaders($this->student, $this->tenant))
            ->getJson("/api/v1/public/courses/{$tree['course']->id}/sections");

        $res->assertOk()
            ->assertJsonPath('enrollment_id', $tree['enrollment']->id)
            ->assertJsonPath('sections.0.lessons.0.completed', false)
            ->assertJsonPath('sections.0.lessons.0.required_watch_seconds', 540);

        // After completion the same payload reports it done.
        $this->complete($tree, 600)->assertOk();

        $this->withHeaders($this->authHeaders($this->student, $this->tenant))
            ->getJson("/api/v1/public/courses/{$tree['course']->id}/sections")
            ->assertOk()
            ->assertJsonPath('sections.0.lessons.0.completed', true);
    }

    public function test_a_guest_curriculum_never_leaks_completion_or_an_enrollment(): void
    {
        $tree = $this->courseWithLesson('video', 10);

        $res = $this->getJson("/api/v1/public/courses/{$tree['course']->id}/sections");

        $res->assertOk()
            ->assertJsonPath('enrollment_id', null)
            ->assertJsonPath('sections.0.lessons.0.completed', null);
    }
}
