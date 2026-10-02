<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\LiveSession;
use App\Models\Section;
use App\Services\FileProtectionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

class RoleAuthorizationTest extends TenantTestCase
{
    private function makeCourse(string $tenantId): Course
    {
        return Course::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'title' => 'Test Course',
            'slug' => 'test-course-'.Str::random(6),
            'is_published' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_student_cannot_update_tenant_file_policy(): void
    {
        $student = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($student, $tenant, 'student');

        $response = $this->putJson('/api/v1/teacher/file-policy', ['file_downloads_enabled' => false], $this->authHeaders($student, $tenant));

        $response->assertStatus(403);
    }

    public function test_teacher_can_update_tenant_file_policy(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $response = $this->putJson('/api/v1/teacher/file-policy', ['file_downloads_enabled' => false], $this->authHeaders($teacher, $tenant));

        $response->assertOk();
        $response->assertJsonPath('policy.file_downloads_enabled', false);
    }

    public function test_live_session_hides_host_start_url_from_students(): void
    {
        $tenant = $this->makeTenant();

        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $course = $this->makeCourse($tenant->id);
        $section = Section::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'title' => 'S1', 'sort_order' => 0]);
        $lesson = Lesson::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'section_id' => $section->id, 'title' => 'L1', 'sort_order' => 0]);
        $content = LessonContent::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'lesson_id' => $lesson->id, 'content_type' => 'live', 'sort_order' => 0, 'title' => 'Live']);

        $session = LiveSession::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'lesson_content_id' => $content->id,
            'title' => 'Live class',
            'provider' => 'zoom',
            'room_name' => 'room-1',
            'status' => 'scheduled',
            'max_participants' => 50,
            'record_enabled' => false,
            'config' => [],
            'metadata' => [
                'meeting_url' => 'https://zoom.us/j/123',
                'meeting_id' => '123456789',
                'meeting_password' => 'secret123',
                'start_url' => 'https://zoom.us/s/start?secret=host-only',
            ],
        ]);

        // A student who is NOT enrolled in the course gets no join link at all.
        $studentView = $this->getJson("/api/v1/live-sessions/{$session->id}", $this->authHeaders($student, $tenant));

        $studentView->assertOk();
        $studentView->assertJsonMissing(['start_url' => 'https://zoom.us/s/start?secret=host-only']);
        $studentView->assertJsonMissing(['meeting_id' => '123456789']);
        $studentView->assertJsonMissing(['meeting_url' => 'https://zoom.us/j/123']);
        $studentView->assertJsonMissing(['meeting_password' => 'secret123']);

        // Once enrolled, the student receives the join URL (but never the
        // host-only start_url / meeting_id / password).
        Enrollment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $this->app['auth']->forgetGuards();

        $enrolledView = $this->getJson("/api/v1/live-sessions/{$session->id}", $this->authHeaders($student, $tenant));

        $enrolledView->assertOk();
        $enrolledView->assertJsonPath('session.meeting_url', 'https://zoom.us/j/123');
        $enrolledView->assertJsonMissing(['start_url' => 'https://zoom.us/s/start?secret=host-only']);
        $enrolledView->assertJsonMissing(['meeting_id' => '123456789']);
        $enrolledView->assertJsonMissing(['meeting_password' => 'secret123']);

        $teacherView = $this->getJson("/api/v1/live-sessions/{$session->id}", $this->authHeaders($teacher, $tenant));

        $teacherView->assertOk();
        $teacherView->assertJsonPath('session.start_url', 'https://zoom.us/s/start?secret=host-only');
    }

    public function test_student_cannot_upload_files_into_tenant_lessons(): void
    {
        $tenant = $this->makeTenant();

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $course = $this->makeCourse($tenant->id);
        $section = Section::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'title' => 'S1', 'sort_order' => 0]);
        $lesson = Lesson::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'section_id' => $section->id, 'title' => 'L1', 'sort_order' => 0]);

        $response = $this->withHeaders($this->authHeaders($student, $tenant))
            ->post('/api/v1/upload', [
                'lesson_id' => $lesson->id,
                'file' => UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF"),
            ]);

        $response->assertStatus(403);
    }

    public function test_teacher_can_upload_files_into_own_lessons(): void
    {
        $tenant = $this->makeTenant();

        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $course = $this->makeCourse($tenant->id);
        $section = Section::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'title' => 'S1', 'sort_order' => 0]);
        $lesson = Lesson::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'section_id' => $section->id, 'title' => 'L1', 'sort_order' => 0]);

        Storage::fake('public');

        $response = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->post('/api/v1/upload', [
                'lesson_id' => $lesson->id,
                'file' => UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF"),
            ]);

        $response->assertStatus(201);
    }

    public function test_teacher_cannot_upload_into_another_tenants_lesson(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        $teacherA = $this->makeUser();
        $this->attachToTenant($teacherA, $tenantA, 'teacher');

        $courseB = $this->makeCourse($tenantB->id);
        $sectionB = Section::create(['id' => UuidV7::generate(), 'tenant_id' => $tenantB->id, 'course_id' => $courseB->id, 'title' => 'S1', 'sort_order' => 0]);
        $lessonB = Lesson::create(['id' => UuidV7::generate(), 'tenant_id' => $tenantB->id, 'section_id' => $sectionB->id, 'title' => 'L1', 'sort_order' => 0]);

        $response = $this->withHeaders($this->authHeaders($teacherA, $tenantA))
            ->post('/api/v1/upload', [
                'lesson_id' => $lessonB->id,
                'file' => UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF"),
            ]);

        $response->assertStatus(404);
    }

    public function test_student_is_not_treated_as_tenant_staff_for_file_access(): void
    {
        $tenant = $this->makeTenant();

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $course = $this->makeCourse($tenant->id);
        $section = Section::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'title' => 'S1', 'sort_order' => 0]);
        $lesson = Lesson::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'section_id' => $section->id, 'title' => 'L1', 'sort_order' => 0, 'is_free_preview' => false]);

        $service = app(FileProtectionService::class);

        // Not enrolled and not free-preview: no access — even though the
        // student is an active member of the tenant.
        $this->assertFalse($service->canAccess($lesson, $student));

        // An actual tenant staff member gets access.
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->assertTrue($service->canAccess($lesson, $teacher));
    }
}
