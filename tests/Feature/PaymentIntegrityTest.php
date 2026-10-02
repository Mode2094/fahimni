<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\TenantSubscription;
use App\Models\Transaction;
use App\Services\PaymentCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

class PaymentIntegrityTest extends TenantTestCase
{
    use RefreshDatabase;

    public function test_payment_completion_is_idempotent(): void
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
            'transaction_id' => 'tx-1', 'gateway' => 'paytabs', 'amount' => 49.99, 'currency' => 'JOD',
            'status' => 'pending', 'payment_method' => 'paytabs', 'gateway_data' => [],
        ]);

        // Simulate two concurrent callbacks racing the completion.
        $service = app(PaymentCompletionService::class);
        $service->complete($payment);
        $service->complete($payment);

        // Exactly one ledger entry, one invoice, subscription active once.
        $this->assertEquals(1, Transaction::where('reference_id', $payment->id)->where('type', 'payment')->count());
        $this->assertEquals(1, Invoice::where('payment_id', $payment->id)->count());
        $this->assertEquals('active', $sub->fresh()->status);
        $this->assertEquals(1, TenantSubscription::where('tenant_id', $tenant->id)->where('status', 'active')->count());
    }

    public function test_coupon_usage_is_reserved_at_initiate_and_released_on_failure(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);

        $plan = SubscriptionPlan::create([
            'id' => (string) Str::uuid(),
            'name' => 'Pro', 'slug' => 'pro-'.uniqid(), 'monthly_price' => 49.99, 'yearly_price' => 499.99,
            'currency' => 'JOD', 'trial_days' => 7, 'is_active' => true, 'is_public' => true, 'sort_order' => 0,
        ]);
        $coupon = Coupon::withoutTenant()->create([
            'id' => UuidV7::generate(), 'code' => 'SAVE10', 'type' => 'percent', 'value' => 10,
            'currency' => 'JOD', 'max_uses' => 1, 'used_count' => 0, 'is_active' => true,
        ]);

        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');

        // No gateway configured -> initiate fails AFTER reserving the coupon.
        $response = $this->withHeaders($this->authHeaders($teacher, $tenant))
            ->postJson('/api/v1/payments/initiate', [
                'plan_id' => $plan->id, 'billing_cycle' => 'monthly', 'gateway' => 'paytabs', 'coupon_code' => 'SAVE10',
            ]);

        $response->assertStatus(422);

        // The whole transaction (coupon reservation included) rolled back.
        $this->assertEquals(0, $coupon->fresh()->used_count);
        $this->assertEquals(0, Payment::withoutTenant()->count());
    }
}
