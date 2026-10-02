<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Paddle Billing gateway — config, initiate, webhook signature, idempotency,
 * completion, renewals, cancellation and refunds. Paddle API calls are faked
 * at the HTTP layer; the internal Payment/Subscription/Invoice/Ledger side
 * effects run against the real (test) database.
 */
class PaddleCheckoutTest extends TenantTestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'pdl_ntfset_test_secret_0123456789abcdef';

    private const TXN_ID = 'txn_01gqtesttransactionpaddle0001';

    private const SUB_ID = 'sub_01gqtestsubscriptionpaddle0001';

    /**
     * Force the in-memory cache for the duration of these tests. The Docker
     * container injects a real CACHE_STORE env var that can otherwise leak
     * gateway settings written by a previous test process into redis and
     * poison default-value assertions.
     */
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        \Illuminate\Support\Facades\Cache::flush();
        \App\Models\PlatformSetting::forgetCache();
    }

    private function enablePaddle(string $env = 'sandbox'): void
    {
        PlatformSetting::set('paddle_enabled', true);
        PlatformSetting::set('paddle_environment', $env);
        PlatformSetting::set('paddle_client_token', 'test_'.Str::random(27));
        PlatformSetting::set('paddle_api_key', 'pdl_sdbx_apikey_'.Str::random(26).'_'.Str::random(22).'_abc');
        PlatformSetting::set('paddle_webhook_secret', self::WEBHOOK_SECRET);
        PlatformSetting::set('currency_rate', 0.71);
    }

    private function makePaddlePlan(array $meta = []): SubscriptionPlan
    {
        return SubscriptionPlan::create(array_merge([
            'id' => (string) Str::uuid(),
            'name' => 'Pro', 'slug' => 'pro-pd-'.uniqid(), 'monthly_price' => 29.00, 'yearly_price' => 290.00,
            'currency' => 'JOD', 'trial_days' => 7, 'is_active' => true, 'is_public' => true, 'sort_order' => 0,
        ], ['metadata' => array_merge([
            'paddle_price_id_monthly' => 'pri_01gqtestmonthlypaddle0001',
            'paddle_price_id_yearly' => 'pri_01gqtestyearlypaddle0001',
        ], $meta)]));
    }

    private function fakeCreateTransaction(?string $txnId = null, array $overrides = []): void
    {
        Http::fake([
            '*/transactions' => Http::response(array_merge([
                'data' => [
                    'id' => $txnId ?? self::TXN_ID,
                    'status' => 'draft',
                    'custom_data' => [],
                    'checkout' => ['url' => 'https://checkout.paddle.com/123?_ptxn='.($txnId ?? self::TXN_ID)],
                ],
            ], $overrides), 201),
        ]);
    }

    private function postWebhook(array $event): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($event, JSON_UNESCAPED_SLASHES);
        $ts = time();
        $sig = hash_hmac('sha256', $ts.':'.$raw, self::WEBHOOK_SECRET);
        $header = 'ts='.$ts.';h1='.$sig;

        return $this->call('POST', '/api/v1/payments/paddle-webhook', [], [], [], [
            'HTTP_Paddle-Signature' => $header,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $raw);
    }

    private function completedEvent(array $overrides = [], array $customData = []): array
    {
        return array_merge([
            'event_id' => 'evt_'.Str::random(26),
            'event_type' => 'transaction.completed',
            'occurred_at' => now()->toIso8601String(),
            'notification_id' => 'ntf_'.Str::random(26),
            'data' => array_merge([
                'id' => self::TXN_ID,
                'status' => 'completed',
                'custom_data' => $customData,
                'totals' => ['subtotal' => '2559', 'tax' => '0', 'total' => '2559', 'currency_code' => 'USD'],
                'subscription_id' => self::SUB_ID,
                'origin' => 'web',
            ], $overrides),
        ], $overrides);
    }

    // ── Configuration ─────────────────────────────────────────────────────

    public function test_gateways_reports_paddle_disabled(): void
    {
        $this->getJson('/api/v1/payment-gateways')->assertOk()->assertJson([
            'paddle' => ['enabled' => false, 'configured' => false],
        ]);
    }

    public function test_gateways_reports_paddle_configured(): void
    {
        $this->enablePaddle();
        $this->getJson('/api/v1/payment-gateways')->assertOk()->assertJson([
            'paddle' => ['enabled' => true, 'configured' => true, 'environment' => 'sandbox'],
        ]);
    }

    public function test_initiate_rejects_when_paddle_disabled(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makePaddlePlan();

        $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'paddle',
        ], $this->authHeaders($user, $tenant))->assertStatus(422);
    }

    public function test_initiate_rejects_when_credentials_missing(): void
    {
        PlatformSetting::set('paddle_enabled', true);
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makePaddlePlan();

        $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'paddle',
        ], $this->authHeaders($user, $tenant))->assertStatus(422);
    }

    public function test_initiate_rejects_missing_price_id(): void
    {
        $this->enablePaddle();
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makePaddlePlan(['paddle_price_id_monthly' => null]);

        $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'paddle',
        ], $this->authHeaders($user, $tenant))->assertStatus(422);
    }

    public function test_initiate_rejects_invalid_cycle_and_plan(): void
    {
        $this->enablePaddle();
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');

        $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => 'missing', 'billing_cycle' => 'monthly', 'gateway' => 'paddle',
        ], $this->authHeaders($user, $tenant))->assertStatus(422);

        $plan = $this->makePaddlePlan();
        $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'weekly', 'gateway' => 'paddle',
        ], $this->authHeaders($user, $tenant))->assertStatus(422);
    }

    public function test_initiate_returns_paddle_payload_and_price_from_database(): void
    {
        $this->enablePaddle();
        $this->fakeCreateTransaction();
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makePaddlePlan();

        $captured = null;
        Http::fake([
            '*/transactions' => function ($request) use (&$captured) {
                $captured = json_decode($request->body(), true);

                return Http::response([
                    'data' => ['id' => self::TXN_ID, 'status' => 'draft', 'custom_data' => [], 'checkout' => ['url' => 'https://checkout.paddle.com/x?_ptxn='.self::TXN_ID]],
                ], 201);
            },
        ]);

        $res = $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
            'gateway' => 'paddle',
            'amount' => 0.01, // forged amount — must be ignored
        ], $this->authHeaders($user, $tenant));

        $res->assertOk()
            ->assertJsonStructure(['payment_id', 'paddle_transaction_id', 'paddle_client_token', 'paddle_env'])
            ->assertJson(['paddle_transaction_id' => self::TXN_ID, 'paddle_env' => 'sandbox']);

        $this->assertNotNull($captured);
        $this->assertSame('pri_01gqtestmonthlypaddle0001', $captured['items'][0]['price_id']);
        $this->assertSame('USD', $captured['currency_code']);
        $this->assertArrayNotHasKey('amount', $captured);
        // 29 JOD × 0.71 = 20.59 USD + $5 service fee = 25.59 USD = 2559 minor.
        $payment = Payment::where('id', $res->json('payment_id'))->firstOrFail();
        $this->assertSame('2559', $payment->gateway_data['expected_subtotal_minor']);
        $this->assertSame(25.59, (float) $payment->amount);
        $this->assertSame('USD', $payment->currency);
    }

    public function test_initiate_sends_inline_non_recurring_discount_for_coupon(): void
    {
        $this->enablePaddle();
        $this->fakeCreateTransaction();
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makePaddlePlan();

        Coupon::create([
            'id' => (string) Str::uuid(), 'tenant_id' => null, 'code' => 'SAVE10', 'type' => 'percent',
            'value' => 10, 'currency' => 'JOD', 'max_uses' => 100, 'used_count' => 0, 'is_active' => true,
        ]);

        $captured = null;
        Http::fake([
            '*/transactions' => function ($request) use (&$captured) {
                $captured = json_decode($request->body(), true);

                return Http::response(['data' => ['id' => self::TXN_ID, 'status' => 'draft', 'custom_data' => [], 'checkout' => ['url' => 'https://checkout.paddle.com/x?_ptxn='.self::TXN_ID]]], 201);
            },
        ]);

        $res = $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'paddle', 'coupon_code' => 'SAVE10',
        ], $this->authHeaders($user, $tenant));

        $res->assertOk();
        $this->assertSame('flat', $captured['discount']['type']);
        $this->assertSame('206', $captured['discount']['amount']);
        $this->assertFalse($captured['discount']['recur']);

        $payment = Payment::where('id', $res->json('payment_id'))->firstOrFail();
        // 29 JOD → 20.59 USD, −10% = 18.53 USD + $5 fee = 23.53 USD → 2353 minor.
        $this->assertSame('2353', $payment->gateway_data['expected_subtotal_minor']);
    }

    public function test_duplicate_initiate_fails_on_unique_transaction(): void
    {
        $this->enablePaddle();
        $this->fakeCreateTransaction();
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makePaddlePlan();
        $headers = $this->authHeaders($user, $tenant);

        $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'paddle',
        ], $headers)->assertOk();

        // Same Paddle transaction id reused → unique (gateway, transaction_id) violation.
        $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'paddle',
        ], $headers)->assertStatus(422);
    }

    // ── Webhook signature ────────────────────────────────────────────────

    public function test_webhook_rejects_invalid_signature(): void
    {
        $this->enablePaddle();
        $event = $this->completedEvent();
        $raw = json_encode($event);

        $this->call('POST', '/api/v1/payments/paddle-webhook', [], [], [], [
            'HTTP_Paddle-Signature' => 'ts='.time().';h1='.str_repeat('0', 64),
            'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertStatus(401);
    }

    public function test_webhook_rejects_malformed_signature(): void
    {
        $this->enablePaddle();
        $event = $this->completedEvent();
        $raw = json_encode($event);

        $this->call('POST', '/api/v1/payments/paddle-webhook', [], [], [], [
            'HTTP_Paddle-Signature' => 'garbage',
            'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertStatus(401);
    }

    public function test_webhook_rejects_expired_timestamp(): void
    {
        $this->enablePaddle();
        $event = $this->completedEvent();
        $raw = json_encode($event, JSON_UNESCAPED_SLASHES);
        $ts = time() - 1000;
        $sig = hash_hmac('sha256', $ts.':'.$raw, self::WEBHOOK_SECRET);

        $this->call('POST', '/api/v1/payments/paddle-webhook', [], [], [], [
            'HTTP_Paddle-Signature' => 'ts='.$ts.';h1='.$sig,
            'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertStatus(401);
    }

    public function test_webhook_ignores_unknown_event(): void
    {
        $this->enablePaddle();
        $this->postWebhook(['event_id' => 'evt_'.Str::random(26), 'event_type' => 'customer.created', 'data' => []])
            ->assertOk()->assertJson(['message' => 'Ignored event.']);
    }

    // ── Completion ───────────────────────────────────────────────────────

    public function test_webhook_completes_payment_and_activates_subscription(): void
    {
        $this->enablePaddle();
        $this->fakeCreateTransaction();
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makePaddlePlan();

        $res = $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'paddle',
        ], $this->authHeaders($user, $tenant));
        $paymentId = $res->json('payment_id');

        $event = $this->completedEvent([], ['payment_id' => $paymentId, 'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly']);
        $this->postWebhook($event)->assertOk();

        $payment = Payment::findOrFail($paymentId);
        $this->assertSame('completed', $payment->status);
        $this->assertSame(self::TXN_ID, $payment->transaction_id);
        $this->assertSame(self::SUB_ID, $payment->gateway_data['paddle_subscription_id']);

        $this->assertDatabaseHas('invoices', ['payment_id' => $paymentId, 'status' => 'paid']);
        $this->assertDatabaseHas('transactions', ['reference_type' => 'payment', 'reference_id' => $paymentId, 'type' => 'payment']);
        $this->assertSame('active', $payment->subscription->fresh()->status);
    }

    public function test_webhook_is_idempotent_on_replay(): void
    {
        $this->enablePaddle();
        $this->fakeCreateTransaction();
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makePaddlePlan();

        $paymentId = $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'paddle',
        ], $this->authHeaders($user, $tenant))->json('payment_id');

        $event = $this->completedEvent([], ['payment_id' => $paymentId, 'tenant_id' => $tenant->id, 'plan_id' => $plan->id]);
        $this->postWebhook($event)->assertOk();
        $this->postWebhook($event)->assertOk();

        $this->assertSame(1, Invoice::where('payment_id', $paymentId)->count());
        $this->assertSame(1, Transaction::where('reference_id', $paymentId)->where('type', 'payment')->count());
        $this->assertSame('completed', Payment::findOrFail($paymentId)->status);
    }

    public function test_webhook_rejects_wrong_tenant(): void
    {
        $this->enablePaddle();
        $this->fakeCreateTransaction();
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makePaddlePlan();

        $paymentId = $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'paddle',
        ], $this->authHeaders($user, $tenant))->json('payment_id');

        $event = $this->completedEvent([], ['payment_id' => $paymentId, 'tenant_id' => Str::uuid(), 'plan_id' => $plan->id]);
        $this->postWebhook($event)->assertOk();

        $this->assertSame('pending', Payment::findOrFail($paymentId)->status);
    }

    public function test_webhook_rejects_amount_tampering(): void
    {
        $this->enablePaddle();
        $this->fakeCreateTransaction();
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makePaddlePlan();

        $paymentId = $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'paddle',
        ], $this->authHeaders($user, $tenant))->json('payment_id');

        $event = $this->completedEvent(['data' => ['id' => self::TXN_ID, 'status' => 'completed', 'custom_data' => ['payment_id' => $paymentId], 'totals' => ['subtotal' => '1', 'tax' => '0', 'total' => '1', 'currency_code' => 'USD']]]);
        $this->postWebhook($event)->assertOk();

        $this->assertSame('failed', Payment::findOrFail($paymentId)->status);
    }

    public function test_webhook_rejects_wrong_currency(): void
    {
        $this->enablePaddle();
        $this->fakeCreateTransaction();
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makePaddlePlan();

        $paymentId = $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'paddle',
        ], $this->authHeaders($user, $tenant))->json('payment_id');

        $event = $this->completedEvent([
            'data' => ['id' => self::TXN_ID, 'status' => 'completed', 'custom_data' => ['payment_id' => $paymentId], 'totals' => ['subtotal' => '2559', 'tax' => '0', 'total' => '2559', 'currency_code' => 'EUR']],
        ]);
        $this->postWebhook($event)->assertOk();

        $this->assertSame('failed', Payment::findOrFail($paymentId)->status);
    }

    public function test_webhook_ignores_unknown_payment(): void
    {
        $this->enablePaddle();
        $event = $this->completedEvent([], ['payment_id' => Str::uuid(), 'tenant_id' => Str::uuid()]);
        $this->postWebhook($event)->assertOk();
    }

    // ── Renewals ─────────────────────────────────────────────────────────

    public function test_renewal_creates_payment_and_extends_subscription(): void
    {
        $this->enablePaddle();
        $tenant = $this->makeTenant();
        $subscription = $this->attachSubscription($tenant, 'active');
        $subscription->update(['metadata' => ['paddle_subscription_id' => self::SUB_ID]]);

        $event = $this->completedEvent([
            'data' => [
                'id' => 'txn_01gqtestrenewalpaddle0001',
                'status' => 'completed',
                'custom_data' => null,
                'origin' => 'subscription_recurring',
                'subscription_id' => self::SUB_ID,
                'totals' => ['subtotal' => '2559', 'tax' => '0', 'total' => '2559', 'currency_code' => 'USD'],
            ],
        ]);

        $this->postWebhook($event)->assertOk();

        $payment = Payment::where('gateway', 'paddle')->where('transaction_id', 'txn_01gqtestrenewalpaddle0001')->first();
        $this->assertNotNull($payment);
        $this->assertSame('completed', $payment->status);
        $this->assertSame('active', $payment->subscription->fresh()->status);
        $this->assertSame(1, Transaction::where('reference_id', $payment->id)->where('type', 'payment')->count());
    }

    public function test_renewal_without_binding_is_ignored(): void
    {
        $this->enablePaddle();
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant, 'active');

        $event = $this->completedEvent([
            'data' => [
                'id' => 'txn_01gqtestunboundpaddle0001',
                'status' => 'completed',
                'custom_data' => null,
                'origin' => 'subscription_recurring',
                'subscription_id' => 'sub_01gqtestunboundsubscription0001',
                'totals' => ['subtotal' => '2559', 'tax' => '0', 'total' => '2559', 'currency_code' => 'USD'],
            ],
        ]);

        $this->postWebhook($event)->assertOk();
        $this->assertSame(0, Payment::where('transaction_id', 'txn_01gqtestunboundpaddle0001')->count());
    }

    public function test_renewal_is_idempotent_on_replay(): void
    {
        $this->enablePaddle();
        $tenant = $this->makeTenant();
        $subscription = $this->attachSubscription($tenant, 'active');
        $subscription->update(['metadata' => ['paddle_subscription_id' => self::SUB_ID]]);

        $event = $this->completedEvent([
            'data' => [
                'id' => 'txn_01gqtestrenewalpaddle0002',
                'status' => 'completed',
                'custom_data' => null,
                'origin' => 'subscription_recurring',
                'subscription_id' => self::SUB_ID,
                'totals' => ['subtotal' => '2559', 'tax' => '0', 'total' => '2559', 'currency_code' => 'USD'],
            ],
        ]);

        $this->postWebhook($event)->assertOk();
        $this->postWebhook($event)->assertOk();

        $this->assertSame(1, Payment::where('transaction_id', 'txn_01gqtestrenewalpaddle0002')->count());
    }

    // ── Subscription lifecycle ───────────────────────────────────────────

    public function test_subscription_canceled_webhook_stops_renewal(): void
    {
        $this->enablePaddle();
        $tenant = $this->makeTenant();
        $subscription = $this->attachSubscription($tenant, 'active');
        $subscription->update(['metadata' => ['paddle_subscription_id' => self::SUB_ID]]);

        $this->postWebhook([
            'event_id' => 'evt_'.Str::random(26),
            'event_type' => 'subscription.canceled',
            'occurred_at' => now()->toIso8601String(),
            'data' => ['id' => self::SUB_ID, 'status' => 'canceled', 'canceled_at' => now()->toIso8601String()],
        ])->assertOk();

        $fresh = $subscription->fresh();
        $this->assertFalse($fresh->auto_renew);
        $this->assertNotNull($fresh->cancelled_at);
    }

    public function test_subscription_past_due_webhook_sets_status(): void
    {
        $this->enablePaddle();
        $tenant = $this->makeTenant();
        $subscription = $this->attachSubscription($tenant, 'active');
        $subscription->update(['metadata' => ['paddle_subscription_id' => self::SUB_ID]]);

        $this->postWebhook([
            'event_id' => 'evt_'.Str::random(26),
            'event_type' => 'subscription.past_due',
            'occurred_at' => now()->toIso8601String(),
            'data' => ['id' => self::SUB_ID, 'status' => 'past_due'],
        ])->assertOk();

        $this->assertSame('past_due', $subscription->fresh()->status);
    }

    public function test_subscription_created_binds_paddle_id(): void
    {
        $this->enablePaddle();
        $this->fakeCreateTransaction();
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makePaddlePlan();

        $paymentId = $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'paddle',
        ], $this->authHeaders($user, $tenant))->json('payment_id');

        // subscription.created carries custom_data copied from the transaction.
        $this->postWebhook([
            'event_id' => 'evt_'.Str::random(26),
            'event_type' => 'subscription.created',
            'occurred_at' => now()->toIso8601String(),
            'data' => ['id' => self::SUB_ID, 'status' => 'active', 'custom_data' => ['payment_id' => $paymentId]],
        ])->assertOk();

        $payment = Payment::findOrFail($paymentId);
        $this->assertSame(self::SUB_ID, $payment->subscription->fresh()->metadata['paddle_subscription_id']);
    }

    // ── Refunds ──────────────────────────────────────────────────────────

    public function test_approved_refund_syncs_via_refund_service(): void
    {
        $this->enablePaddle();
        $this->fakeCreateTransaction();
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant, 'active');
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makePaddlePlan();

        $paymentId = $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'paddle',
        ], $this->authHeaders($user, $tenant))->json('payment_id');

        $this->postWebhook($this->completedEvent([], ['payment_id' => $paymentId, 'tenant_id' => $tenant->id]))->assertOk();
        $this->assertSame('completed', Payment::findOrFail($paymentId)->status);

        $this->postWebhook([
            'event_id' => 'evt_'.Str::random(26),
            'event_type' => 'adjustment.created',
            'occurred_at' => now()->toIso8601String(),
            'data' => ['id' => 'adj_'.Str::random(26), 'action' => 'refund', 'status' => 'approved', 'transaction_id' => self::TXN_ID, 'reason' => 'test'],
        ])->assertOk();

        $payment = Payment::findOrFail($paymentId);
        $this->assertSame('refunded', $payment->status);
        $this->assertSame(1, Transaction::where('reference_id', $paymentId)->where('type', 'refund')->count());
        $this->assertFalse($payment->subscription->fresh()->auto_renew);
    }

    public function test_pending_refund_is_ignored(): void
    {
        $this->enablePaddle();
        $this->fakeCreateTransaction();
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant, 'active');
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makePaddlePlan();

        $paymentId = $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'paddle',
        ], $this->authHeaders($user, $tenant))->json('payment_id');
        $this->postWebhook($this->completedEvent([], ['payment_id' => $paymentId, 'tenant_id' => $tenant->id]))->assertOk();

        $this->postWebhook([
            'event_id' => 'evt_'.Str::random(26),
            'event_type' => 'adjustment.created',
            'data' => ['id' => 'adj_'.Str::random(26), 'action' => 'refund', 'status' => 'pending_approval', 'transaction_id' => self::TXN_ID],
        ])->assertOk();

        $this->assertSame('completed', Payment::findOrFail($paymentId)->status);
    }

    public function test_duplicate_refund_does_not_double_apply(): void
    {
        $this->enablePaddle();
        $this->fakeCreateTransaction();
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant, 'active');
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $plan = $this->makePaddlePlan();

        $paymentId = $this->postJson('/api/v1/payments/initiate', [
            'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'paddle',
        ], $this->authHeaders($user, $tenant))->json('payment_id');
        $this->postWebhook($this->completedEvent([], ['payment_id' => $paymentId, 'tenant_id' => $tenant->id]))->assertOk();

        $refund = [
            'event_id' => 'evt_'.Str::random(26),
            'event_type' => 'adjustment.created',
            'occurred_at' => now()->toIso8601String(),
            'data' => ['id' => 'adj_'.Str::random(26), 'action' => 'refund', 'status' => 'approved', 'transaction_id' => self::TXN_ID],
        ];

        $this->postWebhook($refund)->assertOk();
        $this->postWebhook($refund)->assertOk();

        $this->assertSame(1, Transaction::where('reference_id', $paymentId)->where('type', 'refund')->count());
    }

    // ── Cancellation (user-initiated → Paddle API) ───────────────────────

    public function test_cancel_calls_paddle_api_before_local_update(): void
    {
        $this->enablePaddle();
        $tenant = $this->makeTenant();
        $subscription = $this->attachSubscription($tenant, 'active');
        $subscription->update(['metadata' => ['paddle_subscription_id' => self::SUB_ID]]);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');

        Http::fake([
            '*/subscriptions/*/cancel' => Http::response(['data' => ['id' => self::SUB_ID, 'status' => 'active']], 200),
        ]);

        $this->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", [], $this->authHeaders($user, $tenant))
            ->assertOk();

        $fresh = $subscription->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertFalse($fresh->auto_renew);
    }

    public function test_cancel_aborts_when_paddle_api_fails(): void
    {
        $this->enablePaddle();
        $tenant = $this->makeTenant();
        $subscription = $this->attachSubscription($tenant, 'active');
        $subscription->update(['metadata' => ['paddle_subscription_id' => self::SUB_ID]]);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');

        Http::fake([
            '*/subscriptions/*/cancel' => Http::response(['error' => ['message' => 'upstream down']], 502),
        ]);

        $this->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", [], $this->authHeaders($user, $tenant))
            ->assertStatus(502);

        $this->assertSame('active', $subscription->fresh()->status);
    }

    public function test_cancel_handles_already_canceled_in_paddle(): void
    {
        $this->enablePaddle();
        $tenant = $this->makeTenant();
        $subscription = $this->attachSubscription($tenant, 'active');
        $subscription->update(['metadata' => ['paddle_subscription_id' => self::SUB_ID]]);
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'owner');

        Http::fake([
            '*/subscriptions/*/cancel' => Http::response(['error' => ['message' => 'not found']], 404),
        ]);

        $this->postJson("/api/v1/subscriptions/{$subscription->id}/cancel", [], $this->authHeaders($user, $tenant))
            ->assertOk();

        $this->assertSame('cancelled', $subscription->fresh()->status);
    }
}
