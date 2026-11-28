<?php

namespace Tests\Feature;

/**
 * Regression: with the teacher free trial disabled, a freshly registered
 * teacher has no subscription at all. That workspace must be treated as
 * «pending payment» — blocked from management AND never described as
 * "unlimited" by the plan endpoint (the signup bypass the owner reported:
 * "gave me everything unlimited and never asked me to pick a plan").
 */
class TeacherPlanGateTest extends TenantTestCase
{
    public function test_no_plan_tenant_is_never_reported_as_unlimited(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $response = $this->getJson('/api/v1/teacher/plan', $this->authHeaders($teacher, $tenant));

        $response->assertOk();
        $response->assertJsonPath('active', false);
        $response->assertJsonPath('plan', null);

        // Core of the regression: no plan → every quota is locked, none is ∞.
        $response->assertJsonPath('usage.courses.unlimited', false);
        $response->assertJsonPath('usage.courses.locked', true);
        $response->assertJsonPath('usage.students.unlimited', false);
        $response->assertJsonPath('usage.students.locked', true);
        $response->assertJsonPath('usage.storage.unlimited', false);
        $response->assertJsonPath('usage.storage.locked', true);
    }

    public function test_no_plan_tenant_is_blocked_from_management(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        // No privilege bypass: management routes still 402 until the tenant pays.
        $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant))
            ->assertStatus(402)
            ->assertJsonPath('requires_subscription', true);
    }

    public function test_trialing_tenant_reports_active_with_real_limits(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $this->attachSubscription($tenant, 'trialing');

        $response = $this->getJson('/api/v1/teacher/plan', $this->authHeaders($teacher, $tenant));

        $response->assertOk();
        $response->assertJsonPath('active', true);
        $response->assertJsonPath('usage.courses.unlimited', false);
        $response->assertJsonPath('usage.courses.limit', 100);
        // Active tenants are not flagged as locked.
        $response->assertJsonMissingPath('usage.courses.locked');
    }
}
