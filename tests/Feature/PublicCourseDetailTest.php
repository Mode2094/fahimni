<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\Section;
use App\Models\Tenant;
use App\Models\VideoContent;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * /api/v1/public/{slug}/courses/{course} — the public course page.
 *
 * Guarantees: a course is only ever returned for its OWN active tenant slug and
 * only when published, the curriculum exposes real lesson metadata, and a guest
 * preview can never surface a private (signed/user-bound) stream URL.
 */
class PublicCourseDetailTest extends TenantTestCase
{
    private function makeCourse(Tenant $tenant, array $attrs = []): Course
    {
        return Course::create(array_merge([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'title' => 'Public Course',
            'slug' => 'pub-'.Str::random(6),
            'short_description' => 'short',
            'is_published' => true,
            'price' => 49,
            'currency' => 'USD',
            'sort_order' => 0,
        ], $attrs));
    }

    private function addLesson(Tenant $tenant, Course $course, bool $freePreview, ?string $provider = null): Lesson
    {
        $section = Section::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'title' => 'Unit',
            'is_published' => true,
            'sort_order' => 0,
        ]);

        $lesson = Lesson::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'section_id' => $section->id,
            'title' => $freePreview ? 'Free lesson' : 'Locked lesson',
            'lesson_type' => 'video',
            'is_free_preview' => $freePreview,
            'is_published' => true,
            'sort_order' => 0,
        ]);

        if ($provider !== null) {
            $content = LessonContent::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenant->id,
                'lesson_id' => $lesson->id,
                'content_type' => 'video',
                'sort_order' => 0,
            ]);

            VideoContent::create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenant->id,
                'lesson_content_id' => $content->id,
                'lesson_id' => $lesson->id,
                'provider' => $provider,
                'provider_video_id' => $provider === 'youtube' ? 'dQw4w9WgXcQ' : 'bunny-'.Str::random(6),
                'is_uploaded' => false,
                'status' => 'ready',
                'title' => 'Video',
            ]);
        }

        return $lesson;
    }

    /** @test */
    public function test_published_course_is_returned_with_its_curriculum(): void
    {
        $tenant = $this->makeTenant();
        $course = $this->makeCourse($tenant);
        $this->addLesson($tenant, $course, true);

        $res = $this->getJson("/api/v1/public/{$tenant->slug}/courses/{$course->id}");

        $res->assertOk();
        $this->assertSame($course->id, $res->json('course.id'));
        $this->assertSame('Public Course', $res->json('course.title'));
        $this->assertSame(1, $res->json('course.sections_count') ?? count($res->json('sections')));
        $this->assertSame('Free lesson', $res->json('sections.0.lessons.0.title'));
        $this->assertTrue($res->json('sections.0.lessons.0.is_free_preview'));
        $this->assertFalse($res->json('sections.0.lessons.0.locked'));
    }

    /** @test */
    public function test_unpublished_course_is_not_found(): void
    {
        $tenant = $this->makeTenant();
        $course = $this->makeCourse($tenant, ['is_published' => false]);

        $this->getJson("/api/v1/public/{$tenant->slug}/courses/{$course->id}")
            ->assertStatus(404);
    }

    /** @test */
    public function test_a_course_from_another_tenant_is_never_leaked_under_this_slug(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $courseB = $this->makeCourse($tenantB);

        // Tenant B's published course requested under tenant A's slug → 404.
        $this->getJson("/api/v1/public/{$tenantA->slug}/courses/{$courseB->id}")
            ->assertStatus(404);
    }

    /** @test */
    public function test_unknown_slug_is_not_found(): void
    {
        $tenant = $this->makeTenant();
        $course = $this->makeCourse($tenant);

        $this->getJson("/api/v1/public/does-not-exist/courses/{$course->id}")
            ->assertStatus(404);
    }

    /** @test */
    public function test_guest_free_preview_of_external_video_exposes_the_public_id_only(): void
    {
        $tenant = $this->makeTenant();
        $course = $this->makeCourse($tenant);
        $lesson = $this->addLesson($tenant, $course, true, 'youtube');

        $res = $this->getJson("/api/v1/public/courses/{$course->id}/lessons/{$lesson->id}");

        $res->assertOk();
        $this->assertSame('dQw4w9WgXcQ', $res->json('contents.0.video.provider_video_id'));
        $this->assertFalse($res->json('contents.0.video.requires_login'));
        $this->assertNull($res->json('contents.0.video.stream_url'));
    }

    /** @test */
    public function test_guest_free_preview_of_hosted_video_requires_login_and_never_leaks_a_stream_url(): void
    {
        $tenant = $this->makeTenant();
        $course = $this->makeCourse($tenant);
        $lesson = $this->addLesson($tenant, $course, true, 'bunny');

        $res = $this->getJson("/api/v1/public/courses/{$course->id}/lessons/{$lesson->id}");

        $res->assertOk();
        $this->assertTrue($res->json('contents.0.video.requires_login'));
        $this->assertNull($res->json('contents.0.video.stream_url'));
        $this->assertNull($res->json('contents.0.video.provider_video_id'));
    }

    /** @test */
    public function test_guest_cannot_open_a_locked_lesson(): void
    {
        $tenant = $this->makeTenant();
        $course = $this->makeCourse($tenant);
        $lesson = $this->addLesson($tenant, $course, false, 'youtube');

        $this->getJson("/api/v1/public/courses/{$course->id}/lessons/{$lesson->id}")
            ->assertStatus(403);
    }
}
