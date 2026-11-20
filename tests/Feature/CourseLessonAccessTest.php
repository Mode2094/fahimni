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
 * /public/courses/{course}/lessons/{lesson} — بوابة محتوى الدرس.
 *
 * علة حقيقية: البوابة كانت تحسب "مسجّل" من صف Enrollment فقط، بخلاف
 * /sections التي تعتبر المالك/الطاقم مسجّلين تلقائياً. النتيجة: المعلم صاحب
 * الكورس يحصل على 403 عند معاينة درسه، والواجهة تُسقط الخطأ بصمت إلى
 * واجهة "درس نصي" بدل الفيديو.
 */
class CourseLessonAccessTest extends TenantTestCase
{
    private function makeCourseTree(Tenant $tenant): array
    {
        $course = Course::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'title' => 'Access Course',
            'slug' => 'acc-'.Str::random(6),
            'is_published' => true,
            'sort_order' => 0,
        ]);

        $section = Section::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'title' => 'S1',
            'is_published' => true,
            'sort_order' => 0,
        ]);

        $lesson = Lesson::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'section_id' => $section->id,
            'title' => 'Video lesson',
            'lesson_type' => 'video',
            'is_published' => true,
            'sort_order' => 0,
        ]);

        $lessonContent = LessonContent::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'lesson_id' => $lesson->id,
            'content_type' => 'video',
            'sort_order' => 0,
        ]);

        VideoContent::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'lesson_content_id' => $lessonContent->id,
            'lesson_id' => $lesson->id,
            'provider' => 'bunny',
            'provider_video_id' => 'bunny-video-'.Str::random(6),
            'is_uploaded' => false,
            'status' => 'ready',
            'title' => 'Lesson video',
        ]);

        return compact('course', 'section', 'lesson');
    }

    /** @test */
    public function test_course_owner_can_open_lesson_content_without_enrollment(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);

        $owner = $this->makeUser();
        $this->attachToTenant($owner, $tenant, 'owner');

        $response = $this->getJson(
            "/api/v1/public/courses/{$course->id}/lessons/{$lesson->id}",
            $this->authHeaders($owner, $tenant),
        );

        $response->assertOk();
        $this->assertSame('video', $response->json('contents.0.content_type'));
        $this->assertSame('provider', $response->json('contents.0.video.type'));
        $this->assertTrue($response->json('lesson.enrolled'), 'owner must be treated as enrolled');
    }

    /** @test */
    public function test_teacher_staff_can_open_lesson_content_without_enrollment(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);

        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $this->getJson(
            "/api/v1/public/courses/{$course->id}/lessons/{$lesson->id}",
            $this->authHeaders($teacher, $tenant),
        )->assertOk();
    }

    /** @test */
    public function test_enrolled_student_can_open_lesson_content(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        \App\Models\Enrollment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $this->getJson(
            "/api/v1/public/courses/{$course->id}/lessons/{$lesson->id}",
            $this->authHeaders($student, $tenant),
        )->assertOk();
    }

    /** @test */
    public function test_stranger_still_gets_403_for_a_locked_lesson(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'lesson' => $lesson] = $this->makeCourseTree($tenant);

        $stranger = $this->makeUser();
        $this->attachToTenant($stranger, $tenant, 'student');

        $this->getJson(
            "/api/v1/public/courses/{$course->id}/lessons/{$lesson->id}",
            $this->authHeaders($stranger, $tenant),
        )->assertStatus(403);
    }

    /** @test */
    public function test_cross_tenant_teacher_cannot_open_another_tenants_lesson(): void
    {
        $tenantA = $this->makeTenant();
        $this->attachSubscription($tenantA);
        ['course' => $courseA, 'lesson' => $lessonA] = $this->makeCourseTree($tenantA);

        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantB);
        $teacherB = $this->makeUser();
        $this->attachToTenant($teacherB, $tenantB, 'teacher');

        $this->getJson(
            "/api/v1/public/courses/{$courseA->id}/lessons/{$lessonA->id}",
            $this->authHeaders($teacherB, $tenantB),
        )->assertStatus(403);
    }
}
