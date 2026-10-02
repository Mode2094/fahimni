<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Plans\Exceptions\FeatureUnavailableException;
use App\Plans\PlanEngine;
use App\Plans\QuotaService;
use App\Plans\UsageService;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

class PlanEngineTest extends TenantTestCase
{
    private function makePlan(array $overrides = []): SubscriptionPlan
    {
        return SubscriptionPlan::create(array_merge([
            'id' => UuidV7::generate(),
            'name' => 'Test Plan',
            'slug' => 'test-'.Str::random(6),
            'monthly_price' => 49.99,
            'yearly_price' => 499.99,
            'currency' => 'JOD',
            'trial_days' => 7,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ], $overrides));
    }

    private function addFeature(SubscriptionPlan $plan, string $key, string $value): PlanFeature
    {
        return PlanFeature::updateOrCreate(
            ['plan_id' => $plan->id, 'feature_key' => $key],
            ['feature_value' => $value, 'feature_type' => 'string', 'sort_order' => 0]
        );
    }

    /** Update a feature value through the model (fires observers — as the admin panel does). */
    private function setFeature(SubscriptionPlan $plan, string $key, string $value): void
    {
        $feature = PlanFeature::where('plan_id', $plan->id)->where('feature_key', $key)->firstOrFail();
        $feature->update(['feature_value' => $value]);
    }

    private function planFor(SubscriptionPlan $plan, Tenant $tenant): void
    {
        $this->attachSubscription($tenant);
        TenantSubscription::where('tenant_id', $tenant->id)->update(['plan_id' => $plan->id]);
    }

    // ─── Engine basics ──────────────────────────────────────────

    public function test_engine_reads_features_from_database(): void
    {
        $plan = $this->makePlan();
        $this->addFeature($plan, 'courses_limit', '5');
        $this->addFeature($plan, 'live_stream_enabled', 'true');

        $tenant = $this->makeTenant();
        $this->planFor($plan, $tenant);

        $engine = app(PlanEngine::class);

        $this->assertTrue($engine->hasFeature($tenant->id, 'live_stream_enabled'));
        $this->assertFalse($engine->hasFeature($tenant->id, 'nonexistent'));
        $this->assertSame(5, $engine->intValue($tenant->id, 'courses_limit'));
        $this->assertFalse($engine->isUnlimited($tenant->id, 'courses_limit'));
    }

    public function test_engine_returns_empty_for_tenant_without_subscription(): void
    {
        $tenant = $this->makeTenant();

        $engine = app(PlanEngine::class);

        $this->assertFalse($engine->hasFeature($tenant->id, 'live_stream_enabled'));
        $this->assertSame([], $engine->features($tenant->id));
    }

    // ─── Instant upgrade (cache invalidation) ───────────────────

    public function test_upgrade_takes_effect_immediately_without_cache_staleness(): void
    {
        $plan = $this->makePlan();
        $this->addFeature($plan, 'courses_limit', '2');

        $tenant = $this->makeTenant();
        $this->planFor($plan, $tenant);

        // Warm the cache.
        $engine = app(PlanEngine::class);
        $this->assertSame(2, $engine->intValue($tenant->id, 'courses_limit'));

        // Admin raises the limit → must apply instantly (no stale cache).
        $this->addFeature($plan, 'courses_limit', '10');

        $this->assertSame(10, $engine->intValue($tenant->id, 'courses_limit'));
    }

    // ─── Quota: course limit blocks creation ───────────────────

    public function test_course_limit_blocks_creation_via_api(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $plan = $this->makePlan();
        $this->addFeature($plan, 'courses_limit', '1');
        $this->planFor($plan, $tenant);

        $first = $this->postJson('/api/v1/courses', ['title' => 'C1', 'slug' => 'c1-'.Str::random(4)], $this->authHeaders($teacher, $tenant));
        $first->assertCreated();

        $second = $this->postJson('/api/v1/courses', ['title' => 'C2', 'slug' => 'c2-'.Str::random(4)], $this->authHeaders($teacher, $tenant));
        $second->assertStatus(403);
    }

    // ─── Downgrade: blocks new, keeps existing ─────────────────

    public function test_downgrade_blocks_new_students_but_keeps_existing(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $plan = $this->makePlan();
        $this->addFeature($plan, 'students_limit', '100');
        $this->planFor($plan, $tenant);

        $course = Course::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'title' => 'C', 'slug' => 'c-'.Str::random(6), 'is_published' => true, 'sort_order' => 0]);

        // 80 students enrolled while limit was 100.
        for ($i = 0; $i < 80; $i++) {
            $student = $this->makeUser();
            $this->attachToTenant($student, $tenant, 'student');
            Enrollment::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'course_id' => $course->id, 'user_id' => $student->id, 'status' => 'active', 'enrolled_at' => now()]);
        }

        // Admin downgrades to 50 (model update → cache invalidated).
        $this->setFeature($plan, 'students_limit', '50');

        $newStudent = $this->makeUser();
        $this->attachToTenant($newStudent, $tenant, 'student');

        $blocked = $this->postJson('/api/v1/enrollments', ['course_id' => $course->id, 'user_id' => $newStudent->id], $this->authHeaders($teacher, $tenant));
        $blocked->assertStatus(403);

        // Existing data untouched.
        $this->assertSame(80, Enrollment::where('tenant_id', $tenant->id)->count());
    }

    // ─── Feature gate on routes ────────────────────────────────

    public function test_announcements_route_gated_by_feature(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $plan = $this->makePlan();
        $this->addFeature($plan, 'announcements_enabled', 'false');
        $this->planFor($plan, $tenant);

        $blocked = $this->postJson('/api/v1/announcements', ['title' => 'X', 'body' => 'Y', 'audience' => 'all'], $this->authHeaders($teacher, $tenant));
        $blocked->assertStatus(403);

        // Admin enables the feature → works instantly.
        $this->setFeature($plan, 'announcements_enabled', 'true');

        $allowed = $this->postJson('/api/v1/announcements', ['title' => 'X', 'body' => 'Y', 'audience' => 'all'], $this->authHeaders($teacher, $tenant));
        $allowed->assertCreated();
    }

    // ─── Usage service ─────────────────────────────────────────

    public function test_usage_service_counts_real_consumption(): void
    {
        $tenant = $this->makeTenant();

        Course::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'title' => 'A', 'slug' => 'a-'.Str::random(6), 'is_published' => true, 'sort_order' => 0]);
        Course::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'title' => 'B', 'slug' => 'b-'.Str::random(6), 'is_published' => true, 'sort_order' => 0]);

        $usage = app(UsageService::class);

        $this->assertSame(2, $usage->usageOf($tenant->id, 'courses'));
        $this->assertSame(0, $usage->usageOf($tenant->id, 'students'));
    }

    // ─── Quota service direct ──────────────────────────────────

    public function test_quota_service_throws_feature_and_limit_exceptions(): void
    {
        $plan = $this->makePlan();
        $this->addFeature($plan, 'live_stream_enabled', 'false');
        $this->addFeature($plan, 'questions_limit', '1');

        $tenant = $this->makeTenant();
        $this->planFor($plan, $tenant);

        $quota = app(QuotaService::class);

        $this->expectException(FeatureUnavailableException::class);
        $quota->assertCan($tenant->id, 'live_sessions');
    }

    public function test_quota_status_reports_used_limit_remaining(): void
    {
        $plan = $this->makePlan();
        $this->addFeature($plan, 'courses_limit', '10');

        $tenant = $this->makeTenant();
        $this->planFor($plan, $tenant);

        Course::create(['id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'title' => 'A', 'slug' => 'a-'.Str::random(6), 'is_published' => true, 'sort_order' => 0]);

        $status = app(QuotaService::class)->status($tenant->id);

        $this->assertSame(1, $status['courses']['used']);
        $this->assertSame(10, $status['courses']['limit']);
        $this->assertSame(9, $status['courses']['remaining']);
        $this->assertFalse($status['courses']['unlimited']);
    }

    // ─── Teacher plan endpoint ─────────────────────────────────

    public function test_teacher_plan_endpoint_returns_dynamic_usage(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $plan = $this->makePlan();
        $this->addFeature($plan, 'courses_limit', '5');
        $this->addFeature($plan, 'live_stream_enabled', 'false');
        $this->planFor($plan, $tenant);

        $response = $this->getJson('/api/v1/teacher/plan', $this->authHeaders($teacher, $tenant));

        $response->assertOk();
        $response->assertJsonPath('plan.plan_name', 'Test Plan');
        $response->assertJsonPath('gates.live_stream_enabled', false);
        $response->assertJsonPath('usage.courses.limit', 5);
        $response->assertJsonPath('usage.courses.remaining', 5);
        $response->assertJsonPath('features.courses_limit', '5');
    }
}
