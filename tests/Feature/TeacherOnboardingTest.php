<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Section;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Phase 5 — the teacher getting-started checklist.
 *
 * The checklist must reflect the tenant's real data (never a hardcoded "done"),
 * must hide steps the plan does not include, and must not leak another tenant's
 * activity.
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

    private function keys(array $steps): array
    {
        return array_map(fn ($s) => $s['key'], $steps);
    }

    public function test_a_full_plan_tenant_sees_every_step_and_completion_tracks_real_data(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $headers = $this->authHeaders($teacher, $tenant);

        $first = $this->getJson('/api/v1/teacher/onboarding', $headers)->assertOk();
        $this->assertSame(0, $first->json('completed'));
        $this->assertSame(
            ['course', 'lesson', 'video', 'card', 'assignment', 'live', 'schedule'],
            $this->keys($first->json('steps')),
        );

        // Real data flips the first steps — nothing is hardcoded.
        $course = $this->makeCourse($tenant->id);
        $this->makeLesson($tenant->id, $course->id);

        $second = $this->getJson('/api/v1/teacher/onboarding', $headers)->assertOk();
        $byKey = collect($second->json('steps'))->keyBy('key');
        $this->assertTrue($byKey['course']['done']);
        $this->assertTrue($byKey['lesson']['done']);
        $this->assertFalse($byKey['video']['done']);
        $this->assertSame(2, $second->json('completed'));
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

        $this->assertSame(['course', 'lesson'], $this->keys($response->json('steps')));
        $this->assertSame(2, $response->json('total'));
    }

    public function test_another_tenants_activity_does_not_mark_this_tenant_done(): void
    {
        $tenantA = $this->makeTenant();
        $this->attachSubscription($tenantA);
        $teacherA = $this->makeUser();
        $this->attachToTenant($teacherA, $tenantA, 'teacher');

        // Tenant B already has a course and a lesson.
        $tenantB = $this->makeTenant();
        $courseB = $this->makeCourse($tenantB->id);
        $this->makeLesson($tenantB->id, $courseB->id);

        $response = $this->getJson('/api/v1/teacher/onboarding', $this->authHeaders($teacherA, $tenantA))->assertOk();

        $byKey = collect($response->json('steps'))->keyBy('key');
        $this->assertFalse($byKey['course']['done'], 'tenant isolation: B\'s course must not satisfy A\'s checklist');
        $this->assertFalse($byKey['lesson']['done']);
        $this->assertSame(0, $response->json('completed'));
    }

    public function test_a_student_cannot_read_the_teacher_checklist(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $this->getJson('/api/v1/teacher/onboarding', $this->authHeaders($student, $tenant))
            ->assertStatus(403);
    }
}
