<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Models\Transaction;
use App\Services\PaymentCompletionService;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

class RefundWebhookTest extends TenantTestCase
{
    private const SECRET = 'test-refund-secret';

    protected function setUp(): void
    {
        parent::setUp();

        // الحاوية تمرر .env كمتغيرات بيئة حية لا يمكن لـ phpunit.xml تجاوزها —
        // نضبط سر الاختبار مباشرة في config لكل اختبار.
        config(['payments.refund_webhook_secret' => self::SECRET]);
    }

    protected function makeCompletedPayment(): array
    {
        $tenant = $this->makeTenant();

        $plan = SubscriptionPlan::create([
            'id' => (string) Str::uuid(),
            'name' => 'Pro', 'slug' => 'pro-'.uniqid(), 'monthly_price' => 49.99, 'yearly_price' => 499.99,
            'currency' => 'JOD', 'trial_days' => 7, 'is_active' => true, 'is_public' => true, 'sort_order' => 0,
        ]);

        $sub = TenantSubscription::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
            'status' => 'pending', 'billing_cycle' => 'monthly', 'starts_at' => now(), 'auto_renew' => true,
        ]);

        $payment = Payment::create([
            'id' => UuidV7::generate(), 'tenant_id' => $tenant->id, 'tenant_subscription_id' => $sub->id,
            'transaction_id' => 'manual-tx-'.uniqid(), 'gateway' => 'manual', 'amount' => 49.99, 'currency' => 'JOD',
            'status' => 'completed', 'payment_method' => 'manual', 'gateway_data' => ['manual' => true],
        ]);

        app(PaymentCompletionService::class)->complete($payment);

        return ['tenant' => $tenant, 'subscription' => $sub->fresh(), 'payment' => $payment->fresh()];
    }

    private function signature(string $payload): string
    {
        return 'sha256='.hash_hmac('sha256', $payload, self::SECRET);
    }

    /**
     * الصيغة الفعلية التي يرسلها postJson (JSON_UNESCAPED_SLASHES|UNICODE) —
     * التوقيع يجب أن يُبنى على نفس البايتات وصولاً للخادم.
     */
    private function jsonBody(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function test_refund_webhook_syncs_refund_with_full_side_effects(): void
    {
        $ctx = $this->makeCompletedPayment();
        $payment = $ctx['payment'];

        $this->assertSame('completed', $payment->status);
        $this->assertSame('active', $ctx['subscription']->status);
        $this->assertSame(1, Invoice::where('payment_id', $payment->id)->where('status', 'paid')->count());

        $payload = ['payment_id' => $payment->id, 'gateway' => 'manual', 'refund_reference' => 'click-ref-123'];

        $this->postJson('/api/v1/payments/refund-webhook', $payload, [
            'X-Webhook-Signature' => $this->signature(json_encode($payload)),
        ])->assertOk()->assertJson(['status' => 'refunded', 'already_refunded' => false]);

        $payment->refresh();
        $this->assertSame('refunded', $payment->status);
        $this->assertTrue((bool) ($payment->gateway_data['refund_synced_via_webhook'] ?? false));
        $this->assertSame('click-ref-123', $payment->gateway_data['refund']['refund_reference'] ?? null);
        $this->assertSame(1, Transaction::where('reference_id', $payment->id)->where('type', 'refund')->count());
        $this->assertSame(0, Invoice::where('payment_id', $payment->id)->where('status', 'paid')->count());
        $this->assertSame('cancelled', $ctx['subscription']->fresh()->status);
        $this->assertFalse((bool) $ctx['subscription']->fresh()->auto_renew);
    }

    public function test_refund_webhook_is_idempotent_on_replay(): void
    {
        $ctx = $this->makeCompletedPayment();
        $payment = $ctx['payment'];
        $payload = ['payment_id' => $payment->id];

        $this->postJson('/api/v1/payments/refund-webhook', $payload, [
            'X-Webhook-Signature' => $this->signature(json_encode($payload)),
        ])->assertOk()->assertJson(['status' => 'refunded', 'already_refunded' => false]);

        $this->postJson('/api/v1/payments/refund-webhook', $payload, [
            'X-Webhook-Signature' => $this->signature(json_encode($payload)),
        ])->assertOk()->assertJson(['status' => 'refunded', 'already_refunded' => true]);

        $this->assertSame(1, Transaction::where('reference_id', $payment->id)->where('type', 'refund')->count());
    }

    public function test_refund_webhook_rejects_invalid_signature(): void
    {
        $ctx = $this->makeCompletedPayment();
        $payment = $ctx['payment'];

        $this->postJson('/api/v1/payments/refund-webhook', ['payment_id' => $payment->id], [
            'X-Webhook-Signature' => 'sha256=wrong',
        ])->assertUnauthorized();

        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertSame(0, Transaction::where('reference_id', $payment->id)->where('type', 'refund')->count());
    }

    public function test_refund_webhook_rejects_amount_mismatch(): void
    {
        $ctx = $this->makeCompletedPayment();
        $payment = $ctx['payment'];

        $payload = ['payment_id' => $payment->id, 'amount' => 1.00];

        $this->postJson('/api/v1/payments/refund-webhook', $payload, [
            'X-Webhook-Signature' => $this->signature(json_encode($payload)),
        ])->assertStatus(422);

        $this->assertSame('completed', $payment->fresh()->status);
    }

    public function test_refund_webhook_rejects_gateway_mismatch(): void
    {
        $ctx = $this->makeCompletedPayment();
        $payment = $ctx['payment'];

        $payload = ['payment_id' => $payment->id, 'gateway' => 'paytabs'];

        $this->postJson('/api/v1/payments/refund-webhook', $payload, [
            'X-Webhook-Signature' => $this->signature(json_encode($payload)),
        ])->assertStatus(422);

        $this->assertSame('completed', $payment->fresh()->status);
    }

    public function test_refund_webhook_returns_404_for_unknown_payment(): void
    {
        $payload = ['payment_id' => (string) UuidV7::generate()];

        $this->postJson('/api/v1/payments/refund-webhook', $payload, [
            'X-Webhook-Signature' => $this->signature($this->jsonBody($payload)),
        ])->assertNotFound();
    }

    public function test_refund_webhook_requires_configured_secret(): void
    {
        config(['payments.refund_webhook_secret' => '']);

        try {
            $this->postJson('/api/v1/payments/refund-webhook', ['payment_id' => (string) UuidV7::generate()])
                ->assertStatus(503);
        } finally {
            config(['payments.refund_webhook_secret' => self::SECRET]);
        }
    }
}
