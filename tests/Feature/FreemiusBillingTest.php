<?php

namespace Tests\Feature;

use App\Models\FsEntitlement;
use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Plans\PlanEngine;
use App\Services\FreemiusGateway;
use App\Services\FreemiusPlanMapper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Freemius-only platform subscription billing.
 *
 * Covers: checkout initiation, entitlement activation via API re-fetch,
 * webhook signature verification, idempotency, replay/out-of-order handling,
 * cancellation, the customer portal, tenant isolation, and the guarantee
 * that a browser redirect alone never activates access.
 */
class FreemiusBillingTest extends TenantTestCase
{
    private function configureFreemius(): void
    {
        config()->set('services.freemius.enabled', true);
        config()->set('services.freemius.product_id', '777');
        config()->set('services.freemius.api_token', 'test-api-token');
        config()->set('services.freemius.secret_key', 'test-secret-key');
        config()->set('services.freemius.public_key', 'test-public-key');
        config()->set('services.freemius.api_url', 'https://api.freemius.test');
        config()->set('services.freemius.checkout_url', 'https://checkout.freemius.test');
    }

    private function makePlan(array $attrs = []): SubscriptionPlan
    {
        $plan = SubscriptionPlan::create(array_merge([
            'id' => (string) Str::uuid(),
            'name' => 'Starter',
            'slug' => 'starter',
            'monthly_price' => 49.99,
            'yearly_price' => 499.99,
            'currency' => 'JOD',
            'trial_days' => 7,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
            'metadata' => ['freemius_monthly_plan_id' => '1010', 'freemius_yearly_plan_id' => '2020'],
        ], $attrs));

        PlanFeature::create([
            'id' => (string) Str::uuid(),
            'plan_id' => $plan->id,
            'feature_key' => 'courses_limit',
            'feature_value' => '5',
            'feature_type' => 'numeric',
            'sort_order' => 0,
        ]);

        return $plan;
    }

    private function makeLicensePayload(string $licenseId, string $email, array $overrides = []): array
    {
        return array_merge([
            'id' => $licenseId,
            'user_id' => '9001',
            'plan_id' => '1010',
            'pricing_id' => '5005',
            'expiration' => now()->addMonth()->toIso8601String(),
            'is_canceled' => false,
            'user' => ['id' => '9001', 'email' => $email],
        ], $overrides);
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, 'test-secret-key');
    }

    /**
     * Attach a user as the tenant owner AND bind the tenant's owner_id so the
     * Freemius entitlement sync can resolve the customer by owner email.
     */
    private function attachOwner(User $user, Tenant $tenant): void
    {
        $this->attachToTenant($user, $tenant, 'owner');
        $tenant->update(['owner_id' => $user->id]);
    }

    // ── Checkout initiation ──────────────────────────────────────────

    public function test_gateways_reports_freemius_only(): void
    {
        $this->configureFreemius();

        $res = $this->getJson('/api/v1/payment-gateways');

        $res->assertOk()
            ->assertJsonPath('freemius.enabled', true)
            ->assertJsonPath('freemius.configured', true)
            ->assertJsonPath('freemius.secrets_from_env', true)
            ->assertJsonMissingPath('paddle')
            ->assertJsonMissingPath('paypal')
            ->assertJsonMissingPath('paytabs')
            ->assertJsonMissingPath('lemonsqueezy')
            ->assertJsonMissingPath('manual');
    }

    public function test_checkout_requires_authentication(): void
    {
        $this->configureFreemius();

        $this->postJson('/api/v1/subscriptions/freemius/checkout', [])
            ->assertUnauthorized();
    }

    public function test_checkout_returns_hosted_checkout_url_with_prefilled_email(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $user = $this->makeUser(['email' => 'owner@academy.test']);
        $this->attachOwner($user, $tenant);

        $res = $this->postJson('/api/v1/subscriptions/freemius/checkout', [
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
        ], $this->authHeaders($user, $tenant));

        $res->assertOk()
            ->assertJsonPath('mode', 'checkout');

        $url = $res->json('checkout_url');
        $this->assertStringContainsString('/product/777/plan/1010/', $url);
        $this->assertStringContainsString('user_email=owner%40academy.test', $url);
        $this->assertStringContainsString('readonly_user=true', $url);
    }

    public function test_checkout_rejects_unmapped_plan(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan(['metadata' => []]);
        $tenant = $this->makeTenant();
        $user = $this->makeUser();
        $this->attachOwner($user, $tenant);

        // No env mapping and no metadata mapping for slug "starter" → 422.
        config()->set('FREEMIUS_PLAN_STARTER_MONTHLY_ID', null);

        $this->postJson('/api/v1/subscriptions/freemius/checkout', [
            'plan_id' => $plan->id,
        ], $this->authHeaders($user, $tenant))->assertStatus(422);
    }

    public function test_checkout_returns_503_when_not_configured(): void
    {
        config()->set('services.freemius.enabled', false);
        config()->set('services.freemius.api_token', '');

        $plan = $this->makePlan(['metadata' => ['freemius_monthly_plan_id' => '1010']]);
        $tenant = $this->makeTenant();
        $user = $this->makeUser();
        $this->attachOwner($user, $tenant);

        $this->postJson('/api/v1/subscriptions/freemius/checkout', [
            'plan_id' => $plan->id,
        ], $this->authHeaders($user, $tenant))->assertStatus(503);
    }

    public function test_checkout_blocks_conflicting_legacy_subscription(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $user = $this->makeUser();
        $this->attachOwner($user, $tenant);

        // A legacy (non-Freemius) active local subscription is a conflict:
        // the tenant must resolve it before starting a Freemius checkout.
        TenantSubscription::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'auto_renew' => true,
        ]);

        $this->postJson('/api/v1/subscriptions/freemius/checkout', [
            'plan_id' => $plan->id,
        ], $this->authHeaders($user, $tenant))->assertStatus(422);
    }

    public function test_existing_own_license_upgrades_instead_of_blocking(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $user = $this->makeUser(['email' => 'owner@academy.test']);
        $this->attachOwner($user, $tenant);

        FsEntitlement::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'fs_license_id' => 'LIC-OWN',
            'plan_id' => $plan->id,
            'type' => 'subscription',
            'status' => 'active',
            'expiration' => now()->addMonth(),
            'is_canceled' => false,
        ]);

        Http::fake([
            'api.freemius.test/v1/products/777/licenses/LIC-OWN/checkout/link.json' => Http::response([
                'url' => 'https://checkout.freemius.test/upgrade/own',
                'settings' => [],
                'expires' => now()->addHour()->toIso8601String(),
            ]),
        ]);

        $this->postJson('/api/v1/subscriptions/freemius/checkout', [
            'plan_id' => $plan->id,
        ], $this->authHeaders($user, $tenant))
            ->assertOk()
            ->assertJsonPath('mode', 'upgrade');
    }

    // ── Webhook security ─────────────────────────────────────────────

    public function test_webhook_rejects_invalid_signature(): void
    {
        $this->configureFreemius();

        $body = json_encode(['id' => 'evt_1', 'type' => 'license.created']);

        $this->call('POST', '/api/v1/payments/freemius-webhook', [], [], [], [
            'HTTP_X_SIGNATURE' => 'deadbeef',
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(401);

        $this->assertDatabaseCount('webhook_events', 0);
    }

    public function test_webhook_signature_is_verified_before_any_processing(): void
    {
        $this->configureFreemius();

        // Valid signature but malformed body → 422 (parsed only after verify).
        $body = 'not-json';
        $this->call('POST', '/api/v1/payments/freemius-webhook', [], [], [], [
            'HTTP_X_SIGNATURE' => $this->sign($body),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(422);
    }

    public function test_webhook_activates_entitlement_via_server_side_refetch(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $user = $this->makeUser(['email' => 'owner@academy.test']);
        $this->attachOwner($user, $tenant);

        Http::fake([
            'api.freemius.test/v1/products/777/licenses/LIC-1.json' => Http::response(
                $this->makeLicensePayload('LIC-1', 'owner@academy.test')
            ),
            'api.freemius.test/v1/products/777/licenses/LIC-1/subscription.json' => Http::response([
                'id' => 'SUB-1',
                'plan_id' => '1010',
                'status' => 'active',
                'billing_cycle' => 'monthly',
            ]),
        ]);

        $body = json_encode([
            'id' => 'evt_1',
            'type' => 'license.created',
            'objects' => ['license' => ['id' => 'LIC-1']],
        ]);

        $this->call('POST', '/api/v1/payments/freemius-webhook', [], [], [], [
            'HTTP_X_SIGNATURE' => $this->sign($body),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertDatabaseHas('fs_entitlements', [
            'tenant_id' => $tenant->id,
            'fs_license_id' => 'LIC-1',
            'plan_id' => $plan->id,
            'is_canceled' => false,
        ]);

        $this->assertTrue(app(PlanEngine::class)->hasFeature($tenant->id, 'courses_limit'));
    }

    public function test_webhook_is_idempotent_on_replay(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $user = $this->makeUser(['email' => 'owner@academy.test']);
        $this->attachOwner($user, $tenant);

        Http::fake([
            'api.freemius.test/v1/products/777/licenses/LIC-1.json' => Http::response(
                $this->makeLicensePayload('LIC-1', 'owner@academy.test')
            ),
            'api.freemius.test/v1/products/777/licenses/LIC-1/subscription.json' => Http::response([
                'id' => 'SUB-1', 'plan_id' => '1010', 'status' => 'active', 'billing_cycle' => 'monthly',
            ]),
        ]);

        $body = json_encode(['id' => 'evt_dup', 'type' => 'license.created', 'objects' => ['license' => ['id' => 'LIC-1']]]);
        $headers = ['HTTP_X_SIGNATURE' => $this->sign($body), 'CONTENT_TYPE' => 'application/json'];

        $this->call('POST', '/api/v1/payments/freemius-webhook', [], [], [], $headers, $body)->assertOk();
        $this->call('POST', '/api/v1/payments/freemius-webhook', [], [], [], $headers, $body)->assertOk();

        // Exactly one webhook ledger row and one entitlement — no double-apply.
        $this->assertSame(1, WebhookEvent::where('provider', 'freemius')->where('event_id', 'evt_dup')->count());
        $this->assertSame(1, FsEntitlement::where('fs_license_id', 'LIC-1')->count());
    }

    public function test_webhook_out_of_order_redelivery_self_heals(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $user = $this->makeUser(['email' => 'owner@academy.test']);
        $this->attachOwner($user, $tenant);

        // First, an "expired" license event arrives; then an out-of-order
        // "extended" event with a live license. The API is stubbed with a
        // sequence (a second Http::fake() would NOT replace the first stub).
        Http::fakeSequence('api.freemius.test/v1/products/777/licenses/LIC-9.json')
            ->push($this->makeLicensePayload('LIC-9', 'owner@academy.test', [
                'expiration' => now()->subDay()->toIso8601String(),
            ]))
            ->push($this->makeLicensePayload('LIC-9', 'owner@academy.test', [
                'expiration' => now()->addMonth()->toIso8601String(),
            ]));

        Http::fakeSequence('api.freemius.test/v1/products/777/licenses/LIC-9/subscription.json')
            ->push(['id' => 'SUB-9', 'plan_id' => '1010', 'status' => 'expired'])
            ->push(['id' => 'SUB-9', 'plan_id' => '1010', 'status' => 'active']);

        $body = json_encode(['id' => 'evt_a', 'type' => 'license.expired', 'objects' => ['license' => ['id' => 'LIC-9']]]);
        $this->call('POST', '/api/v1/payments/freemius-webhook', [], [], [], [
            'HTTP_X_SIGNATURE' => $this->sign($body), 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertFalse(app(PlanEngine::class)->hasFeature($tenant->id, 'courses_limit'));

        // Out-of-order "extended" event with a live license.
        $body2 = json_encode(['id' => 'evt_b', 'type' => 'license.extended', 'objects' => ['license' => ['id' => 'LIC-9']]]);
        $this->call('POST', '/api/v1/payments/freemius-webhook', [], [], [], [
            'HTTP_X_SIGNATURE' => $this->sign($body2), 'CONTENT_TYPE' => 'application/json',
        ], $body2)->assertOk();

        $this->assertTrue(app(PlanEngine::class)->hasFeature($tenant->id, 'courses_limit'));
    }

    public function test_license_deleted_removes_entitlement(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $user = $this->makeUser(['email' => 'owner@academy.test']);
        $this->attachOwner($user, $tenant);

        FsEntitlement::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'fs_license_id' => 'LIC-DEL',
            'plan_id' => $plan->id,
            'type' => 'subscription',
            'status' => 'active',
            'expiration' => now()->addMonth(),
            'is_canceled' => false,
        ]);

        $body = json_encode(['id' => 'evt_del', 'type' => 'license.deleted', 'data' => ['license_id' => 'LIC-DEL']]);
        $this->call('POST', '/api/v1/payments/freemius-webhook', [], [], [], [
            'HTTP_X_SIGNATURE' => $this->sign($body), 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertDatabaseMissing('fs_entitlements', ['fs_license_id' => 'LIC-DEL']);
    }

    public function test_subscription_events_are_ignored_for_entitlements(): void
    {
        $this->configureFreemius();
        $this->makePlan();
        $tenant = $this->makeTenant();
        $user = $this->makeUser(['email' => 'owner@academy.test']);
        $this->attachOwner($user, $tenant);

        // A subscription.* event must be acknowledged but never create an
        // entitlement (only license.* events do).
        $body = json_encode(['id' => 'evt_sub', 'type' => 'subscription.updated', 'data' => ['id' => 'SUB-1']]);
        $this->call('POST', '/api/v1/payments/freemius-webhook', [], [], [], [
            'HTTP_X_SIGNATURE' => $this->sign($body), 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        Http::assertNothingSent();
        $this->assertDatabaseCount('fs_entitlements', 0);
    }

    // ── Return / portal / cancel ─────────────────────────────────────

    public function test_complete_redirect_without_license_does_not_activate(): void
    {
        $this->configureFreemius();
        $this->makePlan();
        $tenant = $this->makeTenant();
        $user = $this->makeUser();
        $this->attachOwner($user, $tenant);

        // A spoofed/empty return must never grant access.
        $this->getJson('/api/v1/subscriptions/freemius/complete', $this->authHeaders($user, $tenant))
            ->assertOk()
            ->assertJsonPath('status', 'pending');

        $this->assertDatabaseCount('fs_entitlements', 0);
    }

    public function test_complete_with_foreign_license_does_not_activate(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();

        $tenant = $this->makeTenant();
        $user = $this->makeUser(['email' => 'owner@academy.test']);
        $this->attachOwner($user, $tenant);

        $other = $this->makeTenant();
        $otherUser = $this->makeUser(['email' => 'other@academy.test']);
        $this->attachOwner($otherUser, $other);

        // License belongs to a DIFFERENT tenant.
        Http::fake([
            'api.freemius.test/v1/products/777/licenses/LIC-FOREIGN.json' => Http::response(
                $this->makeLicensePayload('LIC-FOREIGN', 'other@academy.test')
            ),
            'api.freemius.test/v1/products/777/licenses/LIC-FOREIGN/subscription.json' => Http::response([
                'id' => 'SUB-F', 'plan_id' => '1010', 'status' => 'active',
            ]),
        ]);

        $this->getJson('/api/v1/subscriptions/freemius/complete?license_id=LIC-FOREIGN', $this->authHeaders($user, $tenant))
            ->assertOk()
            ->assertJsonPath('status', 'pending');

        // The entitlement was created for the OTHER tenant, not the caller.
        $this->assertDatabaseHas('fs_entitlements', ['fs_license_id' => 'LIC-FOREIGN', 'tenant_id' => $other->id]);
        $this->assertDatabaseMissing('fs_entitlements', ['fs_license_id' => 'LIC-FOREIGN', 'tenant_id' => $tenant->id]);
    }

    public function test_cancel_requires_active_entitlement(): void
    {
        $this->configureFreemius();
        $tenant = $this->makeTenant();
        $user = $this->makeUser();
        $this->attachOwner($user, $tenant);

        $this->postJson('/api/v1/subscriptions/freemius/cancel', [], $this->authHeaders($user, $tenant))
            ->assertStatus(404);
    }

    public function test_cancel_calls_freemius_and_keeps_access_until_expiration(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $user = $this->makeUser();
        $this->attachOwner($user, $tenant);

        FsEntitlement::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'fs_license_id' => 'LIC-C',
            'plan_id' => $plan->id,
            'type' => 'subscription',
            'status' => 'active',
            'expiration' => now()->addDays(20),
            'is_canceled' => false,
        ]);

        // Access is active BEFORE cancel.
        $this->assertTrue(app(PlanEngine::class)->hasFeature($tenant->id, 'courses_limit'));

        Http::fake([
            'api.freemius.test/v1/products/777/licenses/LIC-C/subscription.json' => Http::response([], 200),
        ]);

        $this->postJson('/api/v1/subscriptions/freemius/cancel', [], $this->authHeaders($user, $tenant))
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        Http::assertSent(fn ($req) => $req->method() === 'DELETE'
            && str_contains($req->url(), '/licenses/LIC-C/subscription.json'));

        // The local entitlement is marked cancelled, but the subscription
        // mirror keeps the paid period so access is not cut mid-term.
        $ent = FsEntitlement::where('fs_license_id', 'LIC-C')->first();
        $this->assertTrue($ent->is_canceled);
        $this->assertTrue($ent->expiration->isFuture());
        $this->assertTrue($ent->isActive());
        $this->assertTrue($ent->isCancelledAtPeriodEnd());

        // CRITICAL: access IS preserved after cancelling (paid period running).
        $this->assertTrue(app(PlanEngine::class)->hasFeature($tenant->id, 'courses_limit'));
        $this->assertSame('freemius', app(\App\Plans\SubscriptionState::class)->resolve($tenant->id)['source']);
        $this->assertTrue(app(\App\Plans\SubscriptionState::class)->resolve($tenant->id)['cancel_at_period_end']);
    }

    public function test_cancel_then_expiry_revokes_access(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $user = $this->makeUser();
        $this->attachOwner($user, $tenant);

        // Cancelled subscription whose paid period is still running.
        $ent = FsEntitlement::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'fs_license_id' => 'LIC-EXP',
            'plan_id' => $plan->id,
            'type' => 'subscription',
            'status' => 'cancelled',
            'expiration' => now()->addDays(10),
            'is_canceled' => true,
        ]);

        $this->assertTrue(app(PlanEngine::class)->hasFeature($tenant->id, 'courses_limit'));

        // The paid period elapses.
        $ent->update(['expiration' => now()->subDay()]);
        PlanEngine::bumpVersion();

        $this->assertFalse(app(PlanEngine::class)->hasFeature($tenant->id, 'courses_limit'));
        $this->assertSame('none', app(\App\Plans\SubscriptionState::class)->resolve($tenant->id)['source']);
    }

    public function test_revoked_license_stops_access_immediately(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $user = $this->makeUser();
        $this->attachOwner($user, $tenant);

        FsEntitlement::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'fs_license_id' => 'LIC-REV',
            'plan_id' => $plan->id,
            'type' => 'subscription',
            'status' => 'revoked',
            'expiration' => now()->addDays(10),
            'is_canceled' => false,
        ]);

        $this->assertFalse(app(PlanEngine::class)->hasFeature($tenant->id, 'courses_limit'));
    }

    // ── No revival of expired/deleted licenses from stale local rows ─

    public function test_expired_freemius_local_row_does_not_revive_access(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $user = $this->makeUser();
        $this->attachOwner($user, $tenant);

        // A stale ACTIVE local mirror row owned by Freemius, but the license
        // itself is gone/expired. It must NOT grant access.
        \App\Models\TenantSubscription::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_source' => 'freemius',
            'fs_license_id' => 'LIC-GONE',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addMonth(),
            'auto_renew' => true,
        ]);

        $this->assertFalse(app(PlanEngine::class)->hasFeature($tenant->id, 'courses_limit'));
        $this->assertSame('none', app(\App\Plans\SubscriptionState::class)->resolve($tenant->id)['source']);
    }

    public function test_license_deleted_revokes_local_mirror_and_cache(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $user = $this->makeUser();
        $this->attachOwner($user, $tenant);

        FsEntitlement::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'fs_license_id' => 'LIC-DEL2',
            'plan_id' => $plan->id,
            'type' => 'subscription',
            'status' => 'active',
            'expiration' => now()->addMonth(),
            'is_canceled' => false,
        ]);

        // Local mirror row (active) exists alongside the entitlement.
        \App\Models\TenantSubscription::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_source' => 'freemius',
            'fs_license_id' => 'LIC-DEL2',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'auto_renew' => true,
        ]);

        $this->assertTrue(app(PlanEngine::class)->hasFeature($tenant->id, 'courses_limit'));

        $body = json_encode(['id' => 'evt_del2', 'type' => 'license.deleted', 'data' => ['license_id' => 'LIC-DEL2']]);
        $this->call('POST', '/api/v1/payments/freemius-webhook', [], [], [], [
            'HTTP_X_SIGNATURE' => $this->sign($body), 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        // Entitlement gone AND the local mirror is no longer active.
        $this->assertDatabaseMissing('fs_entitlements', ['fs_license_id' => 'LIC-DEL2']);
        $this->assertFalse(app(PlanEngine::class)->hasFeature($tenant->id, 'courses_limit'));
        $this->assertDatabaseMissing('tenant_subscriptions', [
            'fs_license_id' => 'LIC-DEL2',
            'status' => 'active',
        ]);
    }

    // ── Webhook recovery: transient failure retries, no lost event ───

    public function test_webhook_transient_api_failure_does_not_mark_processed_and_can_retry(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $user = $this->makeUser(['email' => 'owner@academy.test']);
        $this->attachOwner($user, $tenant);

        // First delivery: the Freemius API is down (connection error).
        // Then it recovers — a stateful fake (a second Http::fake() would NOT
        // replace the first stub).
        $calls = 0;
        Http::fake([
            'api.freemius.test/*' => function ($request) use (&$calls) {
                $calls++;
                // Fail the first delivery INCLUDING its internal retries; the
                // second webhook delivery then succeeds.
                if ($calls <= 3) {
                    throw new \Illuminate\Http\Client\ConnectionException('timeout');
                }
                if (str_ends_with($request->url(), '/subscription.json')) {
                    return Http::response(['id' => 'SUB-F', 'plan_id' => '1010', 'status' => 'active', 'billing_cycle' => 'monthly']);
                }

                return Http::response($this->makeLicensePayload('LIC-FLAKY', 'owner@academy.test'));
            },
        ]);

        $body = json_encode(['id' => 'evt_flaky', 'type' => 'license.created', 'objects' => ['license' => ['id' => 'LIC-FLAKY']]]);
        $this->call('POST', '/api/v1/payments/freemius-webhook', [], [], [], [
            'HTTP_X_SIGNATURE' => $this->sign($body), 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(500);

        // The event must NOT be marked processed (so it can be retried).
        $event = WebhookEvent::where('event_id', 'evt_flaky')->first();
        $this->assertNotNull($event);
        $this->assertNotSame(WebhookEvent::STATUS_PROCESSED, $event->processing_status);
        $this->assertDatabaseCount('fs_entitlements', 0);

        // Second delivery: the API has recovered.
        $this->call('POST', '/api/v1/payments/freemius-webhook', [], [], [], [
            'HTTP_X_SIGNATURE' => $this->sign($body), 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        // Now it is processed and the entitlement exists exactly once.
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, WebhookEvent::where('event_id', 'evt_flaky')->first()->processing_status);
        $this->assertSame(1, FsEntitlement::where('fs_license_id', 'LIC-FLAKY')->count());
        $this->assertTrue(app(PlanEngine::class)->hasFeature($tenant->id, 'courses_limit'));
    }

    public function test_reconcile_job_retries_failed_events_and_never_double_applies(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $user = $this->makeUser(['email' => 'owner@academy.test']);
        $this->attachOwner($user, $tenant);

        // A previously-failed event sitting in the ledger.
        $raw = json_encode(['id' => 'evt_stuck', 'type' => 'license.created', 'objects' => ['license' => ['id' => 'LIC-STUCK']]]);
        WebhookEvent::create([
            'id' => (string) Str::uuid(),
            'provider' => WebhookEvent::PROVIDER_FREEMIUS,
            'event_id' => 'evt_stuck',
            'event_type' => 'license.created',
            'payload_hash' => hash('sha256', $raw),
            'payload' => json_decode($raw, true),
            'received_at' => now()->subHour(),
            'processing_status' => WebhookEvent::STATUS_FAILED,
            'attempts' => 1,
            'locked_until' => null,
        ]);

        Http::fake([
            'api.freemius.test/v1/products/777/licenses/LIC-STUCK.json' => Http::response(
                $this->makeLicensePayload('LIC-STUCK', 'owner@academy.test')
            ),
            'api.freemius.test/v1/products/777/licenses/LIC-STUCK/subscription.json' => Http::response([
                'id' => 'SUB-S', 'plan_id' => '1010', 'status' => 'active', 'billing_cycle' => 'monthly',
            ]),
        ]);

        (new \App\Jobs\ReconcileFreemiusWebhooksJob)->handle(app(\App\Services\FsEntitlementSyncService::class));

        $this->assertSame(1, FsEntitlement::where('fs_license_id', 'LIC-STUCK')->count());
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, WebhookEvent::where('event_id', 'evt_stuck')->first()->processing_status);

        // Running again is a no-op (already processed, not re-claimed).
        (new \App\Jobs\ReconcileFreemiusWebhooksJob)->handle(app(\App\Services\FsEntitlementSyncService::class));
        $this->assertSame(1, FsEntitlement::where('fs_license_id', 'LIC-STUCK')->count());
    }

    public function test_concurrent_duplicate_event_is_not_processed_twice(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $user = $this->makeUser(['email' => 'owner@academy.test']);
        $this->attachOwner($user, $tenant);

        Http::fake([
            'api.freemius.test/v1/products/777/licenses/LIC-CC.json' => Http::response(
                $this->makeLicensePayload('LIC-CC', 'owner@academy.test')
            ),
            'api.freemius.test/v1/products/777/licenses/LIC-CC/subscription.json' => Http::response([
                'id' => 'SUB-CC', 'plan_id' => '1010', 'status' => 'active', 'billing_cycle' => 'monthly',
            ]),
        ]);

        $body = json_encode(['id' => 'evt_cc', 'type' => 'license.created', 'objects' => ['license' => ['id' => 'LIC-CC']]]);

        // First delivery holds a processing lock; a second identical delivery
        // while locked must be acknowledged WITHOUT reprocessing.
        $first = WebhookEvent::claim(WebhookEvent::PROVIDER_FREEMIUS, 'evt_cc', 'license.created', $body);
        $this->assertNotNull($first);
        $this->assertSame(WebhookEvent::STATUS_PROCESSING, $first->fresh()->processing_status);

        $second = WebhookEvent::claim(WebhookEvent::PROVIDER_FREEMIUS, 'evt_cc', 'license.created', $body);
        $this->assertNull($second, 'A locked duplicate must not be re-claimed.');
    }

    // ── Tenant binding: no cross-tenant rebinding / email collision ──

    public function test_bound_license_is_never_rebound_on_email_change(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();

        $tenantA = $this->makeTenant();
        $userA = $this->makeUser(['email' => 'owner@academy.test']);
        $this->attachOwner($userA, $tenantA);

        $tenantB = $this->makeTenant();
        $userB = $this->makeUser(['email' => 'other@academy.test']);
        $this->attachOwner($userB, $tenantB);

        // License already bound to tenant A.
        FsEntitlement::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantA->id,
            'fs_license_id' => 'LIC-BOUND',
            'plan_id' => $plan->id,
            'type' => 'subscription',
            'status' => 'active',
            'expiration' => now()->addMonth(),
            'is_canceled' => false,
        ]);

        // The customer's email on the license now points at tenant B's owner.
        Http::fake([
            'api.freemius.test/v1/products/777/licenses/LIC-BOUND.json' => Http::response(
                $this->makeLicensePayload('LIC-BOUND', 'other@academy.test')
            ),
            'api.freemius.test/v1/products/777/licenses/LIC-BOUND/subscription.json' => Http::response([
                'id' => 'SUB-B', 'plan_id' => '1010', 'status' => 'active',
            ]),
        ]);

        $body = json_encode(['id' => 'evt_bound', 'type' => 'license.updated', 'objects' => ['license' => ['id' => 'LIC-BOUND']]]);
        $this->call('POST', '/api/v1/payments/freemius-webhook', [], [], [], [
            'HTTP_X_SIGNATURE' => $this->sign($body), 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        // The license stays with tenant A; tenant B never gains access.
        $ent = FsEntitlement::where('fs_license_id', 'LIC-BOUND')->first();
        $this->assertSame($tenantA->id, $ent->tenant_id);
        $this->assertTrue(app(PlanEngine::class)->hasFeature($tenantA->id, 'courses_limit'));
        $this->assertFalse(app(PlanEngine::class)->hasFeature($tenantB->id, 'courses_limit'));
    }

    public function test_checkout_intent_binds_license_to_correct_tenant(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $user = $this->makeUser(['email' => 'newowner@academy.test']);
        $this->attachOwner($user, $tenant);

        // No owner-email match is needed: a server-side intent row exists.
        \App\Models\TenantSubscription::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'pending',
            'billing_source' => 'freemius',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'auto_renew' => true,
            'metadata' => ['freemius_intent_email' => 'newowner@academy.test'],
        ]);

        Http::fake([
            'api.freemius.test/v1/products/777/licenses/LIC-INTENT.json' => Http::response(
                $this->makeLicensePayload('LIC-INTENT', 'newowner@academy.test')
            ),
            'api.freemius.test/v1/products/777/licenses/LIC-INTENT/subscription.json' => Http::response([
                'id' => 'SUB-I', 'plan_id' => '1010', 'status' => 'active', 'billing_cycle' => 'monthly',
            ]),
        ]);

        $body = json_encode(['id' => 'evt_intent', 'type' => 'license.created', 'objects' => ['license' => ['id' => 'LIC-INTENT']]]);
        $this->call('POST', '/api/v1/payments/freemius-webhook', [], [], [], [
            'HTTP_X_SIGNATURE' => $this->sign($body), 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertDatabaseHas('fs_entitlements', [
            'fs_license_id' => 'LIC-INTENT',
            'tenant_id' => $tenant->id,
        ]);
    }

    public function test_portal_returns_freemius_link(): void
    {
        $this->configureFreemius();
        $tenant = $this->makeTenant();
        $user = $this->makeUser(['email' => 'owner@academy.test']);
        $this->attachOwner($user, $tenant);

        Http::fake([
            'api.freemius.test/v1/products/777/portal/login.json' => Http::response(['link' => 'https://portal.freemius.test/x']),
        ]);

        $this->postJson('/api/v1/subscriptions/freemius/portal', [], $this->authHeaders($user, $tenant))
            ->assertOk()
            ->assertJsonPath('portal_url', 'https://portal.freemius.test/x');
    }

    // ── Plan mapping ─────────────────────────────────────────────────

    public function test_plan_mapper_prefers_admin_metadata_over_env(): void
    {
        $plan = $this->makePlan();
        $this->assertSame('1010', FreemiusPlanMapper::planId($plan, 'monthly'));
        $this->assertSame('2020', FreemiusPlanMapper::planId($plan, 'yearly'));
    }

    public function test_plan_mapper_falls_back_to_env(): void
    {
        $plan = $this->makePlan(['slug' => 'pro', 'metadata' => []]);
        config()->set('FREEMIUS_PLAN_PRO_MONTHLY_ID', '555');

        $this->assertSame('555', FreemiusPlanMapper::planId($plan, 'monthly'));
    }

    public function test_plan_mapper_reverse_lookup(): void
    {
        $plan = $this->makePlan();
        $this->assertSame($plan->id, FreemiusPlanMapper::localPlanForFreemiusPlanId('1010')?->id);
        $this->assertSame($plan->id, FreemiusPlanMapper::localPlanForFreemiusPlanId('2020')?->id);
        $this->assertNull(FreemiusPlanMapper::localPlanForFreemiusPlanId('9999'));
    }

    // ── Tenant isolation ─────────────────────────────────────────────

    public function test_entitlement_sync_isolates_tenants_by_email(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();

        $a = $this->makeTenant();
        $ua = $this->makeUser(['email' => 'a@academy.test']);
        $this->attachOwner($ua, $a);

        $b = $this->makeTenant();
        $ub = $this->makeUser(['email' => 'b@academy.test']);
        $this->attachOwner($ub, $b);

        Http::fake([
            'api.freemius.test/v1/products/777/licenses/LIC-B.json' => Http::response(
                $this->makeLicensePayload('LIC-B', 'b@academy.test')
            ),
            'api.freemius.test/v1/products/777/licenses/LIC-B/subscription.json' => Http::response([
                'id' => 'SUB-B', 'plan_id' => '1010', 'status' => 'active',
            ]),
        ]);

        $body = json_encode(['id' => 'evt_iso', 'type' => 'license.created', 'objects' => ['license' => ['id' => 'LIC-B']]]);
        $this->call('POST', '/api/v1/payments/freemius-webhook', [], [], [], [
            'HTTP_X_SIGNATURE' => $this->sign($body), 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        // Entitlement granted to B only; A must never gain access.
        $this->assertTrue(app(PlanEngine::class)->hasFeature($b->id, 'courses_limit'));
        $this->assertFalse(app(PlanEngine::class)->hasFeature($a->id, 'courses_limit'));
    }

    public function test_unknown_customer_email_is_acknowledged_without_entitlement(): void
    {
        $this->configureFreemius();
        $this->makePlan();

        Http::fake([
            'api.freemius.test/v1/products/777/licenses/LIC-X.json' => Http::response(
                $this->makeLicensePayload('LIC-X', 'stranger@nowhere.test')
            ),
        ]);

        $body = json_encode(['id' => 'evt_x', 'type' => 'license.created', 'objects' => ['license' => ['id' => 'LIC-X']]]);
        $this->call('POST', '/api/v1/payments/freemius-webhook', [], [], [], [
            'HTTP_X_SIGNATURE' => $this->sign($body), 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertDatabaseCount('fs_entitlements', 0);
    }

    // ── Signature verification unit ──────────────────────────────────

    public function test_signature_verification_is_constant_time_and_strict(): void
    {
        $this->configureFreemius();

        $this->assertTrue(FreemiusGateway::verifySignature('payload', $this->sign('payload')));
        $this->assertFalse(FreemiusGateway::verifySignature('payload', 'wrong'));
        $this->assertFalse(FreemiusGateway::verifySignature('payload', ''));
    }

    // ── Currency: platform subscriptions are always USD ──────────────

    public function test_platform_subscription_prices_are_always_usd(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser(['is_super_admin' => true]);
        $this->attachToTenant($admin, $tenant, 'owner');

        // Even if the client sends JOD, the server forces USD.
        $res = $this->postJson('/api/v1/admin/plans', [
            'name' => 'Test Plan',
            'slug' => 'test-plan-'.uniqid(),
            'monthly_price' => 29,
            'yearly_price' => 290,
            'currency' => 'JOD',
            'is_active' => true,
        ], $this->authHeaders($admin, $tenant));

        $res->assertCreated();
        $this->assertDatabaseHas('subscription_plans', [
            'id' => $res->json('plan.id'),
            'currency' => 'USD',
        ]);
    }

    // ── Admin-only Freemius plan mapping ─────────────────────────────

    public function test_teacher_cannot_change_freemius_mapping_or_prices(): void
    {
        $tenant = $this->makeTenant();
        $plan = $this->makePlan();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        // A non-super-admin teacher cannot mutate plans at all (blocked by the
        // admin gate and/or the subscription gate — never 2xx).
        $res = $this->putJson("/api/v1/admin/plans/{$plan->id}", [
            'freemius_monthly_plan_id' => '999999',
            'monthly_price' => 1,
        ], $this->authHeaders($teacher, $tenant));

        $this->assertTrue(in_array($res->status(), [402, 403], true), 'Expected the teacher to be denied, got '.$res->status());

        $this->assertSame('1010', FreemiusPlanMapper::planId($plan->fresh(), 'monthly'));
    }

    public function test_admin_can_set_freemius_mapping(): void
    {
        $tenant = $this->makeTenant();
        $plan = $this->makePlan(['metadata' => []]);
        $admin = $this->makeUser(['is_super_admin' => true]);
        $this->attachToTenant($admin, $tenant, 'owner');

        $this->putJson("/api/v1/admin/plans/{$plan->id}", [
            'freemius_monthly_plan_id' => '888',
            'freemius_yearly_plan_id' => '889',
        ], $this->authHeaders($admin, $tenant))->assertOk();

        $this->assertSame('888', FreemiusPlanMapper::planId($plan->fresh(), 'monthly'));
        $this->assertSame('889', FreemiusPlanMapper::planId($plan->fresh(), 'yearly'));
    }
}
