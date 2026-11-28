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
 * تقرير مشاهدة الفيديو لكل طالب — أداة المعلم لفهم تقدّم طالبه فيديو-بفيديو.
 *
 * Explains why a student's progress can sit at 0% (nothing genuinely watched)
 * and which videos are done or in progress. Read-only and tenant-scoped.
 */
class TeacherStudentVideoReportTest extends TenantTestCase
{
    private function courseWithVideos(Tenant $tenant, User $student, int $videos = 2): array
    {
        $course = Course::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'title' => 'Physics', 'slug' => 'phys-'.Str::random(6),
            'is_published' => true, 'sort_order' => 0,
        ]);
        $section = Section::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'course_id' => $course->id, 'title' => 'Unit', 'is_published' => true, 'sort_order' => 0,
        ]);

        $lessons = [];
        for ($i = 0; $i < $videos; $i++) {
            $lessons[] = Lesson::create([
                'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'section_id' => $section->id,
                'title' => 'Video '.($i + 1), 'lesson_type' => 'video', 'duration_minutes' => 10,
                'is_published' => true, 'sort_order' => $i,
            ]);
        }

        $enrollment = Enrollment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'course_id' => $course->id, 'user_id' => $student->id,
            'status' => 'active', 'progress_percent' => 50, 'enrolled_at' => now(), 'metadata' => [],
        ]);

        return compact('course', 'section', 'lessons', 'enrollment');
    }

    public function test_the_report_shows_per_video_watch_state_for_the_students_courses(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $tree = $this->courseWithVideos($tenant, $student);

        // Lesson 1 fully watched & completed; lesson 2 untouched.
        LessonProgress::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id,
            'enrollment_id' => $tree['enrollment']->id, 'lesson_id' => $tree['lessons'][0]->id,
            'user_id' => $student->id, 'status' => 'completed', 'watch_time_seconds' => 540,
            'completed_at' => now(), 'metadata' => [],
        ]);

        $res = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->getJson("/api/v1/teacher/students/{$student->id}/video-progress");

        $res->assertOk()
            ->assertJsonPath('summary.videos_total', 2)
            ->assertJsonPath('summary.videos_completed', 1)
            ->assertJsonPath('summary.watch_seconds_total', 540)
            ->assertJsonPath('courses.0.course_title', 'Physics')
            ->assertJsonPath('courses.0.lessons.0.status', 'completed')
            ->assertJsonPath('courses.0.lessons.0.coverage_percent', 90)
            ->assertJsonPath('courses.0.lessons.1.status', 'not_started')
            ->assertJsonPath('courses.0.lessons.1.coverage_percent', 0);
    }

    public function test_a_student_cannot_read_another_students_video_report(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $other = $this->makeUser();
        $this->attachToTenant($other, $tenant, 'student');
        $this->courseWithVideos($tenant, $other);

        $this->withHeaders($this->authHeaders($student, $tenant))
            ->getJson("/api/v1/teacher/students/{$other->id}/video-progress")
            ->assertStatus(403);
    }

    public function test_a_foreign_teacher_cannot_read_the_report(): void
    {
        $tenantA = $this->makeTenant();
        $this->attachSubscription($tenantA);
        $studentA = $this->makeUser();
        $this->attachToTenant($studentA, $tenantA, 'student');
        $this->courseWithVideos($tenantA, $studentA);

        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantB);
        $teacherB = $this->makeUser();
        $this->attachToTenant($teacherB, $tenantB, 'teacher');

        $this->withHeaders($this->authHeaders($teacherB, $tenantB))
            ->getJson("/api/v1/teacher/students/{$studentA->id}/video-progress")
            ->assertStatus(404);
    }
}
