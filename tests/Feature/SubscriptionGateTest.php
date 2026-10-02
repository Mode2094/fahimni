<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

class SubscriptionGateTest extends TenantTestCase
{
    private function makePlan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'id' => UuidV7::generate(),
            'name' => 'Basic',
            'slug' => 'basic-'.Str::random(6),
            'monthly_price' => 19.99,
            'yearly_price' => 199.99,
            'currency' => 'SAR',
            'trial_days' => 7,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_staff_without_subscription_is_blocked_from_management(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $response = $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant));

        $response->assertStatus(402);
        $response->assertJsonPath('requires_subscription', true);
    }

    public function test_staff_during_trial_has_full_access(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        TenantSubscription::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'plan_id' => $this->makePlan()->id,
            'status' => 'trialing',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'trial_ends_at' => now()->addDays(7),
            'auto_renew' => true,
        ]);

        $response = $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant));

        $response->assertOk();
    }

    public function test_staff_after_expired_trial_is_blocked(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        TenantSubscription::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'plan_id' => $this->makePlan()->id,
            'status' => 'trialing',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subDays(10),
            'trial_ends_at' => now()->subDays(3),
            'auto_renew' => true,
        ]);

        $response = $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant));

        $response->assertStatus(402);
    }

    public function test_checkout_routes_stay_reachable_without_subscription(): void
    {
        $teacher = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        // No subscription at all — the dashboard (which shows the "subscribe"
        // state) must stay reachable so the staff can go pay.
        $response = $this->getJson('/api/v1/dashboard', $this->authHeaders($teacher, $tenant));

        $response->assertOk();
    }

    public function test_students_are_never_blocked_by_subscription_gate(): void
    {
        $student = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($student, $tenant, 'student');

        // Even with no subscription, students keep using the platform
        // (their courses were paid to the teacher, not the platform).
        $response = $this->getJson('/api/v1/my-courses', $this->authHeaders($student, $tenant));

        $response->assertOk();
    }
}
