<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\PlanFeature;
use App\Models\Section;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Regression guard for the "silently disabled plan features" defect
 * (audit 2026-11-25).
 *
 * Three gates that `SubscriptionPlanSeeder` was supposed to write were absent
 * from every plan row. `PlanEngine::hasFeature()` returns false for an absent
 * key, so the code path guarding each feature was closed platform-wide — even
 * on the top plan. The clearest casualty: every plan ships an
 * `assignments_limit` (10 / 100 / unlimited) yet
 * `POST /api/v1/assignments` answered 403 for everyone.
 *
 * The fixtures in {@see TenantTestCase::attachSubscription()} already contained
 * the full set, which is exactly why the defect never failed a test — the
 * fixtures diverged from the shipped seeder. These tests exercise the REAL
 * seeder instead.
 */
class PlanFeatureGateBackfillTest extends TenantTestCase
{
    /** Gates that are enforced in code and must be present on every plan. */
    private const REQUIRED_GATES = ['assignments_enabled', 'blog_enabled', 'custom_domain'];

    private function seedPlans(): void
    {
        $this->seed(SubscriptionPlanSeeder::class);
    }

    private function subscribeTo(Tenant $tenant, string $slug, string $status = 'active'): TenantSubscription
    {
        $plan = SubscriptionPlan::where('slug', $slug)->firstOrFail();

        return TenantSubscription::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'trial_ends_at' => now()->addDays(7),
            'auto_renew' => true,
        ]);
    }

    private function courseWithLesson(Tenant $tenant, User $teacher): array
    {
        $course = Course::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'teacher_id' => $teacher->id,
            'title' => 'Course '.Str::random(4),
            'slug' => 'course-'.Str::random(6),
            'is_published' => true,
            'sort_order' => 0,
        ]);

        $section = Section::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'course_id' => $course->id,
            'title' => 'Section',
            'sort_order' => 1,
            'metadata' => [],
        ]);

        $lesson = Lesson::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'section_id' => $section->id,
            'title' => 'Lesson '.Str::random(4),
            'sort_order' => 1,
            'metadata' => [],
        ]);

        return [$course, $lesson];
    }

    public function test_seeder_ships_every_enforced_gate_on_every_plan(): void
    {
        $this->seedPlans();

        $plans = SubscriptionPlan::with('features')->get();
        $this->assertGreaterThanOrEqual(3, $plans->count(), 'the approved plans must be seeded');

        foreach ($plans as $plan) {
            $values = $plan->features->pluck('feature_value', 'feature_key')->all();

            foreach (self::REQUIRED_GATES as $gate) {
                $this->assertArrayHasKey(
                    $gate,
                    $values,
                    "plan '{$plan->slug}' is missing the enforced gate '{$gate}'"
                );
                $this->assertSame(
                    'true',
                    (string) $values[$gate],
                    "plan '{$plan->slug}' must ship '{$gate}' enabled"
                );
            }
        }
    }

    public function test_real_seeded_starter_plan_allows_assignment_creation(): void
    {
        $this->seedPlans();

        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->subscribeTo($tenant, 'starter', 'active');

        [$course, $lesson] = $this->courseWithLesson($tenant, $teacher);

        $this->postJson('/api/v1/assignments', [
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'title' => 'واجب التدقيق',
            'is_published' => true,
        ], $this->authHeaders($teacher, $tenant))
            ->assertStatus(201)
            ->assertJsonPath('assignment.lesson_id', $lesson->id);
    }

    public function test_absent_assignment_gate_is_what_blocked_creation(): void
    {
        $this->seedPlans();

        // Reproduce the pre-fix state on the real plan: the gate key is gone.
        PlanFeature::where('feature_key', 'assignments_enabled')->delete();

        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->subscribeTo($tenant, 'starter', 'active');

        [$course, $lesson] = $this->courseWithLesson($tenant, $teacher);

        // An absent gate = closed gate (hasFeature() returns false).
        $this->postJson('/api/v1/assignments', [
            'course_id' => $course->id,
            'lesson_id' => $lesson->id,
            'title' => 'واجب محجوب',
        ], $this->authHeaders($teacher, $tenant))->assertStatus(403);
    }
}
