<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\ScheduledLesson;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Phase 5 — student dashboard schedule/lesson payloads.
 *
 * Two silent bugs are locked down here:
 *  1. /scheduled-lessons hid the join link from students (correct) but gave the
 *     dashboard nothing to link to, so "Join lesson" was dead. The payload must
 *     now expose course_id while still withholding meeting_url.
 *  2. The course lesson page's Enrolled/Guest badge read `is_enrolled`, which
 *     /public/courses/{id}/sections never returned — every student showed as a
 *     "Guest".
 */
class StudentSchedulePayloadTest extends TenantTestCase
{
    private const MEET_URL = 'https://meet.google.com/abc-defg-hij';

    private function makeCourse(string $tenantId): Course
    {
        return Course::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'title' => 'Scheduled Course',
            'slug' => 'sc-'.Str::random(6),
            'is_published' => true,
            'sort_order' => 0,
        ]);
    }

    private function makeLesson(string $tenantId, ?string $courseId): ScheduledLesson
    {
        return ScheduledLesson::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'course_id' => $courseId,
            'title' => 'Live Lesson',
            'lesson_date' => now()->addDay()->toDateString(),
            'start_time' => '18:00',
            'end_time' => '19:00',
            'provider' => 'google_meet',
            'meeting_url' => self::MEET_URL,
            'status' => 'scheduled',
        ]);
    }

    public function test_a_student_gets_the_course_link_but_no_meeting_url(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $course = $this->makeCourse($tenant->id);
        Enrollment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);
        $this->makeLesson($tenant->id, $course->id);

        $response = $this->getJson('/api/v1/scheduled-lessons', $this->authHeaders($student, $tenant));

        $response->assertOk();
        $lesson = $response->json('lessons.0');
        $this->assertSame($course->id, $lesson['course_id'], 'students need course_id to reach the live player');
        $this->assertNull($lesson['meeting_url'], 'students must never receive the raw meeting link from this endpoint');
    }

    public function test_staff_still_receive_the_meeting_url(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant);

        $course = $this->makeCourse($tenant->id);
        $this->makeLesson($tenant->id, $course->id);

        $response = $this->getJson('/api/v1/scheduled-lessons', $this->authHeaders($teacher, $tenant));

        $response->assertOk();
        $this->assertSame(self::MEET_URL, $response->json('lessons.0.meeting_url'));
    }

    public function test_sections_reports_an_enrolled_student(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $course = $this->makeCourse($tenant->id);
        Enrollment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        $response = $this->getJson(
            "/api/v1/public/courses/{$course->id}/sections",
            $this->authHeaders($student, $tenant)
        );

        $response->assertOk();
        $this->assertTrue($response->json('is_enrolled'), 'an enrolled student must not be shown as a Guest');
    }

    public function test_sections_reports_a_guest_as_not_enrolled(): void
    {
        $tenant = $this->makeTenant();
        $course = $this->makeCourse($tenant->id);

        $response = $this->getJson("/api/v1/public/courses/{$course->id}/sections");

        $response->assertOk();
        $this->assertFalse($response->json('is_enrolled'));
    }
}
