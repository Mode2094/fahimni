<?php

namespace Tests\Feature;

use App\Models\ActivationCard;
use App\Models\Course;
use App\Models\FsEntitlement;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\SuperAdminStatisticsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Symfony\Component\Uid\UuidV7;

class SuperAdminFinancialStatisticsTest extends TenantTestCase
{
    private function teacher(Tenant $tenant): User
    {
        Role::findOrCreate('Teacher', 'web');
        $teacher = $this->makeUser();
        $teacher->assignRole('Teacher');
        $tenant->update(['owner_id' => $teacher->id]);
        $this->attachToTenant($teacher, $tenant, 'owner');

        return $teacher;
    }

    private function superAdminHeaders(): array
    {
        $admin = $this->makeUser();
        $admin->forceFill(['is_super_admin' => true, 'is_active' => true])->save();

        return [
            'Authorization' => 'Bearer '.$admin->createToken('super-admin-test')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    private function plan(
        float $monthly = 20,
        float $yearly = 200,
        string $currency = 'USD',
    ): SubscriptionPlan {
        return SubscriptionPlan::create([
            'id' => UuidV7::generate(),
            'name' => 'Basic',
            'slug' => 'basic-'.UuidV7::generate(),
            'monthly_price' => $monthly,
            'yearly_price' => $yearly,
            'currency' => $currency,
            'trial_days' => 14,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ]);
    }

    private function subscription(
        Tenant $tenant,
        SubscriptionPlan $plan,
        string $status,
        string $cycle = 'monthly',
        array $overrides = [],
    ): TenantSubscription {
        $defaults = [
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'billing_cycle' => $cycle,
            'starts_at' => now()->subDay(),
            'ends_at' => $status === 'active' ? now()->addMonth() : null,
            'trial_ends_at' => $status === 'trialing' ? now()->addDays(7) : null,
            'auto_renew' => true,
        ];

        return TenantSubscription::withoutTenant()->create(array_merge($defaults, $overrides));
    }

    private function confirmedSubscriptionPayment(
        Tenant $tenant,
        TenantSubscription $subscription,
        float $amount,
        string $currency = 'USD',
        array $overrides = [],
    ): Payment {
        return Payment::withoutTenant()->create(array_merge([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'tenant_subscription_id' => $subscription->id,
            'transaction_id' => 'transaction-'.UuidV7::generate(),
            'gateway' => 'legacy',
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'completed',
            'payment_method' => 'card',
            'gateway_data' => [],
            'paid_at' => now(),
        ], $overrides));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Role::findOrCreate('Teacher', 'web');
    }

    public function test_dashboard_teachers_and_subscriptions_share_platform_wide_counts_with_or_without_tenant_header(): void
    {
        $trialTenant = $this->makeTenant();
        $expiredTenant = $this->makeTenant();
        $this->teacher($trialTenant);
        $this->teacher($expiredTenant);

        $plan = $this->plan();
        $trialEnd = now()->addDays(7);
        $this->subscription($trialTenant, $plan, 'trialing', overrides: ['trial_ends_at' => $trialEnd]);
        $this->subscription($expiredTenant, $plan, 'trialing', overrides: [
            'trial_ends_at' => now()->subDay(),
        ]);

        $headers = $this->superAdminHeaders();
        $withTenant = array_merge($headers, ['X-Tenant-Id' => $trialTenant->id]);

        $dashboardScoped = $this->getJson('/api/v1/dashboard', $withTenant)->assertOk();
        $dashboardGlobal = $this->getJson('/api/v1/dashboard', $headers)->assertOk();
        $teachersScoped = $this->getJson('/api/v1/admin/teachers', $withTenant)->assertOk();
        $subscriptionsScoped = $this->getJson('/api/v1/admin/subscriptions', $withTenant)->assertOk();

        $expected = [
            'total_teachers' => 2,
            'active_accounts' => 2,
            'valid_trials' => 1,
            'paid_subscribers' => 0,
            'admin_access' => 0,
            'expired_access' => 1,
            'effective_access' => 1,
            'pending_subscriptions' => 0,
            'historical_records' => 2,
        ];

        $dashboardMetrics = $dashboardGlobal->json('subscription_metrics');
        $this->assertSame($expected, array_intersect_key($dashboardMetrics, $expected));
        $this->assertSame($dashboardMetrics, $dashboardScoped->json('subscription_metrics'));
        $this->assertSame($dashboardMetrics, $teachersScoped->json('subscription_metrics'));
        $this->assertSame($dashboardMetrics, $subscriptionsScoped->json('subscription_metrics'));
        $this->assertCount(2, $subscriptionsScoped->json('subscriptions'));

        $trialList = $this->getJson('/api/v1/admin/teachers?subscription_state=valid_trial', $withTenant)
            ->assertOk()
            ->assertJsonCount(1, 'teachers');
        $trialSummary = $trialList->json('teachers.0.subscription_summary');
        $this->assertSame('valid_trial', $trialSummary['state']);
        $this->assertNotNull($trialSummary['trial_ends_at']);
        $this->assertEquals($trialEnd->getTimestamp(), Carbon::parse($trialSummary['trial_ends_at'])->getTimestamp());

        $this->getJson('/api/v1/admin/teachers?subscription_state=expired', $withTenant)
            ->assertOk()
            ->assertJsonCount(1, 'teachers');
    }

    public function test_an_enabled_teacher_without_payment_is_access_not_a_paid_subscriber(): void
    {
        $tenant = $this->makeTenant();
        $this->teacher($tenant);
        $this->subscription($tenant, $this->plan(), 'active');

        $metrics = app(SuperAdminStatisticsService::class)->snapshot()['subscription_metrics'];

        $this->assertSame(1, $metrics['total_teachers']);
        $this->assertSame(1, $metrics['active_accounts']);
        $this->assertSame(1, $metrics['effective_access']);
        $this->assertSame(0, $metrics['paid_subscribers']);
        $this->assertSame(1, $metrics['unverified_access']);
    }

    public function test_an_explicit_admin_grant_is_access_but_never_paid_mrr(): void
    {
        $tenant = $this->makeTenant();
        $this->teacher($tenant);
        $this->subscription($tenant, $this->plan(), 'expired', overrides: [
            'ends_at' => now()->subDay(),
            'trial_ends_at' => now()->subDay(),
            'admin_ends_at' => now()->addDays(10),
        ]);

        $metrics = app(SuperAdminStatisticsService::class)->snapshot()['subscription_metrics'];

        $this->assertSame(1, $metrics['admin_access']);
        $this->assertSame(1, $metrics['effective_access']);
        $this->assertSame(0, $metrics['paid_subscribers']);
        $this->assertSame(0.0, $metrics['financials']['mrr_by_currency']['USD'] ?? 0.0);
    }

    public function test_confirmed_legacy_monthly_and_annual_payments_determine_mrr_without_mixing_currencies(): void
    {
        config([
            'services.freemius.enabled' => true,
            'services.freemius.api_url' => 'https://api.freemius.test',
            'services.freemius.product_id' => '123',
            'services.freemius.api_token' => 'test-token',
            'services.freemius.secret_key' => 'test-secret',
        ]);
        PlatformSetting::set('freemius_enabled', true);
        PlatformSetting::set('freemius_product_id', '123');

        $monthlyTenant = $this->makeTenant();
        $annualTenant = $this->makeTenant();
        $this->teacher($monthlyTenant);
        $this->teacher($annualTenant);

        $monthlyPlan = $this->plan(monthly: 120, yearly: 1_200, currency: 'USD');
        $annualPlan = $this->plan(monthly: 25, yearly: 240, currency: 'USD');
        $monthlySubscription = $this->subscription($monthlyTenant, $monthlyPlan, 'active', 'monthly');
        $annualSubscription = $this->subscription($annualTenant, $annualPlan, 'active', 'yearly');
        $this->confirmedSubscriptionPayment($monthlyTenant, $monthlySubscription, 120);
        $this->confirmedSubscriptionPayment($annualTenant, $annualSubscription, 240);

        Http::fake(['https://api.freemius.test/*' => Http::response(['payments' => []])]);

        $metrics = app(SuperAdminStatisticsService::class)->snapshot()['subscription_metrics'];

        $this->assertSame(2, $metrics['paid_subscribers']);
        $this->assertEquals(140.0, $metrics['financials']['mrr_by_currency']['USD']);
        $this->assertEquals(360.0, $metrics['financials']['gross_collected_by_currency']['USD']);
    }

    public function test_freemius_entitlement_and_local_mirror_are_not_double_counted(): void
    {
        config([
            'services.freemius.enabled' => true,
            'services.freemius.api_url' => 'https://api.freemius.test',
            'services.freemius.product_id' => '123',
            'services.freemius.api_token' => 'test-token',
            'services.freemius.secret_key' => 'test-secret',
        ]);
        PlatformSetting::set('freemius_enabled', true);
        PlatformSetting::set('freemius_product_id', '123');

        Http::fake([
            'https://api.freemius.test/v1/products/123/payments.json*' => Http::response([
                'payments' => [
                    ['id' => 'fs-payment-1', 'type' => 'payment', 'currency' => 'usd', 'gross' => 50, 'gateway_fee' => 2, 'vat' => 0],
                    ['id' => 'fs-refund-1', 'type' => 'refund', 'currency' => 'usd', 'gross' => 10, 'gateway_fee' => 0, 'vat' => 0],
                ],
            ]),
        ]);

        $tenant = $this->makeTenant();
        $this->teacher($tenant);
        $plan = $this->plan(monthly: 50, yearly: 500);
        $licenseId = 'license-123';

        FsEntitlement::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'fs_license_id' => $licenseId,
            'fs_user_id' => 'provider-user-1',
            'fs_plan_id' => 'provider-plan-1',
            'fs_pricing_id' => 'provider-pricing-1',
            'fs_subscription_id' => 'provider-subscription-1',
            'plan_id' => $plan->id,
            'type' => 'subscription',
            'status' => 'active',
            'expiration' => now()->addMonth(),
            'is_canceled' => false,
            'synced_at' => now(),
            'metadata' => ['billing_cycle' => 'monthly', 'subscription_status' => 'active'],
        ]);

        $mirror = $this->subscription($tenant, $plan, 'active', overrides: [
            'billing_source' => 'freemius',
            'fs_license_id' => $licenseId,
            'fs_subscription_id' => 'provider-subscription-1',
        ]);
        $this->confirmedSubscriptionPayment($tenant, $mirror, 50, 'USD', [
            'gateway' => 'freemius',
            'fs_license_id' => $licenseId,
        ]);

        $metrics = app(SuperAdminStatisticsService::class)->snapshot()['subscription_metrics'];

        $this->assertSame(1, $metrics['paid_subscribers']);
        $this->assertEquals(50.0, $metrics['financials']['mrr_by_currency']['USD']);
        $this->assertEquals(50.0, $metrics['financials']['gross_collected_by_currency']['USD']);
        $this->assertEquals(10.0, $metrics['financials']['refunds_by_currency']['USD']);
        $this->assertEquals(40.0, $metrics['financials']['net_collected_by_currency']['USD']);
    }

    public function test_cancelled_status_filter_accepts_both_spellings_without_rewriting_records(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $plan = $this->plan();
        $a = $this->subscription($tenantA, $plan, 'cancelled');
        $b = $this->subscription($tenantB, $plan, 'canceled');
        $headers = $this->superAdminHeaders();

        $this->getJson('/api/v1/admin/subscriptions?status=canceled', $headers)
            ->assertOk()
            ->assertJsonCount(2, 'subscriptions');

        $this->assertSame('cancelled', $a->fresh()->status);
        $this->assertSame('canceled', $b->fresh()->status);
    }

    public function test_a_multi_workspace_teacher_is_counted_in_exactly_one_state(): void
    {
        // Same person owns a paid workspace and a trial workspace. They must
        // appear once — as a paid subscriber — never in both buckets.
        $paidTenant = $this->makeTenant();
        $trialTenant = $this->makeTenant();
        $teacher = $this->teacher($paidTenant);
        $this->attachToTenant($teacher, $trialTenant, 'owner');
        $trialTenant->update(['owner_id' => $teacher->id]);

        $plan = $this->plan();
        $paidSubscription = $this->subscription($paidTenant, $plan, 'active');
        $this->confirmedSubscriptionPayment($paidTenant, $paidSubscription, 20);
        $this->subscription($trialTenant, $plan, 'trialing', overrides: ['trial_ends_at' => now()->addDays(7)]);

        $metrics = app(SuperAdminStatisticsService::class)->snapshot()['subscription_metrics'];

        $this->assertSame(1, $metrics['total_teachers']);
        $this->assertSame(1, $metrics['paid_subscribers']);
        $this->assertSame(0, $metrics['valid_trials']);
        $this->assertSame(1, $metrics['effective_access']);
    }

    public function test_a_trial_workspace_outranks_an_expired_one_for_the_same_teacher(): void
    {
        $trialTenant = $this->makeTenant();
        $expiredTenant = $this->makeTenant();
        $teacher = $this->teacher($trialTenant);
        $this->attachToTenant($teacher, $expiredTenant, 'owner');
        $expiredTenant->update(['owner_id' => $teacher->id]);

        $plan = $this->plan();
        $this->subscription($trialTenant, $plan, 'trialing', overrides: ['trial_ends_at' => now()->addDays(7)]);
        $this->subscription($expiredTenant, $plan, 'trialing', overrides: ['trial_ends_at' => now()->subDay()]);

        $metrics = app(SuperAdminStatisticsService::class)->snapshot()['subscription_metrics'];

        $this->assertSame(1, $metrics['total_teachers']);
        $this->assertSame(1, $metrics['valid_trials']);
        $this->assertSame(0, $metrics['expired_access']);
        $this->assertSame(1, $metrics['effective_access']);
    }

    public function test_admin_payments_stats_keep_currencies_separate(): void
    {
        $tenant = $this->makeTenant();

        foreach ([
            ['amount' => 100, 'currency' => 'USD', 'status' => 'completed'],
            ['amount' => 50, 'currency' => 'JOD', 'status' => 'completed'],
            ['amount' => 25, 'currency' => 'USD', 'status' => 'refunded'],
        ] as $row) {
            Payment::withoutTenant()->create([
                'id' => UuidV7::generate(),
                'tenant_id' => $tenant->id,
                'transaction_id' => 'txn-'.UuidV7::generate(),
                'gateway' => 'legacy',
                'amount' => $row['amount'],
                'currency' => $row['currency'],
                'status' => $row['status'],
                'payment_method' => 'card',
                'gateway_data' => [],
                'paid_at' => now(),
            ]);
        }

        $res = $this->getJson('/api/v1/admin/payments', $this->superAdminHeaders())->assertOk();

        $this->assertSame(100.0, (float) $res->json('stats.revenue_by_currency.USD'));
        $this->assertSame(50.0, (float) $res->json('stats.revenue_by_currency.JOD'));
        // Legacy scalar is the platform currency only — never a mixed total.
        $this->assertSame(100.0, (float) $res->json('stats.revenue'));
        $this->assertSame(25.0, (float) $res->json('stats.refunded_total_by_currency.USD'));
    }

    public function test_teacher_show_card_stats_ignore_a_foreign_tenant_header(): void
    {
        $teacherTenant = $this->makeTenant();
        $otherTenant = $this->makeTenant();
        $teacher = $this->teacher($teacherTenant);

        $course = Course::withoutTenant()->create([
            'id' => UuidV7::generate(),
            'tenant_id' => $teacherTenant->id,
            'title' => 'Course',
            'slug' => 'course-'.UuidV7::generate(),
            'status' => 'published',
        ]);

        foreach (['generated', 'generated', 'redeemed'] as $i => $status) {
            ActivationCard::withoutTenant()->create([
                'id' => UuidV7::generate(),
                'tenant_id' => $teacherTenant->id,
                'course_id' => $course->id,
                'code' => 'CARD-'.UuidV7::generate(),
                'status' => $status,
                'metadata' => [],
            ]);
        }

        $headers = array_merge($this->superAdminHeaders(), ['X-Tenant-Id' => $otherTenant->id]);

        $res = $this->getJson('/api/v1/admin/teachers/'.$teacher->id, $headers)->assertOk();

        $this->assertSame(3, $res->json('stats.cards'));
        $this->assertSame(2, $res->json('stats.cards_available'));
        $this->assertSame(1, $res->json('stats.cards_redeemed'));
    }
}
