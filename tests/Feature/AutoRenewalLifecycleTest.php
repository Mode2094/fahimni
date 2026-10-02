<?php

namespace Tests\Feature;

use App\Jobs\ExpireSubscriptionsJob;
use App\Jobs\ExpiryFollowUpJob;
use App\Jobs\PurgeExpiredTenantContentJob;
use App\Jobs\RenewalReminderJob;
use App\Models\Announcement;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\TenantSubscription;
use App\Services\PayPalGateway;
use App\Services\PaddleGateway;
use App\Services\PayTabsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

class AutoRenewalLifecycleTest extends TenantTestCase
{
    use RefreshDatabase;

    // ─── Gateway extractors ─────────────────────────────────────────

    public function test_paytabs_extracts_saved_card_from_callback(): void
    {
        $method = PayTabsGateway::extractSavedMethod([
            'tran_ref' => 'TST1',
            'token' => 'TOK123',
            'payment_info' => ['card_scheme' => 'Visa', 'pan' => '4111********1111'],
        ]);

        $this->assertNotNull($method);
        $this->assertSame('TOK123', $method['token']);
        $this->assertSame('Visa', $method['brand']);
        $this->assertSame('1111', $method['last4']);
    }

    public function test_paypal_extracts_vault_from_capture(): void
    {
        $method = PayPalGateway::extractSavedMethod([
            'payment_source' => [
                'card' => [
                    'brand' => 'VISA',
                    'last_digits' => '0005',
                    'attributes' => ['vault' => ['vault_id' => 'VAULT1']],
                ],
            ],
        ]);

        $this->assertNotNull($method);
        $this->assertSame('VAULT1', $method['token']);
        $this->assertSame('VISA', $method['brand']);
        $this->assertSame('0005', $method['last4']);
    }

    public function test_paddle_extracts_customer_token_from_webhook(): void
    {
        $method = PaddleGateway::extractSavedMethod([
            'customer_id' => 'ctm_1',
            'address_id' => 'add_1',
            'items' => [['price' => ['id' => 'pri_1']]],
            'payment' => ['method_details' => ['card' => ['type' => 'visa', 'last4' => '4242']]],
        ]);

        $this->assertNotNull($method);
        $saved = json_decode($method['token'], true);
        $this->assertSame('ctm_1', $saved['customer_id']);
        $this->assertSame('pri_1', $saved['price_id']);
        $this->assertSame('visa', $method['brand']);
    }

    public function test_paytabs_extractor_rejects_payload_without_token(): void
    {
        $this->assertNull(PayTabsGateway::extractSavedMethod(['tran_status' => 'A']));
    }

    // ─── Auto-charge at expiry ──────────────────────────────────────

    public function test_paytabs_saved_card_is_charged_at_expiry_and_subscription_extends(): void
    {
        Queue::fake();
        Http::fake([
            'secure.paytabs.sa/payment/request' => Http::response([
                'tran_ref' => 'TST-RENEW-1',
                'payment_result' => ['response_status' => 'A', 'response_code' => '100', 'response_message' => 'Approved'],
            ]),
        ]);

        $tenant = $this->makeTenant();
        $tenant->update(['payment_gateway' => 'paytabs', 'payment_token' => 'TOK123', 'card_last_four' => '1111']);

        $sub = $this->attachSubscription($tenant, 'active');
        $sub->update(['ends_at' => now()->subMinutes(5)]);

        (new ExpireSubscriptionsJob)->handle();

        $sub = $sub->fresh();
        $this->assertEquals('active', $sub->status); // never flipped to past_due
        // Period had already ended → new cycle starts from now.
        $this->assertEquals(
            now()->addMonth()->format('Y-m-d H:i'),
            $sub->ends_at->format('Y-m-d H:i'),
        );

        $payment = Payment::withoutTenant()
            ->where('tenant_subscription_id', $sub->id)
            ->where('gateway', 'paytabs')
            ->first();
        $this->assertNotNull($payment);
        $this->assertEquals('completed', $payment->status);
        $this->assertEquals('saved_card', $payment->payment_method);
        $this->assertEquals('TST-RENEW-1', $payment->transaction_id);
    }

    public function test_mid_cycle_renewal_extends_from_current_period_end(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant();
        $sub = $this->attachSubscription($tenant, 'active');
        $futureEnds = now()->addDays(10);
        $sub->update(['ends_at' => $futureEnds]);

        $payment = Payment::withoutTenant()->create([
            'tenant_id' => $tenant->id,
            'tenant_subscription_id' => $sub->id,
            'transaction_id' => 'mid-'.(string) Str::uuid(),
            'gateway' => 'paytabs',
            'amount' => 19.99,
            'currency' => 'JOD',
            'status' => 'pending',
            'payment_method' => 'saved_card',
        ]);

        app(\App\Services\PaymentCompletionService::class)->complete($payment->fresh());

        // Renewing before the period ends must not shorten the paid time.
        $this->assertEquals(
            $futureEnds->addMonth()->format('Y-m-d H:i'),
            $sub->fresh()->ends_at->format('Y-m-d H:i'),
        );
    }

    public function test_paypal_saved_card_is_charged_at_expiry(): void
    {
        Queue::fake();
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response(['id' => 'ORD9']),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORD9/capture' => Http::response([
                'status' => 'COMPLETED',
                'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP7']]]]],
            ]),
        ]);

        $tenant = $this->makeTenant();
        $tenant->update(['payment_gateway' => 'paypal', 'payment_token' => 'VAULT1']);

        $sub = $this->attachSubscription($tenant, 'active');
        $sub->update(['ends_at' => now()->subMinutes(5)]);

        (new ExpireSubscriptionsJob)->handle();

        $this->assertEquals('active', $sub->fresh()->status);

        $payment = Payment::withoutTenant()
            ->where('tenant_subscription_id', $sub->id)
            ->where('gateway', 'paypal')
            ->first();
        $this->assertNotNull($payment);
        $this->assertEquals('completed', $payment->status);
        $this->assertEquals('CAP7', $payment->transaction_id);
    }

    public function test_paddle_renewal_is_initiated_async_without_flipping_status(): void
    {
        Queue::fake();
        Http::fake([
            'sandbox-api.paddle.com/transactions' => Http::response([
                'data' => ['id' => 'txn_1', 'status' => 'ready'],
            ]),
        ]);

        $tenant = $this->makeTenant();
        $tenant->update([
            'payment_gateway' => 'paddle',
            'payment_token' => json_encode(['customer_id' => 'ctm_1', 'price_id' => 'pri_1']),
        ]);

        $sub = $this->attachSubscription($tenant, 'active');
        $sub->update(['ends_at' => now()->subMinutes(5)]);

        (new ExpireSubscriptionsJob)->handle();

        // Paddle confirms asynchronously via webhook — the subscription must
        // NOT be flipped while the charge is in flight.
        $this->assertEquals('active', $sub->fresh()->status);

        $payment = Payment::withoutTenant()
            ->where('tenant_subscription_id', $sub->id)
            ->where('gateway', 'paddle')
            ->first();
        $this->assertNotNull($payment);
        $this->assertEquals('pending', $payment->status);
        $this->assertEquals('txn_1', $payment->transaction_id);
        $this->assertArrayHasKey('expected_subtotal_minor', $payment->gateway_data);

        // A second run inside the grace window must not stack a duplicate.
        (new ExpireSubscriptionsJob)->handle();

        $count = Payment::withoutTenant()
            ->where('tenant_subscription_id', $sub->id)
            ->where('gateway', 'paddle')
            ->count();
        $this->assertEquals(1, $count);
    }

    public function test_failed_auto_charge_flips_to_past_due_with_renewal_invoice(): void
    {
        Queue::fake();
        Http::fake([
            'secure.paytabs.sa/payment/request' => Http::response([
                'tran_ref' => 'TST-DENIED',
                'payment_result' => ['response_status' => 'D', 'response_message' => 'Insufficient funds'],
            ]),
        ]);

        $tenant = $this->makeTenant();
        $tenant->update(['payment_gateway' => 'paytabs', 'payment_token' => 'TOK123']);

        $sub = $this->attachSubscription($tenant, 'active');
        $sub->update(['ends_at' => now()->subMinutes(5)]);

        (new ExpireSubscriptionsJob)->handle();

        $sub = $sub->fresh();
        $this->assertEquals('past_due', $sub->status);

        $failed = Payment::withoutTenant()
            ->where('tenant_subscription_id', $sub->id)
            ->where('gateway', 'paytabs')
            ->where('status', 'failed')
            ->exists();
        $this->assertTrue($failed);

        $invoice = Payment::withoutTenant()
            ->where('tenant_subscription_id', $sub->id)
            ->where('payment_method', 'renewal')
            ->exists();
        $this->assertTrue($invoice);
    }

    public function test_tenant_without_saved_card_follows_normal_expiry_path(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant(); // no payment token
        $sub = $this->attachSubscription($tenant, 'active');
        $sub->update(['ends_at' => now()->subMinutes(5)]);

        (new ExpireSubscriptionsJob)->handle();

        $this->assertEquals('past_due', $sub->fresh()->status);

        $this->assertEquals(0, Payment::withoutTenant()
            ->where('tenant_subscription_id', $sub->id)
            ->where('payment_method', 'saved_card')
            ->count());
    }

    // ─── Pre-expiry reminders 3/2/1 ─────────────────────────────────

    public function test_pre_expiry_reminders_fire_once_per_offset(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant();
        $sub = $this->attachSubscription($tenant, 'active');
        $sub->update(['ends_at' => now()->addDays(3), 'auto_renew' => false]);

        (new RenewalReminderJob)->handle();

        $meta = $sub->fresh()->metadata;
        $this->assertEquals([3], $meta['pre_expiry_reminders_sent']);

        // Same day again → no duplicate.
        (new RenewalReminderJob)->handle();
        $this->assertEquals([3], $sub->fresh()->metadata['pre_expiry_reminders_sent']);

        // Next offset.
        $sub->update(['ends_at' => now()->addDays(2)]);
        (new RenewalReminderJob)->handle();
        $this->assertEquals([3, 2], $sub->fresh()->metadata['pre_expiry_reminders_sent']);
    }

    public function test_pre_expiry_reminder_mentions_saved_card_for_auto_renew_tenants(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant();
        $tenant->update(['payment_gateway' => 'paytabs', 'payment_token' => 'TOK', 'card_last_four' => '9999']);

        $owner = $this->makeUser();
        $this->attachToTenant($owner, $tenant, 'owner');

        $sub = $this->attachSubscription($tenant, 'active'); // auto_renew = true
        $sub->update(['ends_at' => now()->addDays(3)]);

        (new RenewalReminderJob)->handle();

        Queue::assertPushed(\App\Jobs\SendNotificationJob::class, function ($job) {
            return $job->title !== null
                && str_contains($job->title, 'تلقائياً')
                && str_contains((string) $job->body, '9999');
        });
    }

    // ─── Post-expiry daily follow-up ────────────────────────────────

    public function test_expiry_followup_fires_daily_inside_grace_window(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant();
        $sub = $this->attachSubscription($tenant, 'active');
        $sub->update(['status' => 'past_due', 'auto_renew' => false, 'ends_at' => now()->subDay()]);

        (new ExpiryFollowUpJob)->handle();

        $today = now()->toDateString();
        $this->assertEquals($today, $sub->fresh()->metadata['last_expiry_reminder_date']);

        // Second run same day → skipped.
        (new ExpiryFollowUpJob)->handle();
        $this->assertEquals($today, $sub->fresh()->metadata['last_expiry_reminder_date']);
    }

    public function test_expiry_followup_skips_subscriptions_outside_grace_window(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant();
        $sub = $this->attachSubscription($tenant, 'active');
        $sub->update(['status' => 'expired', 'auto_renew' => false, 'ends_at' => now()->subDays(11)]);

        (new ExpiryFollowUpJob)->handle();

        $this->assertNull($sub->fresh()->metadata['last_expiry_reminder_date'] ?? null);
    }

    // ─── Content purge after 10 days ────────────────────────────────

    private function seedContent(string $tenantId): void
    {
        $course = Course::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'title' => 'Physics',
            'slug' => 'physics-'.Str::random(4),
            'is_published' => true,
        ]);
        $section = \App\Models\Section::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'course_id' => $course->id,
            'title' => 'Chapter 1',
        ]);
        Lesson::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'section_id' => $section->id,
            'title' => 'Intro',
        ]);
        Exam::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'course_id' => $course->id,
            'title' => 'Midterm',
        ]);
        Announcement::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'title' => 'Welcome',
            'body' => 'Hello students',
        ]);
        $student = $this->makeUser();
        Enrollment::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenantId,
            'course_id' => $course->id,
            'user_id' => $student->id,
            'enrolled_at' => now(),
        ]);
    }

    public function test_content_is_not_purged_before_grace_window_ends(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant();
        $this->seedContent($tenant->id);

        $sub = $this->attachSubscription($tenant, 'active');
        $sub->update(['status' => 'expired', 'auto_renew' => false, 'ends_at' => now()->subDays(9)]);

        (new PurgeExpiredTenantContentJob)->handle();

        $this->assertEquals(1, Course::withoutTenant()->where('tenant_id', $tenant->id)->count());
        $this->assertNull($sub->fresh()->metadata['content_purged_at'] ?? null);
    }

    public function test_content_is_soft_deleted_after_grace_window_and_never_twice(): void
    {
        Queue::fake();

        $tenant = $this->makeTenant();
        $this->seedContent($tenant->id);

        $sub = $this->attachSubscription($tenant, 'active');
        $sub->update(['status' => 'expired', 'auto_renew' => false, 'ends_at' => now()->subDays(11)]);

        (new PurgeExpiredTenantContentJob)->handle();

        foreach ([Course::class, \App\Models\Section::class, Lesson::class, Exam::class, Announcement::class] as $model) {
            $this->assertEquals(0, $model::withoutTenant()->where('tenant_id', $tenant->id)->count(), $model);
        }
        // Soft-deleted courses remain recoverable.
        $this->assertEquals(1, Course::withoutTenant()->withTrashed()->where('tenant_id', $tenant->id)->count());

        $purgedAt = $sub->fresh()->metadata['content_purged_at'] ?? null;
        $this->assertNotNull($purgedAt);

        // Re-run is a no-op.
        (new PurgeExpiredTenantContentJob)->handle();
        $this->assertEquals($purgedAt, $sub->fresh()->metadata['content_purged_at']);
    }
}
