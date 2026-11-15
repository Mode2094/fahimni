<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\Section;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Phase 2 — worksheets/assignments: lesson linkage, PRIVATE file storage,
 * authorised downloads and the sanitisation of client-supplied file refs.
 */
class AssignmentWorksheetTest extends TenantTestCase
{
    private function makeCourse(string $tenantId, string $teacherId): Course
    {
        return Course::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'teacher_id' => $teacherId,
            'title' => 'Course '.Str::random(4),
            'slug' => 'course-'.Str::random(6),
            'is_published' => true,
            'sort_order' => 0,
        ]);
    }

    private function makeLesson(string $tenantId, string $courseId): Lesson
    {
        $section = Section::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'course_id' => $courseId,
            'title' => 'Section',
            'sort_order' => 1,
            'metadata' => [],
        ]);

        return Lesson::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'section_id' => $section->id,
            'title' => 'Lesson '.Str::random(4),
            'sort_order' => 1,
            'metadata' => [],
        ]);
    }

    private function enroll(string $tenantId, string $courseId, string $userId, string $status = 'active'): Enrollment
    {
        return Enrollment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'course_id' => $courseId,
            'user_id' => $userId,
            'status' => $status,
            'progress_percent' => 0,
            'enrolled_at' => now(),
            'source' => 'test',
            'metadata' => [],
        ]);
    }

    public function test_teacher_can_create_a_worksheet_tied_to_a_lesson(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $course = $this->makeCourse($tenant->id, $teacher->id);
        $lesson = $this->makeLesson($tenant->id, $course->id);

        $response = $this->postJson('/api/v1/assignments', [
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'title' => 'ورقة عمل 1',
            'is_published' => true,
        ], $this->authHeaders($teacher, $tenant));

        $response->assertStatus(201);
        $response->assertJsonPath('assignment.lesson_id', $lesson->id);

        $this->assertDatabaseHas('assignments', [
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
        ]);
    }

    public function test_a_lesson_from_another_course_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $courseA = $this->makeCourse($tenant->id, $teacher->id);
        $courseB = $this->makeCourse($tenant->id, $teacher->id);
        $foreignLesson = $this->makeLesson($tenant->id, $courseB->id);

        $response = $this->postJson('/api/v1/assignments', [
            'course_id' => $courseA->id,
            'lesson_id' => $foreignLesson->id,
            'title' => 'bad',
        ], $this->authHeaders($teacher, $tenant));

        $response->assertStatus(422);
    }

    public function test_assignment_upload_is_stored_privately(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $file = UploadedFile::fake()->createWithContent('worksheet.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");

        $response = $this->post('/api/v1/assignment-upload', [
            'file' => $file,
        ], $this->authHeaders($teacher, $tenant));

        $response->assertStatus(201);

        $url = $response->json('url');

        // Must be an opaque private reference, never a public /storage URL.
        $this->assertStringStartsNotWith('/storage/', $url);
        $this->assertStringStartsWith('assignments/'.$tenant->id.'/', $url);
        Storage::disk('local')->assertExists($url);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_external_file_url_is_rejected_on_create(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $course = $this->makeCourse($tenant->id, $teacher->id);

        $response = $this->postJson('/api/v1/assignments', [
            'course_id' => $course->id,
            'title' => 'link injection',
            'file_url' => 'https://evil.example/payload.pdf',
            'file_name' => 'payload.pdf',
        ], $this->authHeaders($teacher, $tenant));

        $response->assertStatus(201);
        $this->assertNull($response->json('assignment.file_url'));
    }

    public function test_enrolled_student_can_download_but_others_cannot(): void
    {
        Storage::fake('local');

        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $course = $this->makeCourse($tenant->id, $teacher->id);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant->id, $course->id, $student->id);

        $outsider = $this->makeUser();
        $this->attachToTenant($outsider, $tenant, 'student');

        $path = 'assignments/'.$tenant->id.'/worksheet.pdf';
        Storage::disk('local')->put($path, 'PDFDATA');

        $assignment = Assignment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'title' => 'ws',
            'is_published' => true,
            'sort_order' => 1,
            'file_url' => $path,
            'file_name' => 'worksheet.pdf',
            'metadata' => [],
        ]);

        $this->get("/api/v1/assignments/{$assignment->id}/file", $this->authHeaders($student, $tenant))
            ->assertStatus(200);

        $this->get("/api/v1/assignments/{$assignment->id}/file", $this->authHeaders($outsider, $tenant))
            ->assertStatus(403);
    }

    public function test_assignment_file_of_another_tenant_is_not_found(): void
    {
        Storage::fake('local');

        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);
        $course = $this->makeCourse($tenant->id, $teacher->id);

        $path = 'assignments/'.$tenant->id.'/a.pdf';
        Storage::disk('local')->put($path, 'PDFDATA');
        $assignment = Assignment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'title' => 'ws', 'is_published' => true, 'sort_order' => 1,
            'file_url' => $path, 'file_name' => 'a.pdf', 'metadata' => [],
        ]);

        // A teacher of a different tenant must not resolve the row (global scope).
        $tenant2 = $this->makeTenant();
        $teacher2 = $this->makeUser();
        $this->attachToTenant($teacher2, $tenant2, 'teacher');
        $this->attachSubscription($tenant2);

        $this->get("/api/v1/assignments/{$assignment->id}/file", $this->authHeaders($teacher2, $tenant2))
            ->assertStatus(404);
    }

    public function test_submission_file_is_visible_only_to_the_owner_and_staff(): void
    {
        Storage::fake('local');

        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);
        $course = $this->makeCourse($tenant->id, $teacher->id);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant->id, $course->id, $student->id);

        $other = $this->makeUser();
        $this->attachToTenant($other, $tenant, 'student');
        $this->enroll($tenant->id, $course->id, $other->id);

        $assignment = Assignment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'title' => 'ws', 'is_published' => true, 'sort_order' => 1, 'metadata' => [],
        ]);

        $path = 'assignments/'.$tenant->id.'/solution.pdf';
        Storage::disk('local')->put($path, 'SOLUTION');

        $submission = AssignmentSubmission::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'assignment_id' => $assignment->id, 'user_id' => $student->id,
            'file_url' => $path, 'file_name' => 'solution.pdf',
            'submitted_at' => now(), 'status' => 'submitted', 'metadata' => [],
        ]);

        $this->get("/api/v1/submissions/{$submission->id}/file", $this->authHeaders($student, $tenant))
            ->assertStatus(200);
        $this->get("/api/v1/submissions/{$submission->id}/file", $this->authHeaders($teacher, $tenant))
            ->assertStatus(200);
        $this->get("/api/v1/submissions/{$submission->id}/file", $this->authHeaders($other, $tenant))
            ->assertStatus(403);
    }

    public function test_legacy_public_upload_paths_are_still_served(): void
    {
        Storage::fake('public');

        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);
        $course = $this->makeCourse($tenant->id, $teacher->id);

        Storage::disk('public')->put('uploads/legacy.pdf', 'OLD');

        $assignment = Assignment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'title' => 'legacy', 'is_published' => true, 'sort_order' => 1,
            'file_url' => '/storage/uploads/legacy.pdf', 'file_name' => 'legacy.pdf', 'metadata' => [],
        ]);

        $this->get("/api/v1/assignments/{$assignment->id}/file", $this->authHeaders($teacher, $tenant))
            ->assertStatus(200);
    }

    public function test_index_can_filter_by_lesson_and_graded(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);
        $course = $this->makeCourse($tenant->id, $teacher->id);
        $lesson = $this->makeLesson($tenant->id, $course->id);

        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->enroll($tenant->id, $course->id, $student->id);

        $withLesson = Assignment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'lesson_id' => $lesson->id, 'title' => 'on lesson', 'is_published' => true,
            'sort_order' => 1, 'metadata' => [],
        ]);
        $graded = Assignment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id,
            'title' => 'graded one', 'is_published' => true, 'sort_order' => 2, 'metadata' => [],
        ]);

        AssignmentSubmission::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'assignment_id' => $graded->id, 'user_id' => $student->id,
            'submitted_at' => now(), 'status' => 'graded',
            'grade' => 8, 'graded_at' => now(), 'metadata' => [],
        ]);

        $byLesson = $this->getJson(
            "/api/v1/assignments?course_id={$course->id}&lesson_id={$lesson->id}",
            $this->authHeaders($student, $tenant)
        );
        $byLesson->assertOk();
        $this->assertCount(1, $byLesson->json('assignments'));
        $this->assertSame($withLesson->id, $byLesson->json('assignments.0.id'));

        $onlyGraded = $this->getJson(
            "/api/v1/assignments?course_id={$course->id}&filter=graded",
            $this->authHeaders($student, $tenant)
        );
        $onlyGraded->assertOk();
        $ids = array_column($onlyGraded->json('assignments'), 'id');
        $this->assertSame([$graded->id], $ids);
    }
}
