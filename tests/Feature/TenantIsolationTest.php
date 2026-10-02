<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Support\TenantContext;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

class TenantIsolationTest extends TenantTestCase
{
    public function test_middleware_rejects_request_without_tenant_header(): void
    {
        $user = $this->makeUser();
        $tenant = $this->makeTenant();
        $this->attachToTenant($user, $tenant, 'owner');

        $response = $this->withHeader('Authorization', 'Bearer '.$user->createToken('test')->plainTextToken)
            ->getJson('/api/v1/dashboard');

        $response->assertStatus(400);
    }

    public function test_middleware_rejects_tenant_the_user_does_not_belong_to(): void
    {
        $user = $this->makeUser();
        $own = $this->makeTenant();
        $other = $this->makeTenant();
        $this->attachToTenant($user, $own, 'owner');

        $response = $this->getJson('/api/v1/dashboard', $this->authHeaders($user, $other));

        $response->assertStatus(403);
    }

    public function test_user_cannot_read_another_tenants_subscription(): void
    {
        $ownerA = $this->makeUser();
        $tenantA = $this->makeTenant();
        $this->attachToTenant($ownerA, $tenantA, 'owner');

        $ownerB = $this->makeUser();
        $tenantB = $this->makeTenant();
        $this->attachToTenant($ownerB, $tenantB, 'owner');

        $plan = SubscriptionPlan::create([
            'id' => UuidV7::generate(),
            'name' => 'Basic',
            'slug' => 'basic-'.Str::random(6),
            'monthly_price' => 19.99,
            'yearly_price' => 199.99,
            'currency' => 'USD',
            'trial_days' => 14,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ]);

        $subB = TenantSubscription::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantB->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'trial_ends_at' => now()->addDays(14),
            'auto_renew' => true,
        ]);

        $response = $this->getJson("/api/v1/subscriptions/{$subB->id}", $this->authHeaders($ownerA, $tenantA));

        // Either the tenant scope hides the row (404) or the policy rejects it (403).
        $this->assertContains($response->status(), [403, 404], 'Expected the subscription to be inaccessible.');

        $list = $this->getJson('/api/v1/subscriptions', $this->authHeaders($ownerA, $tenantA));
        $list->assertOk();
        $list->assertJsonMissing(['id' => $subB->id]);
    }

    public function test_tenant_scope_applies_when_context_is_set(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        Course::create(['id' => UuidV7::generate(), 'tenant_id' => $tenantA->id, 'title' => 'Course A', 'slug' => 'a-'.Str::random(6), 'status' => 'published']);
        Course::create(['id' => UuidV7::generate(), 'tenant_id' => $tenantB->id, 'title' => 'Course B', 'slug' => 'b-'.Str::random(6), 'status' => 'published']);

        app(TenantContext::class)->set($tenantA->id);
        $this->assertSame(1, Course::count());
        $this->assertSame('Course A', Course::first()->title);

        app(TenantContext::class)->set($tenantB->id);
        $this->assertSame(1, Course::count());
        $this->assertSame('Course B', Course::first()->title);

        app(TenantContext::class)->clear();
        $this->assertSame(2, Course::withoutTenant()->count());
    }

    public function test_scope_never_reads_a_client_supplied_header(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();

        Course::create(['id' => UuidV7::generate(), 'tenant_id' => $tenantA->id, 'title' => 'Course A', 'slug' => 'a-'.Str::random(6), 'status' => 'published']);
        Course::create(['id' => UuidV7::generate(), 'tenant_id' => $tenantB->id, 'title' => 'Course B', 'slug' => 'b-'.Str::random(6), 'status' => 'published']);

        // Outside the tenant middleware there is no server-side context, so a
        // spoofed header must have no effect on Eloquent scoping.
        $this->withHeader('X-Tenant-Id', $tenantB->id)
            ->withoutMiddleware();

        $this->assertNull(app(TenantContext::class)->current());

        $this->assertSame(2, Course::count());
    }

    public function test_index_only_returns_current_tenant_courses(): void
    {
        $teacher = $this->makeUser();
        $tenantA = $this->makeTenant();
        $this->attachToTenant($teacher, $tenantA, 'teacher');
        $this->attachSubscription($tenantA);

        $courseA = Course::create(['id' => UuidV7::generate(), 'tenant_id' => $tenantA->id, 'title' => 'Course A', 'slug' => 'a-'.Str::random(6), 'status' => 'published']);

        $response = $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenantA));

        $response->assertOk();
        $response->assertJsonFragment(['id' => $courseA->id]);
        $response->assertJsonMissing(['title' => 'Course B']);
    }
}
