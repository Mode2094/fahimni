<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\LessonProgress;
use App\Models\Section;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VideoContent;
use App\Services\VideoWatchPositionService;
use Illuminate\Support\Str;

/**
 * موضع مشاهدة الفيديو (الاستكمال): الحفظ الدوري/عند الخروج، الاسترجاع بين
 * الأجهزة، عدم المساس باحتساب الدقائق أو الإكمال، العزل بين الطلاب، تغيّر مدة
 * الفيديو، الفيديو المنتهي، والصلاحيات.
 */
class VideoWatchPositionTest extends TenantTestCase
{
    private function makeCourseTree(Tenant $tenant, int $duration = 600): array
    {
        $course = Course::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'title' => 'Course',
            'slug' => 'wp-'.Str::random(6),
            'is_published' => true,
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
            'is_published' => true,
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
            'duration' => $duration,
        ]);

        return compact('course', 'section', 'lesson', 'video');
    }

    private function enroll(Tenant $tenant, User $student, Course $course, string $status = 'active'): Enrollment
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

    private function progressRow(string $enrollmentId, string $lessonId): ?LessonProgress
    {
        return LessonProgress::withoutTenant()
            ->where('enrollment_id', $enrollmentId)
            ->where('lesson_id', $lessonId)
            ->first();
    }

    /** @test */
    public function test_student_saves_position_and_resume_is_returned_without_touching_watch_time_or_completion()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'lesson' => $lesson, 'video' => $video] = $this->makeCourseTree($tenant);

        $student = $this->makeUser(['name' => 'Student A']);
        $this->attachToTenant($student, $tenant, 'student');
        $enrollment = $this->enroll($tenant, $student, $course);

        $headers = $this->authHeaders($student, $tenant) + ['X-Device-Id' => 'device-a'];
        $this->postJson("/api/v1/videos/{$video->id}/position", [
            'position_seconds' => 120,
            'duration_seconds' => 600,
        ], $headers)->assertOk()->assertJsonPath('ok', true)->assertJsonPath('position.position_seconds', 120);

        // يُخزَّن داخل نظام التقدّم القائم (metadata) دون صفوف جديدة.
        $row = $this->progressRow($enrollment->id, $lesson->id);
        $this->assertNotNull($row);
        $this->assertSame(120, (int) $row->metadata['video_positions'][$video->id]['s']);
        $this->assertSame(600, (int) $row->metadata['video_positions'][$video->id]['d']);

        // لا نلمس دقائق المشاهدة ولا الإكمال — مجرّد استئناف.
        $this->assertSame(0, (int) $row->watch_time_seconds);
        $this->assertSame('in_progress', $row->status);
        $this->assertNull($row->completed_at);

        // الاستكمال يظهر للطالب نفسه في حِمل التشغيل.
        $this->getJson("/api/v1/videos/{$video->id}/playback", $headers)
            ->assertOk()
            ->assertJsonPath('playback.resume.position_seconds', 120);
    }

    /** @test */
    public function test_position_is_monotonic_across_devices_so_a_late_update_never_loses_progress()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'lesson' => $lesson, 'video' => $video] = $this->makeCourseTree($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $enrollment = $this->enroll($tenant, $student, $course);

        $headersA = $this->authHeaders($student, $tenant) + ['X-Device-Id' => 'device-a'];
        $this->postJson("/api/v1/videos/{$video->id}/position", ['position_seconds' => 120, 'duration_seconds' => 600], $headersA)->assertOk();

        // جهاز آخر يتقدم أكثر.
        $headersB = $this->authHeaders($student, $tenant) + ['X-Device-Id' => 'device-b'];
        $this->postJson("/api/v1/videos/{$video->id}/position", ['position_seconds' => 300, 'duration_seconds' => 600], $headersB)->assertOk();

        // تحديث متأخر/متزامن من الجهاز الأول بموضع أقل — لا يُتراجع للخلف.
        $this->postJson("/api/v1/videos/{$video->id}/position", ['position_seconds' => 180, 'duration_seconds' => 600], $headersA)->assertOk();

        $row = $this->progressRow($enrollment->id, $lesson->id);
        $this->assertSame(300, (int) $row->metadata['video_positions'][$video->id]['s']);

        $this->getJson("/api/v1/videos/{$video->id}/playback", $headersA)
            ->assertOk()
            ->assertJsonPath('playback.resume.position_seconds', 300);
    }

    /** @test */
    public function test_a_student_cannot_read_or_overwrite_another_students_position()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'lesson' => $lesson, 'video' => $video] = $this->makeCourseTree($tenant);

        $studentA = $this->makeUser(['name' => 'A']);
        $studentB = $this->makeUser(['name' => 'B']);
        $this->attachToTenant($studentA, $tenant, 'student');
        $this->attachToTenant($studentB, $tenant, 'student');
        $enrollmentA = $this->enroll($tenant, $studentA, $course);
        $enrollmentB = $this->enroll($tenant, $studentB, $course);

        $headersA = $this->authHeaders($studentA, $tenant) + ['X-Device-Id' => 'a'];
        $this->postJson("/api/v1/videos/{$video->id}/position", ['position_seconds' => 400, 'duration_seconds' => 600], $headersA)->assertOk();

        // B لا يرى موضع A.
        $headersB = $this->authHeaders($studentB, $tenant) + ['X-Device-Id' => 'b'];
        $this->getJson("/api/v1/videos/{$video->id}/playback", $headersB)
            ->assertOk()
            ->assertJsonPath('playback.resume', null);

        // B يحفظ موضعه الخاص — صف مستقل.
        $this->postJson("/api/v1/videos/{$video->id}/position", ['position_seconds' => 60, 'duration_seconds' => 600], $headersB)->assertOk();

        $this->assertSame(400, (int) $this->progressRow($enrollmentA->id, $lesson->id)->metadata['video_positions'][$video->id]['s']);
        $this->assertSame(60, (int) $this->progressRow($enrollmentB->id, $lesson->id)->metadata['video_positions'][$video->id]['s']);
    }

    /** @test */
    public function test_unenrolled_and_expired_students_cannot_save_position()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'video' => $video] = $this->makeCourseTree($tenant);

        $stranger = $this->makeUser();
        $this->attachToTenant($stranger, $tenant, 'student');
        $this->postJson("/api/v1/videos/{$video->id}/position", ['position_seconds' => 30], $this->authHeaders($stranger, $tenant))
            ->assertStatus(403);

        $expired = $this->makeUser();
        $this->attachToTenant($expired, $tenant, 'student');
        $this->enroll($tenant, $expired, $course)->update(['expires_at' => now()->subDay()]);
        $this->postJson("/api/v1/videos/{$video->id}/position", ['position_seconds' => 30], $this->authHeaders($expired, $tenant))
            ->assertStatus(403);
    }

    /** @test */
    public function test_validates_the_payload()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'video' => $video] = $this->makeCourseTree($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        $this->postJson("/api/v1/videos/{$video->id}/position", [], $this->authHeaders($student, $tenant))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['position_seconds']);
    }

    /** @test */
    public function test_a_staff_viewer_without_enrollment_saves_nothing_but_is_not_an_error()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['video' => $video] = $this->makeCourseTree($tenant);

        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        // يمكنه الوصول (موظّف) لكن لا تسجيل له → لا نقطة استئناف، وبلا استثناء.
        $this->postJson("/api/v1/videos/{$video->id}/position", ['position_seconds' => 90], $this->authHeaders($teacher, $tenant))
            ->assertOk()
            ->assertJsonPath('ok', false);
    }

    /** @test */
    public function test_stale_position_is_ignored_when_the_video_duration_changes()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'video' => $video] = $this->makeCourseTree($tenant, 600);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant, $student, $course);

        $headers = $this->authHeaders($student, $tenant);
        $this->postJson("/api/v1/videos/{$video->id}/position", ['position_seconds' => 120, 'duration_seconds' => 600], $headers)->assertOk();

        // أُعيد رفع الفيديو بمدة مختلفة → الموضع القديم غير موثوق.
        $video->update(['duration' => 9000]);

        $this->getJson("/api/v1/videos/{$video->id}/playback", $this->authHeaders($student, $tenant))
            ->assertOk()
            ->assertJsonPath('playback.resume', null);
    }

    /** @test */
    public function test_a_finished_video_is_not_offered_for_resume_and_is_not_marked_completed()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'lesson' => $lesson, 'video' => $video] = $this->makeCourseTree($tenant, 600);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $enrollment = $this->enroll($tenant, $student, $course);

        $headers = $this->authHeaders($student, $tenant);
        $this->postJson("/api/v1/videos/{$video->id}/position", ['position_seconds' => 600, 'duration_seconds' => 600], $headers)->assertOk();

        // بلوغ النهاية ليس دليل إكمال: لا استئناف عند آخر الفيديو، والدرس ليس مكتملاً.
        $this->getJson("/api/v1/videos/{$video->id}/playback", $this->authHeaders($student, $tenant))
            ->assertOk()
            ->assertJsonPath('playback.resume', null);

        $row = $this->progressRow($enrollment->id, $lesson->id);
        $this->assertSame('in_progress', $row->status);
        $this->assertNull($row->completed_at);
    }

    /** @test */
    public function test_cross_tenant_video_is_rejected()
    {
        $tenantA = $this->makeTenant();
        $this->attachSubscription($tenantA);
        ['course' => $courseA, 'video' => $videoA] = $this->makeCourseTree($tenantA);
        $studentA = $this->makeUser();
        $this->attachToTenant($studentA, $tenantA, 'student');
        $this->enroll($tenantA, $studentA, $courseA);

        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantB);
        $studentB = $this->makeUser();
        $this->attachToTenant($studentB, $tenantB, 'student');

        // طالب من مؤسسة B لا يصل لفيديو مؤسسة A.
        $this->postJson("/api/v1/videos/{$videoA->id}/position", ['position_seconds' => 30], $this->authHeaders($studentB, $tenantB))
            ->assertStatus(403);
    }

    /** @test */
    public function test_mobile_lesson_exposes_resume_and_mobile_endpoint_saves_position()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'lesson' => $lesson, 'video' => $video] = $this->makeCourseTree($tenant, 600);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $enrollment = $this->enroll($tenant, $student, $course);

        $headers = $this->authHeaders($student, $tenant) + ['X-Device-Id' => 'flutter-abc'];
        $this->postJson('/api/v1/mobile/video-position', [
            'video_id' => $video->id,
            'position_seconds' => 240,
            'duration_seconds' => 600,
        ], $headers)->assertOk()->assertJsonPath('ok', true);

        $row = $this->progressRow($enrollment->id, $lesson->id);
        $this->assertSame(240, (int) $row->metadata['video_positions'][$video->id]['s']);

        // حِمل الدرس على التطبيق يحمل الموضع ليستكمل من حيث توقف.
        $this->getJson("/api/v1/mobile/lessons/{$lesson->id}", $this->authHeaders($student, $tenant))
            ->assertOk()
            ->assertJsonPath('contents.0.video.resume.position_seconds', 240);
    }

    /** @test */
    public function test_mobile_position_rejects_a_member_without_enrollment_and_unknown_videos()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'video' => $video] = $this->makeCourseTree($tenant);

        // عضو فعّال في المؤسسة لكن بلا تسجيل في الكورس → لا صلاحية (403).
        $member = $this->makeUser();
        $this->attachToTenant($member, $tenant, 'student');

        $this->postJson('/api/v1/mobile/video-position', [
            'video_id' => $video->id,
            'position_seconds' => 30,
        ], $this->authHeaders($member, $tenant))->assertStatus(403);

        // فيديو مؤسسة أخرى غير مرئي أصلاً (404).
        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantB);
        $memberB = $this->makeUser();
        $this->attachToTenant($memberB, $tenantB, 'student');
        $this->postJson('/api/v1/mobile/video-position', [
            'video_id' => $video->id,
            'position_seconds' => 30,
        ], $this->authHeaders($memberB, $tenantB))->assertStatus(404);
    }

    /** @test */
    public function test_updating_lesson_progress_does_not_wipe_a_saved_video_position()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['course' => $course, 'lesson' => $lesson, 'video' => $video] = $this->makeCourseTree($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $enrollment = $this->enroll($tenant, $student, $course);

        $headers = $this->authHeaders($student, $tenant);
        $this->postJson("/api/v1/videos/{$video->id}/position", ['position_seconds' => 200, 'duration_seconds' => 600], $headers)->assertOk();

        // تحديث الحالة/الدقائق من عميل لا يعرف بموضع المشاهدة لا يجب أن يمسحه.
        $this->putJson("/api/v1/enrollments/{$enrollment->id}/progress/{$lesson->id}", [
            'status' => 'in_progress',
            'watch_time_seconds' => 200,
        ], $headers)->assertOk();

        $row = $this->progressRow($enrollment->id, $lesson->id);
        $this->assertSame(200, (int) $row->metadata['video_positions'][$video->id]['s']);

        $this->getJson("/api/v1/videos/{$video->id}/playback", $this->authHeaders($student, $tenant))
            ->assertOk()
            ->assertJsonPath('playback.resume.position_seconds', 200);
    }

    /** @test */
    public function test_service_is_a_noop_without_an_enrollment_and_never_throws()
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        ['video' => $video] = $this->makeCourseTree($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        // لا تسجيل → لا استثناء، فقط null (التشغيل يكمل)، ولا صف تقدّم يُنشأ.
        $result = app(VideoWatchPositionService::class)->save($video, $student, 100, 600);
        $this->assertNull($result);
        $this->assertSame(0, LessonProgress::withoutTenant()->count());
    }
}
