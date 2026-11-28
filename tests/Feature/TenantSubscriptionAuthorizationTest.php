<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use Symfony\Component\Uid\UuidV7;

/**
 * Regression guard: managing the workspace subscription (reading billing
 * details, starting or cancelling checkout) is tenant-STAFF only. A student or
 * any other plain member must never be able to cancel the tenant's billing.
 */
class TenantSubscriptionAuthorizationTest extends TenantTestCase
{
    private function plan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'id' => UuidV7::generate(),
            'name' => 'Basic',
            'slug' => 'basic-'.UuidV7::generate(),
            'monthly_price' => 20,
            'yearly_price' => 200,
            'currency' => 'USD',
            'trial_days' => 14,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ]);
    }

    private function subscription(string $tenantId, string $planId): TenantSubscription
    {
        return TenantSubscription::withoutTenant()->create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'plan_id' => $planId,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'auto_renew' => true,
        ]);
    }

    public function test_a_student_member_cannot_read_or_cancel_the_tenant_subscription(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $sub = $this->subscription($tenant->id, $this->plan()->id);

        $this->getJson("/api/v1/subscriptions/{$sub->id}", $this->authHeaders($student, $tenant))
            ->assertForbidden();

        $this->postJson("/api/v1/subscriptions/{$sub->id}/cancel", [], $this->authHeaders($student, $tenant))
            ->assertForbidden();

        $this->assertSame('active', $sub->fresh()->status, 'A student must not change the subscription status.');
    }

    public function test_a_student_member_cannot_list_subscriptions(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $this->subscription($tenant->id, $this->plan()->id);

        $this->getJson('/api/v1/subscriptions', $this->authHeaders($student, $tenant))
            ->assertForbidden();
    }

    public function test_a_student_member_cannot_touch_freemius_billing_endpoints(): void
    {
        $tenant = $this->makeTenant();
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $this->postJson('/api/v1/subscriptions/freemius/cancel', [], $this->authHeaders($student, $tenant))
            ->assertForbidden();
        $this->postJson('/api/v1/subscriptions/freemius/portal', [], $this->authHeaders($student, $tenant))
            ->assertForbidden();
        $this->postJson('/api/v1/subscriptions/freemius/checkout', [
            'plan_id' => $this->plan()->id,
            'billing_cycle' => 'monthly',
        ], $this->authHeaders($student, $tenant))->assertForbidden();
    }

    public function test_tenant_staff_can_still_read_subscriptions(): void
    {
        $tenant = $this->makeTenant();
        $owner = $this->makeUser();
        $this->attachToTenant($owner, $tenant, 'owner');
        $sub = $this->subscription($tenant->id, $this->plan()->id);

        $this->getJson('/api/v1/subscriptions', $this->authHeaders($owner, $tenant))->assertOk();
        $this->getJson("/api/v1/subscriptions/{$sub->id}", $this->authHeaders($owner, $tenant))->assertOk();
        $this->getJson('/api/v1/subscriptions/current', $this->authHeaders($owner, $tenant))->assertOk();
    }
}
