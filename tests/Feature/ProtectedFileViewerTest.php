<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\FileContent;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\Section;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\FileProtectionService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * In-app protected file viewer (Package F).
 *
 * Contract: the ticket hands out separate inline/attachment URLs only for what
 * the policy actually allows; the media endpoint re-checks everything, so a
 * hand-edited `mode` can never reveal a document that is neither previewable
 * nor downloadable, nor force a download the teacher disabled.
 */
class ProtectedFileViewerTest extends TenantTestCase
{
    private function makeCourse(Tenant $tenant): Course
    {
        return Course::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'title' => 'Files Course',
            'slug' => 'files-'.Str::random(6),
            'is_published' => true,
            'sort_order' => 0,
        ]);
    }

    private function makeLesson(Tenant $tenant, Course $course): Lesson
    {
        $section = Section::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'title' => 'S1',
            'sort_order' => 0,
        ]);

        return Lesson::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'section_id' => $section->id,
            'title' => 'L1',
            'sort_order' => 0,
        ]);
    }

    /** Create a lesson carrying one file with the given name/extension. */
    private function makeFileLesson(Tenant $tenant, string $filename, string $ext, bool $downloadable = true, string $mime = 'application/octet-stream'): Lesson
    {
        $course = $this->makeCourse($tenant);
        $lesson = $this->makeLesson($tenant, $course);

        $content = LessonContent::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'lesson_id' => $lesson->id,
            'content_type' => 'file',
            'sort_order' => 0,
            'title' => 'file',
        ]);

        $path = 'lesson-files/'.$tenant->id.'/'.$filename;
        Storage::disk('local')->put($path, 'binary-content-'.$filename);

        FileContent::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'lesson_content_id' => $content->id,
            'original_name' => $filename,
            'storage_path' => $path,
            'mime_type' => $mime,
            'size_bytes' => 20,
            'extension' => $ext,
            'is_downloadable' => $downloadable,
            'metadata' => [],
        ]);

        return $lesson;
    }

    private function setDownloads(Tenant $tenant, bool $enabled): void
    {
        TenantSetting::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'file_downloads_enabled' => $enabled,
        ]);
    }

    // ─── Ticket shape ────────────────────────────────────────────────

    public function test_ticket_returns_inline_and_attachment_urls_according_to_policy(): void
    {
        Storage::fake('local');

        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);
        $this->setDownloads($tenant, true);

        $lesson = $this->makeFileLesson($tenant, 'notes.pdf', 'pdf');

        $res = $this->getJson(
            '/api/v1/lessons/'.$lesson->id.'/file-ticket',
            $this->authHeaders($teacher, $tenant)
        );

        $res->assertOk()
            ->assertJsonPath('previewable', true)
            ->assertJsonPath('is_downloadable', true);

        $this->assertStringContainsString('mode=inline', $res->json('preview_url'));
        $this->assertStringContainsString('mode=attachment', $res->json('download_url'));
        $this->assertStringContainsString('token=', $res->json('preview_url'));
    }

    public function test_ticket_hides_the_download_url_when_the_teacher_disabled_it(): void
    {
        Storage::fake('local');

        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);
        $this->setDownloads($tenant, false);

        $lesson = $this->makeFileLesson($tenant, 'notes.pdf', 'pdf', true);

        $res = $this->getJson('/api/v1/lessons/'.$lesson->id.'/file-ticket', $this->authHeaders($teacher, $tenant));

        $res->assertOk()
            ->assertJsonPath('is_downloadable', false)
            ->assertJsonPath('download_url', null);
        $this->assertNotNull($res->json('preview_url'));
    }

    // ─── Media endpoint enforcement ─────────────────────────────────

    public function test_preview_mode_streams_inline_with_a_renderable_mime_type(): void
    {
        Storage::fake('local');

        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->setDownloads($tenant, false);

        // Stored MIME is octet-stream; the extension must rescue the render.
        $lesson = $this->makeFileLesson($tenant, 'notes.pdf', 'pdf', true, 'application/octet-stream');
        $token = app(FileProtectionService::class)->sign($lesson->id, $teacher->id);

        $res = $this->get('/api/v1/lessons/'.$lesson->id.'/file?mode=inline&token='.$token);

        $res->assertOk();
        $this->assertStringContainsString('inline', (string) $res->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
    }

    public function test_preview_mode_refuses_a_non_previewable_format(): void
    {
        Storage::fake('local');

        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->setDownloads($tenant, true);

        $lesson = $this->makeFileLesson($tenant, 'sheet.docx', 'docx');
        $token = app(FileProtectionService::class)->sign($lesson->id, $teacher->id);

        // Even with downloads enabled, a docx cannot claim to be previewed
        // inline — the browser could not render it anyway.
        $this->get('/api/v1/lessons/'.$lesson->id.'/file?mode=inline&token='.$token)
            ->assertStatus(403);
    }

    public function test_attachment_mode_is_refused_when_downloads_are_disabled(): void
    {
        Storage::fake('local');

        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->setDownloads($tenant, false);

        $lesson = $this->makeFileLesson($tenant, 'notes.pdf', 'pdf', true);
        $token = app(FileProtectionService::class)->sign($lesson->id, $teacher->id);

        $this->get('/api/v1/lessons/'.$lesson->id.'/file?mode=attachment&token='.$token)
            ->assertStatus(403);
    }

    public function test_attachment_mode_downloads_when_allowed(): void
    {
        Storage::fake('local');

        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->setDownloads($tenant, true);

        $lesson = $this->makeFileLesson($tenant, 'notes.pdf', 'pdf', true);
        $token = app(FileProtectionService::class)->sign($lesson->id, $teacher->id);

        $res = $this->get('/api/v1/lessons/'.$lesson->id.'/file?mode=attachment&token='.$token);

        $res->assertOk();
        $this->assertStringContainsString('attachment', (string) $res->headers->get('Content-Disposition'));
    }

    public function test_legacy_call_without_mode_keeps_working(): void
    {
        Storage::fake('local');

        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->setDownloads($tenant, false);

        $lesson = $this->makeFileLesson($tenant, 'notes.pdf', 'pdf', true);
        $token = app(FileProtectionService::class)->sign($lesson->id, $teacher->id);

        // No mode → inline for a previewable file when downloads are off.
        $res = $this->get('/api/v1/lessons/'.$lesson->id.'/file?token='.$token);
        $res->assertOk();
        $this->assertStringContainsString('inline', (string) $res->headers->get('Content-Disposition'));
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        Storage::fake('local');

        $tenant = $this->makeTenant();
        $lesson = $this->makeFileLesson($tenant, 'notes.pdf', 'pdf');

        $this->get('/api/v1/lessons/'.$lesson->id.'/file?mode=inline&token=not-a-real-token')
            ->assertStatus(403);
    }

    public function test_a_student_without_enrollment_is_denied_a_ticket(): void
    {
        Storage::fake('local');

        $tenant = $this->makeTenant();
        $stranger = $this->makeUser();
        $this->attachToTenant($stranger, $tenant, 'student');

        $lesson = $this->makeFileLesson($tenant, 'notes.pdf', 'pdf');

        $this->getJson('/api/v1/lessons/'.$lesson->id.'/file-ticket', $this->authHeaders($stranger, $tenant))
            ->assertStatus(403);
    }

    public function test_an_enrolled_student_gets_a_working_ticket(): void
    {
        Storage::fake('local');

        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $lesson = $this->makeFileLesson($tenant, 'notes.pdf', 'pdf');

        Enrollment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $lesson->section->course_id,
            'user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
            'source' => 'manual',
        ]);

        $res = $this->getJson('/api/v1/lessons/'.$lesson->id.'/file-ticket', $this->authHeaders($student, $tenant));
        $res->assertOk()->assertJsonPath('previewable', true);

        $token = app(FileProtectionService::class)->sign($lesson->id, $student->id);
        $this->get('/api/v1/lessons/'.$lesson->id.'/file?mode=inline&token='.$token)->assertOk();
    }
}
