<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Models\Transaction;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Lemon Squeezy (MoR) — config, checkout creation, webhook signature,
 * idempotency, initial purchase, renewals, subscription lifecycle, refunds,
 * tenant isolation and price/plan manipulation resistance. Lemon Squeezy API
 * calls are faked at the HTTP layer; internal side effects run against the
 * real (test) database.
 */
class LemonSqueezyTest extends TenantTestCase
{
    use RefreshDatabase;

    private const SIGNING_SECRET = 'lemon_signing_secret_test_0123456789';

    private const API_KEY = 'lemon_api_key_test_abcdef123456';

    private const ORDER_ID = '440001234';

    private const CHECKOUT_ID = '5e8b546c-c561-4a2c-a586-40c18bb2a195';

    private const SUB_ID = '123456';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        \Illuminate\Support\Facades\Cache::flush();
        \App\Models\PlatformSetting::forgetCache();

        // Secrets always come from the environment (config/services.php).
        config()->set('services.lemonsqueezy.enabled', true);
        config()->set('services.lemonsqueezy.api_key', self::API_KEY);
        config()->set('services.lemonsqueezy.signing_secret', self::SIGNING_SECRET);
        config()->set('services.lemonsqueezy.store_id', '123456');
        config()->set('services.lemonsqueezy.api_url', 'https://api.lemonsqueezy.com');
    }

    private function enableLemon(): void
    {
        PlatformSetting::set('lemon_enabled', true);
        PlatformSetting::set('lemon_store_id', '123456');
        PlatformSetting::set('currency_rate', 0.71);
    }

    private function makeLemonPlan(array $meta = []): SubscriptionPlan
    {
        return SubscriptionPlan::create(array_merge([
            'id' => (string) Str::uuid(),
            'name' => 'Pro', 'slug' => 'pro-ls-'.uniqid(), 'monthly_price' => 29.00, 'yearly_price' => 290.00,
            'currency' => 'JOD', 'trial_days' => 7, 'is_active' => true, 'is_public' => true, 'sort_order' => 0,
        ], ['metadata' => array_merge([
            'lemon_variant_id_monthly' => '111111',
            'lemon_variant_id_yearly' => '222222',
        ], $meta)]));
    }

    private function fakeCreateCheckout(array $overrides = []): void
    {
        Http::fake([
            '*/checkouts' => Http::response(array_merge([
                'jsonapi' => ['version' => '1.0'],
                'data' => [
                    'id' => self::CHECKOUT_ID,
                    'type' => 'checkouts',
                    'attributes' => [
                        'store_id' => 123456,
                        'variant_id' => 111111,
                        'checkout_data' => [],
                        'expires_at' => now()->addHours(24)->toISOString(),
                        'test_mode' => true,
                        'url' => 'https://store.lemonsqueezy.com/checkout/buy/'.self::CHECKOUT_ID,
                    ],
                ],
            ], $overrides), 201),
        ]);
    }

    private function postWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $sig = hash_hmac('sha256', $raw, self::SIGNING_SECRET);

        return $this->call('POST', '/api/v1/payments/lemonsqueezy-webhook', [], [], [], [
            'HTTP_X-Signature' => $sig,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $raw);
    }

    private function signedPayload(string $eventName, array $attributes, array $customData = [], ?string $webhookId = null): array
    {
        return [
            'meta' => [
                'event_name' => $eventName,
                'custom_data' => $customData,
                'test_mode' => true,
                'webhook_id' => $webhookId ?? ('wh_'.Str::random(24)),
            ],
            'data' => [
                'id' => self::ORDER_ID,
                'type' => 'orders',
                'attributes' => $attributes,
            ],
        ];
    }

    private function orderAttributes(array $overrides = []): array
    {
        return array_merge([
            'status' => 'paid',
            'order_number' => 42,
            'subtotal' => 2559,
            'total' => 2559,
            'currency' => 'USD',
            'customer_id' => 77,
            'subscription_id' => null,
            'variant_id' => 111111,
            'user_email' => 'owner@example.com',
        ], $overrides);
    }

    // ── Configuration ─────────────────────────────────────────────────────

    public function test_gateways_reports_lemonsqueezy_disabled(): void
    {
        config()->set('services.lemonsqueezy.enabled', false);
        config()->set('services.lemonsqueezy.api_key', '');
        config()->set('services.lemonsqueezy.signing_secret', '');
        PlatformSetting::forgetCache();

        $this->getJson('/api/v1/payment-gateways')->assertOk()->assertJson([
            'lemonsqueezy' => ['enabled' => false, 'configured' => false],
        ]);
    }

    public function test_gateways_reports_lemonsqueezy_configured(): void
    {
        $this->enableLemon();

        $this->getJson('/api/v1/payment-gateways')->assertOk()->assertJson([
            'lemonsqueezy' => ['enabled' => true, 'configured' => true, 'secrets_from_env' => true],
        ]);
    }

    // ── Checkout creation ────────────────────────────────────────────────

    public function test_initiate_rejects_when_lemonsqueezy_disabled(): void
    {
        config()->set('services.lemonsqueezy.enabled', false);
        PlatformSetting::forgetCache();

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makeLemonPlan();

        $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'lemonsqueezy',
        ], $this->authHeaders($user, $tenant))->assertStatus(422);
    }

    public function test_initiate_rejects_when_credentials_missing(): void
    {
        $this->enableLemon();
        config()->set('services.lemonsqueezy.api_key', '');
        config()->set('services.lemonsqueezy.signing_secret', '');

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makeLemonPlan();

        $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'lemonsqueezy',
        ], $this->authHeaders($user, $tenant))->assertStatus(422);
    }

    public function test_initiate_rejects_plan_without_variant_mapping(): void
    {
        $this->enableLemon();
        $this->fakeCreateCheckout();

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makeLemonPlan(['lemon_variant_id_monthly' => null]);

        $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'lemonsqueezy',
        ], $this->authHeaders($user, $tenant))->assertStatus(422);
    }

    public function test_initiate_creates_checkout_with_server_side_price_and_plan(): void
    {
        $this->enableLemon();
        $this->fakeCreateCheckout();

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makeLemonPlan();

        $response = $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
            'gateway' => 'lemonsqueezy',
            // Client attempts to manipulate the price — must be ignored.
            'amount' => 0.01,
            'price' => 0.01,
        ], $this->authHeaders($user, $tenant));

        $response->assertOk()
            ->assertJsonPath('redirect_url', 'https://store.lemonsqueezy.com/checkout/buy/'.self::CHECKOUT_ID)
            ->assertJsonPath('lemon_checkout_id', self::CHECKOUT_ID);

        // Server-computed price: 29 JOD × 0.71 = 20.59 USD + $5 service fee
        // = 25.59 → custom_price 2559 cents. Variant from the plan mapping.
        Http::assertSent(function ($request) {
            $attrs = $request['data']['attributes'] ?? [];

            return $attrs['custom_price'] === 2559
                && ($attrs['checkout_data']['variant_quantities'][0]['variant_id'] ?? null) === 111111
                && is_string($attrs['checkout_data']['custom']['payment_id']);
        });

        $payment = Payment::withoutTenant()
            ->where('gateway', 'lemonsqueezy')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($payment);
        $this->assertEquals('pending', $payment->status);
        $this->assertEquals('USD', $payment->currency);
        $this->assertEquals('2559', $payment->gateway_data['expected_subtotal_minor']);
    }

    public function test_initiate_surfaces_gateway_failure_without_stack_trace(): void
    {
        $this->enableLemon();
        Http::fake(['*/checkouts' => Http::response(['errors' => [['detail' => 'invalid variant']]], 422)]);

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makeLemonPlan();

        $response = $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'lemonsqueezy',
        ], $this->authHeaders($user, $tenant));

        $response->assertStatus(422);
        $this->assertStringNotContainsString('#', $response->getContent());
        $this->assertStringNotContainsString(self::API_KEY, $response->getContent());
    }

    public function test_initiate_surfaces_timeout_as_friendly_error(): void
    {
        $this->enableLemon();
        // Simulate a gateway outage (5xx — Http client converts to error).
        Http::fake(['*/checkouts' => Http::response(null, 503)]);

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makeLemonPlan();

        $response = $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'lemonsqueezy',
        ], $this->authHeaders($user, $tenant));

        $response->assertStatus(422);
        $this->assertStringContainsString('Lemon Squeezy', $response->getContent());
        $this->assertStringNotContainsString(self::API_KEY, $response->getContent());
    }

    // ── Webhook security ─────────────────────────────────────────────────

    public function test_webhook_rejects_invalid_signature_without_state_changes(): void
    {
        $this->enableLemon();

        $raw = json_encode($this->signedPayload('order_created', $this->orderAttributes()), JSON_UNESCAPED_SLASHES);
        $badSig = hash_hmac('sha256', $raw, 'wrong_secret');

        $response = $this->call('POST', '/api/v1/payments/lemonsqueezy-webhook', [], [], [], [
            'HTTP_X-Signature' => $badSig,
            'CONTENT_TYPE' => 'application/json',
        ], $raw);

        $response->assertStatus(401);
        $this->assertEquals(0, WebhookEvent::count());
        $this->assertEquals(0, Payment::withoutTenant()->count());
    }

    public function test_webhook_rejects_malformed_payload(): void
    {
        $this->enableLemon();
        $raw = json_encode(['meta' => ['event_name' => 'order_created']], JSON_UNESCAPED_SLASHES);
        $sig = hash_hmac('sha256', $raw, self::SIGNING_SECRET);

        $this->call('POST', '/api/v1/payments/lemonsqueezy-webhook', [], [], [], [
            'HTTP_X-Signature' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertStatus(422);
    }

    public function test_webhook_acknowledges_unknown_event_without_recording(): void
    {
        $this->enableLemon();
        $payload = $this->signedPayload('license_key_created', ['id' => 1, 'status' => 'created']);

        $this->postWebhook($payload)->assertOk()->assertJson(['message' => 'Ignored event.']);

        $this->assertEquals(0, WebhookEvent::count());
    }

    // ── Initial purchase (order_created) ────────────────────────────────

    private function initiatePaymentFor(array $customData, int $subtotal = 2559, string $currency = 'USD'): Payment
    {
        $this->enableLemon();
        $this->fakeCreateCheckout();

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makeLemonPlan();

        $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'lemonsqueezy',
        ], $this->authHeaders($user, $tenant))->assertOk();

        return Payment::withoutTenant()->where('gateway', 'lemonsqueezy')->latest('created_at')->first();
    }

    public function test_order_created_completes_payment_and_activates_subscription(): void
    {
        $payment = $this->initiatePaymentFor([]);
        $custom = ['payment_id' => $payment->id, 'tenant_id' => $payment->tenant_id];

        $payload = $this->signedPayload('order_created', $this->orderAttributes([
            'id' => self::ORDER_ID,
            'subtotal' => 2559,
            'subscription_id' => (int) self::SUB_ID,
            'customer_id' => 77,
        ]), $custom);

        $this->postWebhook($payload)->assertOk();

        $fresh = $payment->fresh();
        $this->assertEquals('completed', $fresh->status);
        $this->assertEquals(self::ORDER_ID, $fresh->transaction_id);

        $sub = $fresh->subscription->fresh();
        $this->assertEquals('active', $sub->status);
        $this->assertEquals('123456', $sub->metadata['lemon_subscription_id']);

        // Activation happened via the verified webhook — assert a redirect
        // alone never touches state (no other pathway exists; state count).
        $this->assertEquals(1, Payment::withoutTenant()->where('status', 'completed')->count());
        $this->assertEquals(1, WebhookEvent::where('processing_status', 'processed')->count());
    }

    public function test_duplicate_order_webhook_is_idempotent(): void
    {
        $payment = $this->initiatePaymentFor([]);
        $custom = ['payment_id' => $payment->id, 'tenant_id' => $payment->tenant_id];
        $payload = $this->signedPayload('order_created', $this->orderAttributes(['id' => self::ORDER_ID]), $custom, 'wh_duplicate_1');

        $this->postWebhook($payload)->assertOk();

        // Same webhook_id replayed.
        $this->postWebhook($payload)->assertOk()->assertJson(['message' => 'Duplicate event.']);

        // Different webhook_id, same order id → unique (gateway, transaction)
        // index makes the second completion a no-op.
        $this->postWebhook($this->signedPayload('order_created', $this->orderAttributes(['id' => self::ORDER_ID]), $custom, 'wh_duplicate_2'))->assertOk();

        $this->assertEquals(2, WebhookEvent::count());
        $this->assertEquals(1, Payment::withoutTenant()->where('status', 'completed')->count());
        $this->assertEquals(1, Payment::withoutTenant()->where('transaction_id', self::ORDER_ID)->count());
    }

    public function test_amount_mismatch_fails_the_payment(): void
    {
        $payment = $this->initiatePaymentFor([]);
        $custom = ['payment_id' => $payment->id, 'tenant_id' => $payment->tenant_id];

        // Attacker forges an order at a different amount.
        $payload = $this->signedPayload('order_created', $this->orderAttributes(['id' => self::ORDER_ID, 'subtotal' => 1]), $custom);

        $this->postWebhook($payload)->assertOk();

        $this->assertEquals('failed', $payment->fresh()->status);
        $this->assertEquals('pending', $payment->subscription->fresh()->status);
    }

    public function test_currency_mismatch_fails_the_payment(): void
    {
        $payment = $this->initiatePaymentFor([]);
        $custom = ['payment_id' => $payment->id, 'tenant_id' => $payment->tenant_id];

        $payload = $this->signedPayload('order_created', $this->orderAttributes(['id' => self::ORDER_ID, 'currency' => 'EUR']), $custom);

        $this->postWebhook($payload)->assertOk();

        $this->assertEquals('failed', $payment->fresh()->status);
    }

    // ── Tenant isolation ────────────────────────────────────────────────

    public function test_order_created_for_another_tenant_is_ignored(): void
    {
        $payment = $this->initiatePaymentFor([]);

        // Tenant id in custom_data does not match the payment's tenant.
        $custom = ['payment_id' => $payment->id, 'tenant_id' => '00000000-0000-7000-8000-000000000000'];

        $payload = $this->signedPayload('order_created', $this->orderAttributes(['id' => self::ORDER_ID]), $custom);

        $this->postWebhook($payload)->assertOk();

        $this->assertEquals('pending', $payment->fresh()->status);
    }

    public function test_renewal_with_unknown_subscription_id_is_acknowledged_but_ignored(): void
    {
        $this->enableLemon();

        $payload = $this->signedPayload('order_created', $this->orderAttributes([
            'id' => self::ORDER_ID,
            'subscription_id' => 999999999,
        ]), []);

        $this->postWebhook($payload)->assertOk();

        $this->assertEquals(0, Payment::withoutTenant()->count());
        $this->assertEquals(1, WebhookEvent::where('processing_status', 'processed')->count());
    }

    public function test_subscription_event_for_unknown_subscription_is_ignored(): void
    {
        $this->enableLemon();

        $payload = $this->signedPayload('subscription_cancelled', ['status' => 'cancelled', 'ends_at' => now()->addDay()->toISOString()], [], 'wh_sub_unknown');
        $payload['data']['id'] = '555555';

        $this->postWebhook($payload)->assertOk();

        $this->assertEquals(0, TenantSubscription::withoutTenant()->count());
        $this->assertEquals(1, WebhookEvent::where('processing_status', 'processed')->count());
    }

    // ── Renewal (recurring order without custom data) ───────────────────

    private function makeBoundSubscription(): TenantSubscription
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $plan = $this->makeLemonPlan();

        return TenantSubscription::withoutTenant()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subDays(30),
            'ends_at' => now()->subHour(),
            'auto_renew' => true,
            'metadata' => ['lemon_subscription_id' => self::SUB_ID, 'lemon_customer_id' => '77'],
        ]);
    }

    public function test_renewal_order_creates_payment_and_extends_subscription(): void
    {
        $sub = $this->makeBoundSubscription();
        $oldEnds = $sub->ends_at;

        $payload = $this->signedPayload('order_created', $this->orderAttributes([
            'id' => self::ORDER_ID,
            'subscription_id' => (int) self::SUB_ID,
        ]), [], 'wh_renewal_1');

        $this->postWebhook($payload)->assertOk();

        $payment = Payment::withoutTenant()
            ->where('gateway', 'lemonsqueezy')
            ->where('transaction_id', self::ORDER_ID)
            ->first();

        $this->assertNotNull($payment);
        $this->assertEquals('completed', $payment->status);
        $this->assertEquals($sub->id, $payment->tenant_subscription_id);

        $fresh = $sub->fresh();
        $this->assertEquals('active', $fresh->status);
        $this->assertTrue($fresh->ends_at->gt($oldEnds));
    }

    public function test_renewal_duplicate_order_is_a_noop(): void
    {
        $sub = $this->makeBoundSubscription();

        $payload = $this->signedPayload('order_created', $this->orderAttributes([
            'id' => self::ORDER_ID,
            'subscription_id' => (int) self::SUB_ID,
        ]), [], 'wh_renewal_1');

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk()->assertJson(['message' => 'Duplicate event.']);

        $this->assertEquals(1, Payment::withoutTenant()->where('gateway', 'lemonsqueezy')->count());
    }

    // ── Subscription lifecycle ──────────────────────────────────────────

    private function lifecycleSubscription(): array
    {
        $this->enableLemon();
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $plan = $this->makeLemonPlan();

        $sub = TenantSubscription::withoutTenant()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'pending',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'auto_renew' => true,
            // Bound by a previous order_created webhook (realistic state).
            'metadata' => ['lemon_subscription_id' => self::SUB_ID, 'lemon_customer_id' => '77'],
        ]);

        $payment = Payment::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'tenant_subscription_id' => $sub->id,
            'transaction_id' => 'lemon-'.Str::random(20),
            'gateway' => 'lemonsqueezy',
            'amount' => 25.59,
            'currency' => 'USD',
            'status' => 'pending',
            'payment_method' => 'lemonsqueezy',
            'gateway_data' => ['expected_subtotal_minor' => '2559'],
        ]);

        return [$sub, $payment];
    }

    private function subscriptionPayload(string $eventName, array $attrs, array $customData): array
    {
        $payload = [
            'meta' => [
                'event_name' => $eventName,
                'custom_data' => $customData,
                'test_mode' => true,
                'webhook_id' => 'wh_'.Str::random(24),
            ],
            'data' => [
                'id' => self::SUB_ID,
                'type' => 'subscriptions',
                'attributes' => $attrs,
            ],
        ];

        return $payload;
    }

    public function test_subscription_created_links_and_sets_trial(): void
    {
        [$sub, $payment] = $this->lifecycleSubscription();

        $payload = $this->subscriptionPayload('subscription_created', [
            'status' => 'on_trial',
            'trial_ends_at' => now()->addDays(7)->toISOString(),
            'renews_at' => now()->addDays(7)->toISOString(),
            'customer_id' => 77,
            'variant_id' => 111111,
        ], ['payment_id' => $payment->id, 'tenant_id' => $payment->tenant_id]);

        $this->postWebhook($payload)->assertOk();

        $fresh = $sub->fresh();
        $this->assertEquals('trialing', $fresh->status);
        $this->assertEquals(self::SUB_ID, $fresh->metadata['lemon_subscription_id']);
        $this->assertTrue($fresh->auto_renew);
    }

    public function test_subscription_cancelled_stops_renewal_keeps_service_until_end(): void
    {
        [$sub] = $this->lifecycleSubscription();
        $sub->update(['status' => 'active']);

        $payload = $this->subscriptionPayload('subscription_cancelled', [
            'status' => 'cancelled',
            'ends_at' => now()->addDays(10)->toISOString(),
            'customer_id' => 77,
        ], []);

        $this->postWebhook($payload)->assertOk();

        $fresh = $sub->fresh();
        $this->assertFalse($fresh->auto_renew);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertEquals('active', $fresh->status); // service until ends_at
        $this->assertTrue($fresh->ends_at->isToday() || $fresh->ends_at->gt(now()));
    }

    public function test_subscription_expired_sets_internal_expired(): void
    {
        [$sub] = $this->lifecycleSubscription();
        $sub->update(['status' => 'active']);

        $payload = $this->subscriptionPayload('subscription_expired', ['status' => 'expired', 'customer_id' => 77], []);

        $this->postWebhook($payload)->assertOk();

        $fresh = $sub->fresh();
        $this->assertEquals('expired', $fresh->status);
        $this->assertFalse($fresh->auto_renew);
    }

    public function test_subscription_resumed_restores_renewal(): void
    {
        [$sub] = $this->lifecycleSubscription();
        $sub->update(['status' => 'active', 'auto_renew' => false]);

        $payload = $this->subscriptionPayload('subscription_resumed', [
            'status' => 'active',
            'renews_at' => now()->addMonth()->toISOString(),
            'customer_id' => 77,
        ], []);

        $this->postWebhook($payload)->assertOk();

        $fresh = $sub->fresh();
        $this->assertTrue($fresh->auto_renew);
        $this->assertEquals('active', $fresh->status);
    }

    public function test_subscription_updated_past_due_maps_to_internal_past_due(): void
    {
        [$sub] = $this->lifecycleSubscription();
        $sub->update(['status' => 'active', 'metadata' => ['lemon_subscription_id' => self::SUB_ID]]);

        $payload = $this->subscriptionPayload('subscription_updated', ['status' => 'past_due', 'customer_id' => 77], []);

        $this->postWebhook($payload)->assertOk();

        $this->assertEquals('past_due', $sub->fresh()->status);
    }

    public function test_subscription_paused_keeps_service_and_stops_billing(): void
    {
        [$sub] = $this->lifecycleSubscription();
        $sub->update(['status' => 'active', 'auto_renew' => true]);

        $payload = $this->subscriptionPayload('subscription_paused', ['status' => 'paused', 'customer_id' => 77], []);

        $this->postWebhook($payload)->assertOk();

        $fresh = $sub->fresh();
        $this->assertFalse($fresh->auto_renew);
        $this->assertEquals('paused', $fresh->metadata['lemon_status']);
    }

    // ── Refunds ─────────────────────────────────────────────────────────

    public function test_order_refunded_marks_payment_refunded_with_side_effects(): void
    {
        $sub = $this->makeBoundSubscription();

        // Completed payment for the order being refunded.
        $payment = Payment::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $sub->tenant_id,
            'tenant_subscription_id' => $sub->id,
            'transaction_id' => self::ORDER_ID,
            'gateway' => 'lemonsqueezy',
            'amount' => 25.59,
            'currency' => 'USD',
            'status' => 'completed',
            'payment_method' => 'lemonsqueezy',
            'gateway_data' => ['lemon_subscription_id' => self::SUB_ID],
        ]);

        Invoice::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $sub->tenant_id,
            'payment_id' => $payment->id,
            'tenant_subscription_id' => $sub->id,
            'invoice_number' => 'INV-'.Str::random(8),
            'amount' => 25.59,
            'total_amount' => 25.59,
            'currency' => 'USD',
            'status' => 'paid',
            'due_date' => now(),
            'paid_at' => now(),
        ]);

        $payload = $this->signedPayload('order_refunded', [
            'id' => self::ORDER_ID,
            'status' => 'refunded',
            'refund_reason' => 'customer request',
        ], [], 'wh_refund_1');

        $this->postWebhook($payload)->assertOk();

        $this->assertEquals('refunded', $payment->fresh()->status);
        $this->assertEquals('cancelled', $sub->fresh()->status);

        // Ledger entry created once.
        $this->assertEquals(1, Transaction::where('reference_type', 'payment')
            ->where('reference_id', $payment->id)
            ->where('type', 'refund')
            ->count());
    }

    public function test_order_refunded_never_applies_twice(): void
    {
        $sub = $this->makeBoundSubscription();

        Payment::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $sub->tenant_id,
            'tenant_subscription_id' => $sub->id,
            'transaction_id' => self::ORDER_ID,
            'gateway' => 'lemonsqueezy',
            'amount' => 25.59,
            'currency' => 'USD',
            'status' => 'completed',
            'payment_method' => 'lemonsqueezy',
        ]);

        $payload = $this->signedPayload('order_refunded', ['id' => self::ORDER_ID, 'status' => 'refunded'], [], 'wh_refund_1');

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk()->assertJson(['message' => 'Duplicate event.']);

        $this->assertEquals(1, Transaction::count());
    }

    // ── Malicious / manipulation resistance ─────────────────────────────

    public function test_plan_manipulation_attempt_is_rejected(): void
    {
        $this->enableLemon();
        $this->fakeCreateCheckout();

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');

        // Non-existent plan id.
        $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => 'does-not-exist', 'billing_cycle' => 'monthly', 'gateway' => 'lemonsqueezy',
        ], $this->authHeaders($user, $tenant))->assertStatus(422);

        // Inactive plan.
        $plan = $this->makeLemonPlan();
        $plan->update(['is_active' => false]);
        $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'lemonsqueezy',
        ], $this->authHeaders($user, $tenant))->assertStatus(422);
    }

    public function test_malicious_webhook_payload_is_rejected(): void
    {
        $this->enableLemon();

        // Huge fake payload with a valid signature but impossible shape.
        $raw = json_encode([
            'meta' => [
                'event_name' => 'order_created',
                'webhook_id' => 'wh_malicious_1',
                'custom_data' => ['payment_id' => str_repeat('a', 5000)],
            ],
            'data' => ['attributes' => ['status' => 'paid']],
        ]);
        $sig = hash_hmac('sha256', $raw, self::SIGNING_SECRET);

        $this->call('POST', '/api/v1/payments/lemonsqueezy-webhook', [], [], [], [
            'HTTP_X-Signature' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertOk(); // acked; no matching payment → no state change

        $this->assertEquals(0, Payment::withoutTenant()->count());
    }

    public function test_secrets_are_never_logged(): void
    {
        $this->enableLemon();

        // Capture everything the logger emits during webhook processing.
        Log::spy();

        // Force a deterministic processing error: the bound subscription's
        // ends_at holds an unparseable value, so hydrating the model during
        // renewal processing throws after the event was claimed.
        $sub = $this->makeBoundSubscription();
        \Illuminate\Support\Facades\DB::table('tenant_subscriptions')
            ->where('id', $sub->id)
            ->update(['ends_at' => 'garbage-date']);

        $payload = $this->signedPayload('order_created', $this->orderAttributes([
            'id' => self::ORDER_ID,
            'subscription_id' => (int) self::SUB_ID,
        ]), [], 'wh_error_1');

        $this->postWebhook($payload)->assertStatus(500);

        // No log entry may ever contain the API key, the signing secret or
        // any card data.
        $log = Log::getFacadeRoot();
        $containsSecret = function ($message, $context) {
            $blob = json_encode([$message, $context]);

            return str_contains($blob, self::API_KEY)
                || str_contains($blob, self::SIGNING_SECRET)
                || str_contains($blob, '4242');
        };
        $log->shouldNotHaveReceived('warning', $containsSecret);
        $log->shouldNotHaveReceived('error', $containsSecret);

        // Event left un-processed so Lemon Squeezy can retry.
        $event = WebhookEvent::where('event_id', 'wh_error_1')->first();
        $this->assertEquals('failed', $event->processing_status);
        $this->assertStringNotContainsString(self::SIGNING_SECRET, (string) $event->failure_reason);
    }

    public function test_admin_settings_reject_unauthorized_user(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');

        $this->putJson('/api/v1/platform-settings', [
            'lemon_enabled' => true,
            'lemon_store_id' => '123',
        ], $this->authHeaders($user, $tenant))->assertForbidden();
    }

    public function test_admin_settings_update_never_accepts_secrets(): void
    {
        $admin = $this->makeUser();
        $this->attachToTenant($admin, $this->makeTenant(), 'owner');
        $admin->forceFill(['is_super_admin' => true])->save();

        $this->putJson('/api/v1/platform-settings', [
            'lemon_enabled' => true,
            'lemon_store_id' => '999',
            'lemon_api_key' => 'should-not-persist',
            'lemon_signing_secret' => 'should-not-persist',
        ], $this->authHeaders($admin, $admin->tenants()->first()))->assertOk();

        // Secrets have no storage path — they can never be written via the API.
        $this->assertNull(PlatformSetting::get('lemon_api_key'));
        $this->assertNull(PlatformSetting::get('lemon_signing_secret'));
        $this->assertTrue(PlatformSetting::bool('lemon_enabled'));
        $this->assertEquals('999', PlatformSetting::get('lemon_store_id'));
    }
}