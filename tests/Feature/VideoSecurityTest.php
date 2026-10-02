<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\PlanFeature;
use App\Models\Section;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\VideoContent;
use App\Services\VideoProtectionService;
use App\Support\TokenSigner;
use Illuminate\Support\Str;

/**
 * طبقة حماية الفيديو: Authorization، الأجهزة، العلامة المائية،
 * صلاحية الـ tokens (انتهاء / إعادة استخدام / ربط الجهاز)، والحصص.
 */
class VideoSecurityTest extends TenantTestCase
{
    private function makeCourseTree(Tenant $tenant, bool $lessonPublished = true, bool $coursePublished = true): array
    {
        $course = Course::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'title' => 'Security Course',
            'slug' => 'sec-'.Str::random(6),
            'is_published' => $coursePublished,
        ]);

        $section = Section::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'title' => 'S1',
            'is_published' => true,
            'sort_order' => 0,
        ]);

        $lesson = Lesson::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'section_id' => $section->id,
            'title' => 'L1',
            'lesson_type' => 'video',
            'is_published' => $lessonPublished,
            'sort_order' => 0,
        ]);

        $lessonContent = LessonContent::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'lesson_id' => $lesson->id,
            'content_type' => 'video',
            'sort_order' => 0,
        ]);

        $video = VideoContent::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'lesson_content_id' => $lessonContent->id,
            'lesson_id' => $lesson->id,
            'provider' => 'bunny',
            'provider_video_id' => 'bunny-video-'.Str::random(6),
            'is_uploaded' => false,
            'status' => 'ready',
            'title' => 'Protected Video',
        ]);

        return compact('course', 'section', 'lesson', 'video');
    }

    private function enroll(\App\Models\Tenant $tenant, User $student, Course $course, string $status = 'active'): Enrollment
    {
        return Enrollment::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => $status,
            'enrolled_at' => now(),
            'metadata' => [],
        ]);
    }

    private function addFeature(\App\Models\TenantSubscription $sub, string $key, string $value): void
    {
        PlanFeature::create([
            'id' => (string) Str::uuid(),
            'plan_id' => $sub->plan_id,
            'feature_key' => $key,
            'feature_value' => $value,
            'feature_type' => 'string',
            'sort_order' => 0,
        ]);
    }

    /** @test */
    public function test_enrolled_student_can_playback_but_watermark_off_by_default()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'video' => $video] = $this->makeCourseTree($tenant);

        $student = $this->makeUser(['name' => 'Student A']);
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        $headers = $this->authHeaders($student, $tenant) + ['X-Device-Id' => 'device-a'];
        $response = $this->getJson("/api/v1/videos/{$video->id}/playback", $headers);
        $response->assertOk();

        // الافتراضي: العلامة المائية معطلة (OFF) حتى يشغّلها المعلم.
        $this->assertSame(false, $response->json('playback.watermark.enabled'));
    }

    /** @test */
    public function test_stranger_without_enrollment_is_rejected()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['video' => $video] = $this->makeCourseTree($tenant);

        $stranger = $this->makeUser();
        $this->attachToTenant($stranger, $tenant, 'student');

        $headers = $this->authHeaders($stranger, $tenant) + ['X-Device-Id' => 'device-x'];
        $this->getJson("/api/v1/videos/{$video->id}/playback", $headers)->assertStatus(403);
    }

    /** @test */
    public function test_cross_tenant_teacher_cannot_access_other_tenant_video()
    {
        $tenantA = $this->makeTenant();
        $this->attachSubscription($tenantA);
        ['course' => $courseA, 'video' => $videoA] = $this->makeCourseTree($tenantA);

        // معلم من مؤسسة أخرى — لا صلاحية له على فيديوهات مؤسسة A إطلاقاً.
        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantB);
        $teacherB = $this->makeUser();
        $this->attachToTenant($teacherB, $tenantB, 'teacher');

        $headers = $this->authHeaders($teacherB, $tenantA) + ['X-Device-Id' => 'device-b'];
        $this->getJson("/api/v1/videos/{$videoA->id}/playback", $headers)->assertStatus(403);
    }

    /** @test */
    public function test_expired_enrollment_is_rejected()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'video' => $video] = $this->makeCourseTree($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course, 'expired');

        $headers = $this->authHeaders($student, $tenant) + ['X-Device-Id' => 'device-a'];
        $this->getJson("/api/v1/videos/{$video->id}/playback", $headers)->assertStatus(403);
    }

    /** @test */
    public function test_disabled_lesson_is_rejected()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'video' => $video] = $this->makeCourseTree($tenant, lessonPublished: false);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        $headers = $this->authHeaders($student, $tenant) + ['X-Device-Id' => 'device-a'];
        $this->getJson("/api/v1/videos/{$video->id}/playback", $headers)->assertStatus(403);
    }

    /** @test */
    public function test_device_limit_is_enforced_on_playback()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        TenantSetting::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'metadata' => ['device_policy_enabled' => true, 'device_policy_max_devices' => 1],
        ]);
        ['course' => $course, 'video' => $video] = $this->makeCourseTree($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        // الجهاز الأول مسموح
        $headers = $this->authHeaders($student, $tenant) + ['X-Device-Id' => 'device-1'];
        $this->getJson("/api/v1/videos/{$video->id}/playback", $headers)->assertOk();

        // الجهاز الثاني يتجاوز الحد → رفض صريح
        $headers2 = $this->authHeaders($student, $tenant) + ['X-Device-Id' => 'device-2'];
        $response = $this->getJson("/api/v1/videos/{$video->id}/playback", $headers2);
        $response->assertStatus(403);
        $this->assertSame('DEVICE_LIMIT_REACHED', $response->json('code'));
    }

    /** @test */
    public function test_teacher_cannot_enable_watermark_without_plan_feature()
    {
        $tenant = $this->makeTenant();
        $sub = $this->attachSubscription($tenant); // الخطة الأساسية بلا video_watermark

        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $headers = $this->authHeaders($teacher, $tenant);
        $response = $this->putJson('/api/v1/teacher/video-security', ['enabled' => true], $headers);
        $response->assertStatus(403);
        $this->assertSame('FEATURE_UNAVAILABLE', $response->json('code'));
    }

    /** @test */
    public function test_teacher_enables_watermark_and_student_sees_own_name()
    {
        $tenant = $this->makeTenant();
        $sub = $this->attachSubscription($tenant);
        $this->addFeature($sub, 'video_watermark', 'true');

        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $headers = $this->authHeaders($teacher, $tenant);
        $this->putJson('/api/v1/teacher/video-security', ['enabled' => true, 'show_id' => true], $headers)
            ->assertOk()
            ->assertJsonPath('settings.enabled', true);

        ['course' => $course, 'video' => $video] = $this->makeCourseTree($tenant);
        $studentA = $this->makeUser(['name' => 'Student A']);
        $this->attachToTenant($studentA, $tenant, 'student');
        $this->enroll($tenant, $studentA, $course);

        $response = $this->getJson(
            "/api/v1/videos/{$video->id}/playback",
            $this->authHeaders($studentA, $tenant) + ['X-Device-Id' => 'device-a'],
        );
        $response->assertOk();

        $wm = $response->json('playback.watermark');
        $this->assertSame(true, $wm['enabled']);
        $this->assertStringContainsString('Student A', $wm['text']);
        // بيانات طالب آخر غير موجودة أبداً
        $this->assertStringNotContainsString('Student B', $wm['text']);
    }

    /** @test */
    public function test_expired_stream_token_is_rejected()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'video' => $video] = $this->makeCourseTree($tenant);

        // فيديو مرفوع محلياً — يمر بفحص النوع قبل فحص الـ token.
        $video->update(['is_uploaded' => true, 'storage_path' => 'videos/nonexistent.mp4']);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        $service = app(VideoProtectionService::class);
        $payload = $service->signPayload($video->id, $student->id, -10); // منتهي منذ 10 ثوانٍ
        $encoded = explode('.', $payload)[0];
        $token = $encoded.'.'.TokenSigner::sign($encoded);

        $this->getJson("/api/v1/stream/{$video->id}?token={$token}")->assertStatus(403);
    }

    /** @test */
    public function test_stream_token_bound_to_device_cannot_be_used_from_other_device()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'video' => $video] = $this->makeCourseTree($tenant);

        // فيديو مرفوع محلياً — يمر بفحص النوع قبل فحص الـ token.
        $video->update(['is_uploaded' => true, 'storage_path' => 'videos/nonexistent.mp4']);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        $deviceA = hash('sha256', 'device-a');
        $service = app(VideoProtectionService::class);
        $url = $service->streamUrl($video, $student, deviceHash: $deviceA);
        $token = null;
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $q);
        $token = $q['token'] ?? null;
        $this->assertNotNull($token);

        // نفس الرابط بلا معامل الجهاز → مرفوض
        $this->getJson("/api/v1/stream/{$video->id}?token={$token}")->assertStatus(403);

        // مع جهاز خاطئ → مرفوض
        $this->getJson("/api/v1/stream/{$video->id}?token={$token}&d=".hash('sha256', 'device-b'))->assertStatus(403);

        // مع الجهاز الصحيح → يسمح (الملف غير موجود فعلياً → 404)
        $this->getJson("/api/v1/stream/{$video->id}?token={$token}&d={$deviceA}")->assertStatus(404);
    }

    /** @test */
    public function test_quota_charged_once_per_playback_session()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'video' => $video] = $this->makeCourseTree($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        $headers = $this->authHeaders($student, $tenant) + ['X-Device-Id' => 'device-a'];

        $this->getJson("/api/v1/videos/{$video->id}/playback", $headers)->assertOk();
        // طلب متكرر خلال نافذة الجلسة → لا خصم إضافي
        $this->getJson("/api/v1/videos/{$video->id}/playback", $headers)->assertOk();
        $this->getJson("/api/v1/videos/{$video->id}/playback", $headers)->assertOk();

        $delta = \App\Models\MediaUsageLedger::where('tenant_id', $tenant->id)
            ->where('resource', 'delivery_minutes')
            ->sum('delta');

        $this->assertSame(1, (int) $delta, 'Quota must be charged once per playback session window');
    }
}
