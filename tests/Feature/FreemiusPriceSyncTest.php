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

    /** A raw pricing-list response (may contain several pricings). */
    private function pricingRows(array $rows)
    {
        return Http::response(['pricing' => $rows], 200);
    }

    private function usdRow(string $id, float $monthly, ?float $annual = null): array
    {
        return [
            'id' => $id,
            'plan_id' => '1010',
            'currency' => 'usd',
            'monthly_price' => $monthly,
            'annual_price' => $annual,
        ];
    }

    // ── Signature (official developer-scope algorithm) ───────────────

    public function test_developer_signature_matches_official_algorithm(): void
    {
        // Independently computed (Python) from the official SDK formula:
        // base64url( hmac_sha256_hex( "METHOD\nContent-MD5\nContent-Type\nDate\nresourcePath" ) )
        $expected = 'ZGMzZTU4MWY5ODBmMGE0NDRiZDM1MjlkMGFhN2U1YmJkNjg3OWE3ZDZkMjE2MDY4OWY3YzAyNDA2OTE2Yjc4MQ';

        $this->assertSame($expected, FreemiusGateway::freemiusSignature(
            'PUT',
            'bc3b35e2daca5384bdb50c21e24e0107',
            'application/json',
            'Mon, 17 Nov 2026 12:00:00 +0000',
            '/v1/developers/123/plugins/777/plans/1010/pricing/5005.json',
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
            && str_contains($request->url(), '/v1/developers/123/plugins/777/plans/1010/pricing/5005.json')
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
        // The request is retained as PENDING (admin-visible); the displayed
        // price is never changed without a confirmed match.
        $this->assertSame(59.0, (float) ($plan->fresh()->metadata['freemius_prices']['monthly']['pending']['amount'] ?? 0));
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
        $this->assertSame(59.0, (float) ($plan->fresh()->metadata['freemius_prices']['monthly']['pending']['amount'] ?? 0));
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

    // ── P0: currency, cycle, exact Pricing ID, ambiguity ─────────────

    public function test_currency_mismatch_is_never_synced_and_blocks_checkout(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan(); // displayed 49.00 USD
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);

        // Same amount but a NON-USD currency must never count as synced.
        Http::fake([
            'api.freemius.test/v1/products/777/plans/1010/pricing.json' => $this->pricingRows([
                ['id' => '5005', 'plan_id' => '1010', 'currency' => 'eur', 'monthly_price' => 49.0, 'annual_price' => 490.0],
            ]),
        ]);

        $this->getJson("/api/v1/admin/plans/{$plan->id}/freemius-sync", $this->authHeaders($admin, $tenant))
            ->assertOk()
            ->assertJsonPath('sync.monthly.status', 'mismatch')
            ->assertJsonPath('sync.monthly.currency', 'EUR');

        $this->postJson('/api/v1/subscriptions/freemius/checkout', [
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
        ], $this->authHeaders($admin, $tenant))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'mismatch');
    }

    public function test_ambiguous_multiple_pricings_block_checkout_and_publish(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan(); // displayed 49.00
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);

        // Two pricings, no explicit Pricing ID → ambiguous, never a guess.
        Http::fake([
            'api.freemius.test/v1/products/777/plans/1010/pricing.json' => $this->pricingRows([
                $this->usdRow('5005', 49.0, 490.0),
                $this->usdRow('5006', 99.0, 990.0),
            ]),
        ]);

        $this->getJson("/api/v1/admin/plans/{$plan->id}/freemius-sync", $this->authHeaders($admin, $tenant))
            ->assertOk()
            ->assertJsonPath('sync.monthly.status', 'ambiguous');

        $this->postJson('/api/v1/subscriptions/freemius/checkout', [
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
        ], $this->authHeaders($admin, $tenant))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'ambiguous');

        $res = $this->postJson("/api/v1/admin/plans/{$plan->id}/publish-price", [
            'monthly_price' => 59,
        ], $this->authHeaders($admin, $tenant));

        $res->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('results.monthly.status', 'ambiguous');

        $this->assertSame(49.0, (float) $plan->fresh()->monthly_price);
    }

    public function test_explicit_pricing_id_is_never_substituted_when_unreadable(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan(['metadata' => [
            'freemius_monthly_plan_id' => '1010',
            'freemius_yearly_plan_id' => '2020',
            'freemius_monthly_pricing_id' => '9999',
        ]]);
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);

        // The linked pricing is gone, but the plan still has another (valid) one.
        // We must NOT silently fall back to it.
        Http::fake([
            'api.freemius.test/v1/products/777/plans/1010/pricing/9999.json' => Http::response(['error' => ['message' => 'not found']], 404),
            'api.freemius.test/v1/products/777/plans/1010/pricing.json' => $this->pricingRows([$this->usdRow('5005', 49.0, 490.0)]),
        ]);

        $this->postJson('/api/v1/subscriptions/freemius/checkout', [
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
        ], $this->authHeaders($admin, $tenant))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'unavailable');

        $res = $this->postJson("/api/v1/admin/plans/{$plan->id}/publish-price", [
            'monthly_price' => 59,
        ], $this->authHeaders($admin, $tenant));

        $res->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('results.monthly.status', 'unavailable');

        $this->assertSame(49.0, (float) $plan->fresh()->monthly_price);

        // The valid sibling pricing must never have been written to.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'pricing/5005'));
    }

    public function test_gateway_returning_a_different_pricing_id_is_rejected(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan(['metadata' => [
            'freemius_monthly_plan_id' => '1010',
            'freemius_yearly_plan_id' => '2020',
            'freemius_monthly_pricing_id' => '5005',
        ]]);
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);

        // Asked for 5005 but the API answers with a different pricing id.
        Http::fake([
            'api.freemius.test/v1/products/777/plans/1010/pricing/5005.json' => Http::response([
                'pricing' => ['id' => '9999', 'currency' => 'usd', 'monthly_price' => 49.0],
            ], 200),
        ]);

        $this->postJson('/api/v1/subscriptions/freemius/checkout', [
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
        ], $this->authHeaders($admin, $tenant))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'unavailable');
    }

    public function test_cycle_without_a_price_for_that_cycle_blocks_checkout(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan(); // displayed yearly 490.00
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);

        // Monthly price exists but there is NO annual price → yearly blocked.
        Http::fake([
            'api.freemius.test/v1/products/777/plans/2020/pricing.json' => $this->pricingRows([
                ['id' => '5005', 'plan_id' => '2020', 'currency' => 'usd', 'monthly_price' => 49.0],
            ]),
        ]);

        $this->postJson('/api/v1/subscriptions/freemius/checkout', [
            'plan_id' => $plan->id,
            'billing_cycle' => 'yearly',
        ], $this->authHeaders($admin, $tenant))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'unavailable');
    }

    public function test_hosted_checkout_url_carries_verified_pricing_id_and_freemius_cycle(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan(); // monthly 49.00 / yearly 490.00
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);

        Http::fake([
            'api.freemius.test/v1/products/777/plans/1010/pricing.json' => $this->pricingList(49.0, 490.0),
            'api.freemius.test/v1/products/777/plans/2020/pricing.json' => $this->pricingList(49.0, 490.0),
        ]);

        $monthly = $this->postJson('/api/v1/subscriptions/freemius/checkout', [
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
        ], $this->authHeaders($admin, $tenant))->assertOk()->json('checkout_url');

        $this->assertStringContainsString('pricing_id=5005', $monthly);
        $this->assertStringContainsString('billing_cycle=monthly', $monthly);
        $this->assertStringContainsString('currency=usd', $monthly);

        $yearly = $this->postJson('/api/v1/subscriptions/freemius/checkout', [
            'plan_id' => $plan->id,
            'billing_cycle' => 'yearly',
        ], $this->authHeaders($admin, $tenant))->assertOk()->json('checkout_url');

        $this->assertStringContainsString('pricing_id=5005', $yearly);
        $this->assertStringContainsString('billing_cycle=annual', $yearly);
        $this->assertStringNotContainsString('billing_cycle=yearly', $yearly);
    }

    public function test_upgrade_link_uses_the_official_annual_cycle_and_no_currency(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan(); // yearly 490.00
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);

        \App\Models\FsEntitlement::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'fs_license_id' => 'LIC-UP',
            'plan_id' => $plan->id,
            'type' => 'subscription',
            'status' => 'active',
            'expiration' => now()->addDays(20),
            'is_canceled' => false,
        ]);

        Http::fake([
            'api.freemius.test/v1/products/777/plans/2020/pricing.json' => $this->pricingList(49.0, 490.0),
            'api.freemius.test/v1/products/777/licenses/LIC-UP/checkout/link.json' => Http::response([
                'url' => 'https://checkout.freemius.test/upgrade/xyz',
                'settings' => [],
                'expires' => now()->addHour()->toIso8601String(),
            ], 200),
        ]);

        $this->postJson('/api/v1/subscriptions/freemius/checkout', [
            'plan_id' => $plan->id,
            'billing_cycle' => 'yearly',
        ], $this->authHeaders($admin, $tenant))
            ->assertOk()
            ->assertJsonPath('mode', 'upgrade');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/products/777/licenses/LIC-UP/checkout/link.json')) {
                return false;
            }

            $data = $request->data();

            return ($data['billing_cycle'] ?? null) === 'annual'
                && ($data['plan_id'] ?? null) === '2020'
                && ! array_key_exists('currency', $data);
        });
    }

    // ── Pending request: never displayed, never adopted without proof ─

    public function test_a_failed_publish_keeps_the_request_as_pending_without_changing_the_displayed_price(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);

        // Freemius rejects the write → nothing may be applied.
        Http::fake([
            'api.freemius.test/v1/ping.json' => $this->ping(),
            'api.freemius.test/v1/developers/*' => Http::response(['error' => ['message' => 'nope']], 402),
            'api.freemius.test/v1/products/777/plans/1010/pricing.json' => $this->pricingList(49.0),
        ]);

        $this->postJson("/api/v1/admin/plans/{$plan->id}/publish-price", [
            'monthly_price' => 59,
        ], $this->authHeaders($admin, $tenant))
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('results.monthly.status', 'unavailable');

        $fresh = $plan->fresh();

        // Displayed price untouched; the request is retained as PENDING.
        $this->assertSame(49.0, (float) $fresh->monthly_price);
        $this->assertSame(59.0, (float) ($fresh->metadata['freemius_prices']['monthly']['pending']['amount'] ?? 0));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'subscription.plan_price_pending',
            'entity_type' => 'plan',
            'entity_id' => $plan->id,
        ]);

        // The pending request is exposed to the admin — but as `pending`, while
        // the charge that buyers would actually pay (49) is what stays live.
        $this->getJson("/api/v1/admin/plans/{$plan->id}/freemius-sync", $this->authHeaders($admin, $tenant))
            ->assertOk()
            ->assertJsonPath('sync.monthly.status', 'synced')
            ->assertJsonPath('sync.monthly.local_price', 49)
            ->assertJsonPath('sync.monthly.remote_price', 49)
            ->assertJsonPath('sync.monthly.pending.amount', 59);
    }

    public function test_apply_pending_price_retries_and_adopts_only_on_a_confirmed_match(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);

        // Attempt 1 is rejected (→ PENDING); attempt 2 succeeds (→ adopt).
        $attempt = 0;

        Http::fake([
            'api.freemius.test/v1/ping.json' => $this->ping(),
            'api.freemius.test/v1/developers/*' => function () use (&$attempt) {
                $attempt++;

                return $attempt === 1
                    ? Http::response(['error' => ['message' => 'nope']], 402)
                    : Http::response(['pricing' => ['id' => '5005', 'monthly_price' => 59]], 200);
            },
            'api.freemius.test/v1/products/777/plans/1010/pricing.json' => function () use (&$attempt) {
                return $this->pricingList($attempt >= 2 ? 59.0 : 49.0);
            },
        ]);

        // 1) First attempt is rejected → the request lands as PENDING.
        $this->postJson("/api/v1/admin/plans/{$plan->id}/publish-price", [
            'monthly_price' => 59,
        ], $this->authHeaders($admin, $tenant))->assertOk();

        $this->assertSame(49.0, (float) $plan->fresh()->monthly_price);
        $this->assertSame(59.0, (float) ($plan->fresh()->metadata['freemius_prices']['monthly']['pending']['amount'] ?? 0));

        // 2) Freemius recovers → applying the pending request adopts the price.
        $this->postJson("/api/v1/admin/plans/{$plan->id}/apply-pending-price", [], $this->authHeaders($admin, $tenant))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('results.monthly.status', 'synced');

        $fresh = $plan->fresh();

        $this->assertSame(59.0, (float) $fresh->monthly_price);
        // Confirmed → the pending request is cleared.
        $this->assertArrayNotHasKey('pending', $fresh->metadata['freemius_prices']['monthly'] ?? []);
    }

    public function test_apply_pending_price_does_nothing_when_there_is_no_pending_request(): void
    {
        $this->configureFreemius();
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);

        Http::fake();

        $this->postJson("/api/v1/admin/plans/{$plan->id}/apply-pending-price", [], $this->authHeaders($admin, $tenant))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('results', []);

        $this->assertSame(49.0, (float) $plan->fresh()->monthly_price);
        Http::assertNothingSent();
    }

    // ── Approved-price publish command (plans:publish-approved-prices) ──

    private function approvedPlan(string $slug, float $monthly, float $yearly): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'id' => (string) Str::uuid(),
            'name' => ucfirst($slug),
            'slug' => $slug,
            'monthly_price' => $monthly,
            'yearly_price' => $yearly,
            'currency' => 'USD',
            'trial_days' => 7,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
            'metadata' => ['freemius_monthly_plan_id' => '1010', 'freemius_yearly_plan_id' => '2020'],
        ]);
    }

    public function test_approved_prices_command_dry_run_never_calls_freemius_and_changes_nothing(): void
    {
        $this->configureFreemius();
        $this->approvedPlan('starter', 29, 299);
        $pro = $this->approvedPlan('professional', 49, 599); // yearly already approved
        $this->approvedPlan('enterprise', 79, 899);

        Http::fake();

        $this->artisan('plans:publish-approved-prices', ['--dry-run' => true])->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(49.0, (float) $pro->fresh()->monthly_price);
    }

    public function test_approved_prices_command_adopts_only_a_verified_price(): void
    {
        $this->configureFreemius();
        $this->approvedPlan('starter', 29, 299);
        $pro = $this->approvedPlan('professional', 49, 599);

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

        $this->artisan('plans:publish-approved-prices')->assertExitCode(0);

        $fresh = $pro->fresh();
        $this->assertSame(59.0, (float) $fresh->monthly_price);
        $this->assertSame('5005', (string) ($fresh->metadata['freemius_prices']['monthly']['pricing_id'] ?? ''));
    }

    public function test_approved_prices_command_does_not_adopt_when_freemius_does_not_confirm(): void
    {
        $this->configureFreemius();
        $this->approvedPlan('starter', 29, 299);
        $pro = $this->approvedPlan('professional', 49, 599);

        Http::fake([
            'api.freemius.test/v1/ping.json' => $this->ping(),
            'api.freemius.test/v1/developers/*' => Http::response(['pricing' => ['id' => '5005', 'monthly_price' => 59]], 200),
            // يقرأ السعر القديم دائماً → لا تطابق → لا اعتماد محلي.
            'api.freemius.test/v1/products/777/plans/1010/pricing.json' => $this->pricingList(49.0),
        ]);

        $this->artisan('plans:publish-approved-prices')->assertExitCode(1);

        $this->assertSame(49.0, (float) $pro->fresh()->monthly_price);
    }
}
