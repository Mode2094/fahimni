<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonContent;
use App\Models\Section;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * The teacher getting-started guide.
 *
 * The guide must reflect the tenant's real data (never a hardcoded "done"),
 * must hide steps the plan does not include, must never leak another tenant's
 * activity, and must let a teacher hide/restore it on any device.
 */
class TeacherOnboardingTest extends TenantTestCase
{
    private function makeCourse(string $tenantId, ?string $slug = null): Course
    {
        return Course::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'title' => 'Onboarding Course',
            'slug' => $slug ?? 'oc-'.Str::random(6),
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
            'title' => 'S',
            'sort_order' => 1,
            'metadata' => [],
        ]);

        return Lesson::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'section_id' => $section->id,
            'title' => 'L',
            'sort_order' => 1,
            'metadata' => [],
        ]);
    }

    private function makeTextContent(string $tenantId, string $lessonId): LessonContent
    {
        return LessonContent::create([
            'tenant_id' => $tenantId,
            'lesson_id' => $lessonId,
            'content_type' => 'text',
            'sort_order' => 1,
            'metadata' => [],
        ]);
    }

    private function keys(array $steps): array
    {
        return array_map(fn ($s) => $s['key'], $steps);
    }

    public function test_a_full_plan_tenant_sees_the_required_and_optional_steps(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $headers = $this->authHeaders($teacher, $tenant);

        $first = $this->getJson('/api/v1/teacher/onboarding', $headers)->assertOk();
        $this->assertSame(0, $first->json('completed'));
        $this->assertSame(
            ['profile', 'course', 'lesson', 'content', 'exam', 'enrollment', 'assignment', 'live', 'schedule'],
            $this->keys($first->json('steps')),
        );

        // The exam and the advanced extras are optional; the core setup is not.
        $optional = collect($first->json('steps'))->where('optional', true)->pluck('key')->all();
        $this->assertSame(['exam', 'assignment', 'live', 'schedule'], $optional);

        // Real data flips the first steps — nothing is hardcoded.
        $course = $this->makeCourse($tenant->id);
        $lesson = $this->makeLesson($tenant->id, $course->id);
        $this->makeTextContent($tenant->id, $lesson->id);

        $second = $this->getJson('/api/v1/teacher/onboarding', $headers)->assertOk();
        $byKey = collect($second->json('steps'))->keyBy('key');
        $this->assertTrue($byKey['course']['done']);
        $this->assertTrue($byKey['lesson']['done']);
        // "Upload a video OR content" — a text lesson counts.
        $this->assertTrue($byKey['content']['done']);
        $this->assertFalse($byKey['exam']['done']);
        $this->assertSame(3, $second->json('completed'));
    }

    public function test_the_profile_step_tracks_the_teacher_phone(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser(); // no phone yet
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $this->assertFalse(
            collect($this->getJson('/api/v1/teacher/onboarding', $this->authHeaders($teacher, $tenant))->json('steps'))->keyBy('key')['profile']['done'],
        );

        $teacher->update(['phone' => '+962790000000']);

        // Fresh headers re-resolve the authenticated user (fresh phone).
        $this->assertTrue(
            collect($this->getJson('/api/v1/teacher/onboarding', $this->authHeaders($teacher, $tenant))->json('steps'))->keyBy('key')['profile']['done'],
        );
    }

    public function test_optional_steps_never_block_completion(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser(['phone' => '+962790000001']);
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $headers = $this->authHeaders($teacher, $tenant);

        $course = $this->makeCourse($tenant->id);
        $lesson = $this->makeLesson($tenant->id, $course->id);
        $this->makeTextContent($tenant->id, $lesson->id);
        Enrollment::create([
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'user_id' => $teacher->id,
            'status' => 'active',
            'enrolled_at' => now(),
        ]);

        // Every required step is done; the optional ones are still pending.
        $body = $this->getJson('/api/v1/teacher/onboarding', $headers)->assertOk()->json();
        $byKey = collect($body['steps'])->keyBy('key');
        $this->assertFalse($byKey['exam']['done']);
        $this->assertFalse($byKey['live']['done']);
        $this->assertTrue($body['all_done'], 'pending optional steps must not block completion');
        $this->assertSame(5, $body['required_total']);
        $this->assertSame(5, $body['required_completed']);
    }

    public function test_steps_the_plan_does_not_include_are_hidden(): void
    {
        // A tenant whose plan turns the gated features off must not be nudged
        // toward steps it cannot complete — those steps are dropped entirely.
        $tenant = $this->makeTenant();
        $sub = $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        \App\Models\PlanFeature::where('plan_id', $sub->plan_id)
            ->whereIn('feature_key', [
                'live_stream_enabled', 'video_upload_enabled', 'assignments_enabled',
                'cards_enabled', 'scheduled_lessons_enabled',
            ])
            ->update(['feature_value' => 'false']);

        $response = $this->getJson('/api/v1/teacher/onboarding', $this->authHeaders($teacher, $tenant))->assertOk();

        // file_upload_enabled stays on, so the content step survives; the
        // enrollment step is ungated (falls back to the student page).
        $this->assertSame(['profile', 'course', 'lesson', 'content', 'exam', 'enrollment'], $this->keys($response->json('steps')));
        $this->assertSame(6, $response->json('total'));

        $enrollment = collect($response->json('steps'))->keyBy('key')['enrollment'];
        $this->assertSame('/students', $enrollment['href']);
    }

    public function test_another_tenants_activity_does_not_mark_this_tenant_done(): void
    {
        $tenantA = $this->makeTenant();
        $this->attachSubscription($tenantA);
        $teacherA = $this->makeUser();
        $this->attachToTenant($teacherA, $tenantA, 'teacher');

        // Tenant B already has a course, lesson and enrollment.
        $tenantB = $this->makeTenant();
        $courseB = $this->makeCourse($tenantB->id);
        $lessonB = $this->makeLesson($tenantB->id, $courseB->id);
        $this->makeTextContent($tenantB->id, $lessonB->id);

        $response = $this->getJson('/api/v1/teacher/onboarding', $this->authHeaders($teacherA, $tenantA))->assertOk();

        $byKey = collect($response->json('steps'))->keyBy('key');
        $this->assertFalse($byKey['course']['done'], 'tenant isolation: B\'s course must not satisfy A\'s checklist');
        $this->assertFalse($byKey['lesson']['done']);
        $this->assertFalse($byKey['content']['done']);
        $this->assertSame(0, $response->json('completed'));
    }

    public function test_a_teacher_can_hide_and_restore_the_guide_across_devices(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $this->assertFalse($this->getJson('/api/v1/teacher/onboarding', $this->authHeaders($teacher, $tenant))->json('dismissed'));

        $this->postJson('/api/v1/teacher/onboarding/dismiss', [], $this->authHeaders($teacher, $tenant))
            ->assertOk()->assertJson(['dismissed' => true]);

        // A fresh session/device (new token, user re-read from the DB) still
        // sees it hidden — the state lives on the user record, not the client.
        $afterDismiss = $this->getJson('/api/v1/teacher/onboarding', $this->authHeaders($teacher, $tenant))->assertOk();
        $this->assertTrue($afterDismiss->json('dismissed'));
        $this->assertNotNull($afterDismiss->json('dismissed_at'));

        $this->postJson('/api/v1/teacher/onboarding/restore', [], $this->authHeaders($teacher, $tenant))
            ->assertOk()->assertJson(['dismissed' => false]);
        $this->assertFalse($this->getJson('/api/v1/teacher/onboarding', $this->authHeaders($teacher, $tenant))->json('dismissed'));
    }

    public function test_hiding_the_guide_is_per_teacher_not_per_tenant(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacherA = $this->makeUser();
        $this->attachToTenant($teacherA, $tenant, 'teacher');
        $teacherB = $this->makeUser();
        $this->attachToTenant($teacherB, $tenant, 'teacher');

        $this->postJson('/api/v1/teacher/onboarding/dismiss', [], $this->authHeaders($teacherA, $tenant))->assertOk();

        // Teacher B's own guide is untouched.
        $this->assertFalse($this->getJson('/api/v1/teacher/onboarding', $this->authHeaders($teacherB, $tenant))->json('dismissed'));
    }

    public function test_a_student_cannot_read_or_change_the_teacher_guide(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $this->getJson('/api/v1/teacher/onboarding', $this->authHeaders($student, $tenant))->assertStatus(403);
        $this->postJson('/api/v1/teacher/onboarding/dismiss', [], $this->authHeaders($student, $tenant))->assertStatus(403);
    }
}
