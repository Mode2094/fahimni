<?php

namespace Tests\Feature;

use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FreemiusGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Verified price synchronisation between the Super Admin panel and Freemius.
 *
 * Covers: developer-scope signature, honest failure when credentials are
 * missing, successful publish + re-read + adopt, mismatch never adopting the
 * new price, rejected writes, concurrency, and the checkout price guard.
 */
class FreemiusPriceSyncTest extends TenantTestCase
{
    private function configureFreemius(bool $withDeveloper = true): void
    {
        config()->set('services.freemius.enabled', true);
        config()->set('services.freemius.product_id', '777');
        config()->set('services.freemius.api_token', 'test-api-token');
        config()->set('services.freemius.secret_key', 'test-secret-key');
        config()->set('services.freemius.public_key', 'test-public-key');
        config()->set('services.freemius.api_url', 'https://api.freemius.test');
        config()->set('services.freemius.checkout_url', 'https://checkout.freemius.test');
        config()->set('services.freemius.developer_id', $withDeveloper ? '123' : '');
        config()->set('services.freemius.developer_public_key', $withDeveloper ? 'pk_test' : '');
        config()->set('services.freemius.developer_secret_key', $withDeveloper ? 'sk_test' : '');
    }

    private function makePlan(array $attrs = []): SubscriptionPlan
    {
        return SubscriptionPlan::create(array_merge([
            'id' => (string) Str::uuid(),
            'name' => 'Starter',
            'slug' => 'starter',
            'monthly_price' => 49.00,
            'yearly_price' => 490.00,
            'currency' => 'USD',
            'trial_days' => 7,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
            'metadata' => ['freemius_monthly_plan_id' => '1010', 'freemius_yearly_plan_id' => '2020'],
        ], $attrs));
    }

    private function superAdmin(Tenant $tenant): User
    {
        $user = $this->makeUser(['is_super_admin' => true]);
        $this->attachToTenant($user, $tenant, 'owner');

        return $user;
    }

    private function pricingList(float $monthly, float $annual = 490.00)
    {
        return Http::response(['pricing' => [[
            'id' => '5005',
            'plan_id' => '1010',
            'currency' => 'usd',
            'monthly_price' => $monthly,
            'annual_price' => $annual,
        ]]], 200);
    }

    private function ping()
    {
        return Http::response(['timestamp' => now()->toRfc2822String()], 200);
    }

    // ── Signature (official developer-scope algorithm) ───────────────

    public function test_developer_signature_matches_official_algorithm(): void
    {
        // Independently computed (Python) from the official SDK formula:
        // base64url( hmac_sha256_hex( "METHOD\nContent-MD5\nContent-Type\nDate\nresourcePath" ) )
        $expected = 'NmU1NjM1NTNhNjc2YjI1NmVmM2MxOGRkZDcwMzE1YWQ4OGIzMGM3NWQ5ZDNjZDQ3YTBkODg1YjdmN2VjNjA1Mg';

        $this->assertSame($expected, FreemiusGateway::freemiusSignature(
            'PUT',
            'bc3b35e2daca5384bdb50c21e24e0107',
            'application/json',
            'Mon, 17 Nov 2026 12:00:00 +0000',
            '/v1/developers/123/products/777/plans/1010/pricing/5005.json',
            'pk_test',
            'sk_test',
        ));
    }

    public function test_update_pricing_signs_the_request_correctly(): void
    {
        $this->configureFreemius();

        Http::fake([
            'api.freemius.test/v1/ping.json' => $this->ping(),
            'api.freemius.test/v1/developers/*' => Http::response(['pricing' => ['id' => '5005', 'monthly_price' => 59]], 200),
        ]);

        $result = FreemiusGateway::updatePricing('1010', '5005', ['monthly_price' => 59, 'currency' => 'usd']);

        $this->assertTrue($result['ok']);
        $this->assertSame(200, $result['status']);

        $body = '{"monthly_price":59,"currency":"usd"}';

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/v1/developers/123/products/777/plans/1010/pricing/5005.json')
            && str_starts_with((string) $request->header('Authorization')[0], 'FS 123:pk_test:')
            && (string) $request->header('Content-MD5')[0] === md5($body)
            && $request->body() === $body);
    }

    public function test_update_pricing_never_calls_the_api_without_developer_credentials(): void
    {
        $this->configureFreemius(withDeveloper: false);
        Http::fake();

        $result = FreemiusGateway::updatePricing('1010', '5005', ['monthly_price' => 59, 'currency' => 'usd']);

        $this->assertFalse($result['ok']);
        $this->assertSame('developer_credentials_missing', $result['error']);
        Http::assertNothingSent();
    }

    // ── Admin gate ───────────────────────────────────────────────────

    public function test_only_super_admin_can_publish_a_price(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        $res = $this->postJson("/api/v1/admin/plans/{$plan->id}/publish-price", [
            'monthly_price' => 1,
        ], $this->authHeaders($teacher, $tenant));

        $this->assertTrue(in_array($res->status(), [402, 403], true), 'Expected the teacher to be denied, got '.$res->status());
        $this->assertSame(49.0, (float) $plan->fresh()->monthly_price);
    }

    // ── Honest failure: no developer credentials ─────────────────────

    public function test_publish_without_developer_credentials_is_honest_and_does_not_change_the_price(): void
    {
        $this->configureFreemius(withDeveloper: false);
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);

        $res = $this->postJson("/api/v1/admin/plans/{$plan->id}/publish-price", [
            'monthly_price' => 59,
        ], $this->authHeaders($admin, $tenant));

        $res->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('results.monthly.status', 'not_configured')
            ->assertJsonPath('results.monthly.action_required', 'add_developer_credentials');

        $this->assertSame(49.0, (float) $plan->fresh()->monthly_price);
        $this->assertNull($plan->fresh()->metadata['freemius_prices'] ?? null);
    }

    // ── Success: write + re-read + adopt ─────────────────────────────

    public function test_publish_success_adopts_the_verified_price_and_records_sync_and_audit(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);

        $updated = false;

        Http::fake([
            'api.freemius.test/v1/ping.json' => $this->ping(),
            'api.freemius.test/v1/developers/*' => function () use (&$updated) {
                $updated = true;

                return Http::response(['pricing' => ['id' => '5005', 'monthly_price' => 59]], 200);
            },
            'api.freemius.test/v1/products/777/plans/1010/pricing.json' => function () use (&$updated) {
                return $this->pricingList($updated ? 59.0 : 49.0);
            },
        ]);

        $res = $this->postJson("/api/v1/admin/plans/{$plan->id}/publish-price", [
            'monthly_price' => 59,
        ], $this->authHeaders($admin, $tenant));

        $res->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('results.monthly.status', 'synced')
            ->assertJsonPath('results.monthly.old_price', 49)
            ->assertJsonPath('results.monthly.new_price', 59)
            ->assertJsonPath('results.monthly.remote_price', 59);

        $fresh = $plan->fresh();
        $this->assertSame(59.0, (float) $fresh->monthly_price);
        $this->assertSame(59, (int) ($fresh->metadata['freemius_prices']['monthly']['amount'] ?? 0));
        $this->assertSame('5005', (string) ($fresh->metadata['freemius_prices']['monthly']['pricing_id'] ?? ''));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'subscription.plan_price_published',
            'entity_type' => 'plan',
            'entity_id' => $plan->id,
        ]);
    }

    // ── Mismatch: never adopt ────────────────────────────────────────

    public function test_publish_mismatch_keeps_the_old_price(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);

        // The write is accepted but Freemius still reports the OLD price.
        Http::fake([
            'api.freemius.test/v1/ping.json' => $this->ping(),
            'api.freemius.test/v1/developers/*' => Http::response(['pricing' => ['id' => '5005']], 200),
            'api.freemius.test/v1/products/777/plans/1010/pricing.json' => $this->pricingList(49.0),
        ]);

        $res = $this->postJson("/api/v1/admin/plans/{$plan->id}/publish-price", [
            'monthly_price' => 59,
        ], $this->authHeaders($admin, $tenant));

        $res->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('results.monthly.status', 'mismatch');

        $this->assertSame(49.0, (float) $plan->fresh()->monthly_price);
        $this->assertNull($plan->fresh()->metadata['freemius_prices'] ?? null);
    }

    public function test_publish_rejected_by_freemius_keeps_the_old_price(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);

        Http::fake([
            'api.freemius.test/v1/ping.json' => $this->ping(),
            'api.freemius.test/v1/developers/*' => Http::response(['error' => ['message' => 'nope']], 402),
            'api.freemius.test/v1/products/777/plans/1010/pricing.json' => $this->pricingList(49.0),
        ]);

        $res = $this->postJson("/api/v1/admin/plans/{$plan->id}/publish-price", [
            'monthly_price' => 59,
        ], $this->authHeaders($admin, $tenant));

        $res->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('results.monthly.status', 'unavailable')
            ->assertJsonPath('results.monthly.http_status', 402);

        $this->assertSame(49.0, (float) $plan->fresh()->monthly_price);
    }

    public function test_concurrent_publish_is_blocked_by_a_lock(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);

        Http::fake([
            'api.freemius.test/v1/*' => Http::response([], 200),
        ]);

        // Hold the plan's publish lock from "another request".
        $held = Cache::lock('freemius_price_sync:'.$plan->id, 30);
        $this->assertTrue($held->get());

        $res = $this->postJson("/api/v1/admin/plans/{$plan->id}/publish-price", [
            'monthly_price' => 59,
        ], $this->authHeaders($admin, $tenant));

        $res->assertStatus(409)->assertJsonPath('locked', true);
        $this->assertSame(49.0, (float) $plan->fresh()->monthly_price);

        $held->release();
    }

    // ── Checkout price guard ─────────────────────────────────────────

    public function test_checkout_is_blocked_when_displayed_price_differs_from_freemius(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan(); // displayed 49.00
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);

        Http::fake([
            'api.freemius.test/v1/products/777/plans/1010/pricing.json' => $this->pricingList(59.0),
        ]);

        $res = $this->postJson('/api/v1/subscriptions/freemius/checkout', [
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
        ], $this->authHeaders($admin, $tenant));

        $res->assertStatus(409)->assertJsonPath('reason', 'mismatch');
    }

    public function test_checkout_is_blocked_when_price_cannot_be_verified(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);

        // The pricing read fails → we cannot prove the price → fail closed.
        Http::fake([
            'api.freemius.test/v1/products/777/plans/1010/pricing.json' => Http::response([], 500),
        ]);

        $res = $this->postJson('/api/v1/subscriptions/freemius/checkout', [
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
        ], $this->authHeaders($admin, $tenant));

        $res->assertStatus(409)->assertJsonPath('reason', 'unavailable');
    }

    public function test_checkout_proceeds_when_displayed_price_matches_freemius(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan(); // displayed 49.00
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);

        Http::fake([
            'api.freemius.test/v1/products/777/plans/1010/pricing.json' => $this->pricingList(49.0),
        ]);

        $this->postJson('/api/v1/subscriptions/freemius/checkout', [
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
        ], $this->authHeaders($admin, $tenant))
            ->assertOk()
            ->assertJsonPath('mode', 'checkout');
    }
}
