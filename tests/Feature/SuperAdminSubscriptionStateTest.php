<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Plans\SubscriptionState;
use App\Support\TenantContext;
use Symfony\Component\Uid\UuidV7;

class SuperAdminSubscriptionStateTest extends TenantTestCase
{
    private function makePlan(): SubscriptionPlan
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

    private function trial(Tenant $tenant, array $overrides = []): TenantSubscription
    {
        return TenantSubscription::withoutTenant()->create(array_merge([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'plan_id' => $this->makePlan()->id,
            'status' => 'trialing',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subDay(),
            'trial_ends_at' => now()->addDays(7),
            'auto_renew' => true,
        ], $overrides));
    }

    public function test_expired_or_unbounded_trial_status_does_not_grant_access(): void
    {
        $expiredTenant = $this->makeTenant();
        $missingEndTenant = $this->makeTenant();
        $futureStartTenant = $this->makeTenant();

        $this->trial($expiredTenant, ['trial_ends_at' => now()->subSecond()]);
        $this->trial($missingEndTenant, ['trial_ends_at' => null]);
        $this->trial($futureStartTenant, [
            'starts_at' => now()->addDay(),
            'trial_ends_at' => now()->addDays(7),
        ]);

        $state = SubscriptionState::make();

        $this->assertSame(SubscriptionState::SOURCE_NONE, $state->resolve($expiredTenant->id)['source']);
        $this->assertSame(SubscriptionState::SOURCE_NONE, $state->resolve($missingEndTenant->id)['source']);
        $this->assertSame(SubscriptionState::SOURCE_NONE, $state->resolve($futureStartTenant->id)['source']);

        // The historical rows remain intact; statistics/access logic must not
        // "fix" the count by mutating source records.
        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $expiredTenant->id,
            'status' => 'trialing',
        ]);
    }

    public function test_resolving_a_platform_tenant_ignores_another_selected_tenant_context(): void
    {
        $targetTenant = $this->makeTenant();
        $selectedTenant = $this->makeTenant();
        $this->trial($targetTenant);
        $this->trial($selectedTenant);

        // The API's TenantMiddleware can set this context from a Super Admin's
        // X-Tenant-Id. Resolving an explicit tenant must not inherit that scope.
        app(TenantContext::class)->set($selectedTenant->id);

        $state = SubscriptionState::make()->resolve($targetTenant->id);

        $this->assertSame(SubscriptionState::SOURCE_LEGACY, $state['source']);
        $this->assertSame($targetTenant->id, TenantSubscription::withoutTenant()
            ->whereKey($state['subscription_id'])
            ->value('tenant_id'));
    }

    public function test_a_future_administrative_grant_is_access_not_paid_subscription(): void
    {
        $tenant = $this->makeTenant();
        $subscription = $this->trial($tenant, [
            'status' => 'expired',
            'trial_ends_at' => now()->subDay(),
            'admin_ends_at' => now()->addDays(5),
        ]);

        $state = SubscriptionState::make()->resolve($tenant->id);

        $this->assertSame(SubscriptionState::SOURCE_ADMIN, $state['source']);
        $this->assertSame($subscription->id, $state['subscription_id']);
        $this->assertSame('expired', $state['status']);
        $this->assertTrue($state['ends_at']->isFuture());
    }
}
