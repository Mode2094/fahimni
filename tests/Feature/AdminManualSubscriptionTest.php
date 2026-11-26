<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Invoice;
use App\Models\ManualSubscriptionOperation;
use App\Models\Payment;
use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\Transaction;
use App\Models\User;
use App\Plans\PlanEngine;
use App\Plans\QuotaService;
use App\Plans\Exceptions\QuotaExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Super-Admin MANUAL subscription management (CliQ / bank / cash / comp).
 *
 * Covers the whole brief: activation during a trial, CliQ payment capture,
 * renewal (remaining days preserved), mid-cycle upgrade, downgrade with a
 * safe non-destructive limit policy, duplicate prevention, authorization,
 * expiry and immediate quota/feature enforcement.
 */
class AdminManualSubscriptionTest extends TenantTestCase
{
    private function superAdmin(): User
    {
        $user = $this->makeUser();
        $user->forceFill(['is_super_admin' => true, 'is_active' => true])->save();

        return $user;
    }

    private function makePlan(array $features = [], array $overrides = []): SubscriptionPlan
    {
        $plan = SubscriptionPlan::create(array_merge([
            'id' => (string) Str::uuid(),
            'name' => 'Plan '.Str::random(5),
            'slug' => 'plan-'.Str::random(6),
            'monthly_price' => 59.00,
            'yearly_price' => 590.00,
            'currency' => 'USD',
            'trial_days' => 7,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ], $overrides));

        $features = array_merge([
            'students_limit' => '250',
            'courses_limit' => '20',
            'videos_limit' => '100',
            'storage_limit_mb' => '15360',
            'live_stream_enabled' => 'true',
            'question_bank_enabled' => 'true',
            'certificates_enabled' => 'true',
        ], $features);

        foreach ($features as $key => $value) {
            PlanFeature::create([
                'id' => (string) Str::uuid(),
                'plan_id' => $plan->id,
                'feature_key' => $key,
                'feature_value' => $value,
                'feature_type' => 'string',
                'sort_order' => 0,
            ]);
        }

        return $plan;
    }

    private function teacherIn(Tenant $tenant): User
    {
        Role::findOrCreate('Teacher', 'web');

        // A teacher must carry a phone (the profile middleware blocks the
        // teacher workspace otherwise).
        $user = $this->makeUser(['phone' => '+962790000000']);
        $this->attachToTenant($user, $tenant, 'teacher');
        $user->assignRole('Teacher');

        return $user;
    }

    private function legacySubscription(Tenant $tenant, SubscriptionPlan $plan, string $status, array $overrides = []): TenantSubscription
    {
        return TenantSubscription::withoutTenant()->create(array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subDays(20),
            'ends_at' => now()->addDays(10),
            'auto_renew' => true,
            'metadata' => [],
        ], $overrides));
    }

    private function payload(SubscriptionPlan $plan, array $overrides = []): array
    {
        return array_merge([
            'plan_id' => $plan->id,
            'operation_type' => 'activate',
            'billing_cycle' => 'monthly',
            'duration_value' => 1,
            'duration_unit' => 'month',
            'payment_method' => 'cliq',
            'amount' => 59.00,
            'currency' => 'USD',
            'payment_reference' => 'CLIQ-REF-'.Str::random(6),
            'payment_date' => now()->toDateString(),
            'notes' => 'Verified on CliQ app',
            'confirm_payment_received' => true,
            'request_id' => 'req-'.Str::random(12),
        ], $overrides);
    }

    private function daysFromNow(Carbon $date): float
    {
        return now()->diffInSeconds($date, false) / 86400;
    }

    private function assertApproxDays(Carbon $date, float $expected, string $msg = ''): void
    {
        $actual = $this->daysFromNow($date);
        $this->assertTrue(abs($actual - $expected) < 0.2, $msg." (expected ~{$expected}d, got {$actual}d)");
    }

    // ── 1. Activation during a free trial (CliQ, paid) ───────────────

    public function test_activation_during_trial_moves_to_paid_plan_and_records_cliq_payment(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();

        $this->attachSubscription($tenant, 'trialing'); // free trial
        $plan = $this->makePlan();

        $res = $this->postJson(
            "/api/v1/admin/teachers/{$teacher->id}/subscription/manual",
            $this->payload($plan, ['payment_reference' => 'CLIQ-998877']),
            $this->authHeaders($admin, $tenant),
        );

        $res->assertOk()->assertJsonPath('already_applied', false);

        $sub = TenantSubscription::withoutTenant()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('active', $sub->status);
        $this->assertSame($plan->id, $sub->plan_id);
        $this->assertNull($sub->trial_ends_at);
        $this->assertNotNull($sub->admin_ends_at);
        $this->assertApproxDays(Carbon::instance($sub->ends_at), 30, 'paid period should be ~1 month');

        // Accounting is real and linked.
        $payment = Payment::withoutTenant()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('manual', $payment->gateway);
        $this->assertSame('completed', $payment->status);
        $this->assertSame('cliq', $payment->payment_method);
        $this->assertSame('CLIQ-998877', $payment->gateway_data['cliq_reference']);
        $this->assertSame($sub->id, $payment->tenant_subscription_id);

        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(
            $sub->id,
            Invoice::withoutTenant()->where('tenant_id', $tenant->id)->value('tenant_subscription_id'),
        );

        $this->assertDatabaseHas('manual_subscription_operations', [
            'tenant_id' => $tenant->id,
            'operation_type' => 'activate',
            'performed_by' => $admin->id,
        ]);

        // Immediate access + no payment prompt.
        $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant))->assertOk();
        $this->getJson('/api/v1/teacher/plan', $this->authHeaders($teacher, $tenant))
            ->assertOk()->assertJsonPath('active', true);
    }

    // ── 2. A free grant records NO revenue ───────────────────────────

    public function test_free_grant_creates_no_payment_or_revenue(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();
        $plan = $this->makePlan();

        $this->postJson(
            "/api/v1/admin/teachers/{$teacher->id}/subscription/manual",
            $this->payload($plan, [
                'operation_type' => 'free_grant',
                'amount' => 0,
                'payment_method' => null,
                'payment_reference' => null,
                'confirm_payment_received' => false,
            ]),
            $this->authHeaders($admin, $tenant),
        )->assertOk();

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('invoices', 0);

        $op = ManualSubscriptionOperation::withoutTenant()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('free_grant', $op->operation_type);
        $this->assertNull($op->payment_id);

        // Access is granted immediately even though nothing was charged.
        $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant))->assertOk();
    }

    // ── 3. Renewal preserves remaining paid days ─────────────────────

    public function test_renewal_preserves_remaining_paid_days(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();
        $plan = $this->makePlan();

        $sub = $this->legacySubscription($tenant, $plan, 'active', ['ends_at' => now()->addDays(10)]);

        $this->postJson(
            "/api/v1/admin/teachers/{$teacher->id}/subscription/manual",
            $this->payload($plan, ['operation_type' => 'renew']),
            $this->authHeaders($admin, $tenant),
        )->assertOk();

        // 10 remaining + 30 = ~40 days, nothing lost.
        $this->assertApproxDays(Carbon::instance($sub->fresh()->ends_at), 40);
        $this->assertTrue(
            (bool) ManualSubscriptionOperation::withoutTenant()
                ->where('tenant_id', $tenant->id)->firstOrFail()->metadata['remaining_days_preserved'],
        );
    }

    // ── 4. Mid-cycle upgrade keeps the paid window ───────────────────

    public function test_upgrade_mid_cycle_keeps_the_paid_window(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();

        $basic = $this->makePlan(['courses_limit' => '3'], ['slug' => 'basic-'.Str::random(4)]);
        $pro = $this->makePlan(['courses_limit' => '20'], ['slug' => 'pro-'.Str::random(4)]);

        $sub = $this->legacySubscription($tenant, $basic, 'active', [
            'ends_at' => now()->addDays(12),
        ]);
        $originalEnd = $sub->ends_at->toIso8601String();

        $this->postJson(
            "/api/v1/admin/teachers/{$teacher->id}/subscription/manual",
            $this->payload($pro, [
                'operation_type' => 'upgrade',
                'duration_value' => null,
                'duration_unit' => null,
            ]),
            $this->authHeaders($admin, $tenant),
        )->assertOk();

        $sub->refresh();
        $this->assertSame($pro->id, $sub->plan_id);
        // No silent period restart: the end is unchanged.
        $this->assertSame($originalEnd, $sub->ends_at->toIso8601String());

        // New plan limits apply immediately.
        $this->assertSame(20, app(PlanEngine::class)->limit($tenant->id, 'courses_limit'));
    }

    // ── 5. Downgrade keeps content + warns about over-limit usage ────

    public function test_downgrade_never_deletes_content_and_warns(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();

        $pro = $this->makePlan(['courses_limit' => '20'], ['slug' => 'pro-'.Str::random(4)]);
        $basic = $this->makePlan(['courses_limit' => '1'], ['slug' => 'basic-'.Str::random(4)]);

        $this->legacySubscription($tenant, $pro, 'active', ['ends_at' => now()->addDays(15)]);

        // The teacher already has 3 courses (over the new plan's limit of 1).
        foreach (range(1, 3) as $i) {
            Course::create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'title' => "Course {$i}",
                'slug' => 'course-'.$i.'-'.Str::random(4),
                'is_published' => true,
            ]);
        }

        // Preview must surface the safe-limit warning without deleting anything.
        $preview = $this->postJson(
            "/api/v1/admin/teachers/{$teacher->id}/subscription/preview",
            $this->payload($basic, ['operation_type' => 'downgrade', 'request_id' => null]),
            $this->authHeaders($admin, $tenant),
        )->assertOk()->json('preview');

        $this->assertNotEmpty($preview['warnings']);
        $this->assertSame('courses', $preview['warnings'][0]['resource']);
        $this->assertSame(3, $preview['warnings'][0]['used']);

        $this->postJson(
            "/api/v1/admin/teachers/{$teacher->id}/subscription/manual",
            $this->payload($basic, ['operation_type' => 'downgrade']),
            $this->authHeaders($admin, $tenant),
        )->assertOk();

        // All three courses still exist — nothing is zeroed or deleted.
        $this->assertSame(3, Course::withoutTenant()->where('tenant_id', $tenant->id)->count());
        $this->assertSame($basic->id, TenantSubscription::withoutTenant()->where('tenant_id', $tenant->id)->first()->plan_id);

        // The new limit is enforced for NEW creations only.
        $this->expectException(QuotaExceededException::class);
        app(QuotaService::class)->assertCan($tenant->id, 'courses');
    }

    // ── 6. Duplicate prevention (idempotent request_id) ──────────────

    public function test_duplicate_submit_does_not_double_activate(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();
        $plan = $this->makePlan();

        $payload = $this->payload($plan, ['request_id' => 'fixed-request-1']);

        $this->postJson("/api/v1/admin/teachers/{$teacher->id}/subscription/manual", $payload, $this->authHeaders($admin, $tenant))
            ->assertOk()->assertJsonPath('already_applied', false);

        $firstEnd = TenantSubscription::withoutTenant()->where('tenant_id', $tenant->id)->firstOrFail()->ends_at->toIso8601String();

        $second = $this->postJson("/api/v1/admin/teachers/{$teacher->id}/subscription/manual", $payload, $this->authHeaders($admin, $tenant))
            ->assertOk()->assertJsonPath('already_applied', true);

        $this->assertSame(
            $firstEnd,
            TenantSubscription::withoutTenant()->where('tenant_id', $tenant->id)->firstOrFail()->ends_at->toIso8601String(),
        );
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('manual_subscription_operations', 1);
        $this->assertNotNull($second->json('operation.id'));
    }

    // ── 7. Authorization: only super admins ──────────────────────────

    public function test_non_admin_cannot_manage_manual_subscriptions(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $plan = $this->makePlan();

        $this->attachSubscription($tenant, 'trialing');

        $this->postJson(
            "/api/v1/admin/teachers/{$teacher->id}/subscription/manual",
            $this->payload($plan),
            $this->authHeaders($teacher, $tenant),
        )->assertStatus(403);

        $this->getJson("/api/v1/admin/teachers/{$teacher->id}/subscription/history", $this->authHeaders($teacher, $tenant))
            ->assertStatus(403);

        $this->assertDatabaseCount('manual_subscription_operations', 0);
    }

    // ── 8. Paid operation requires verified payment confirmation ─────

    public function test_paid_operation_requires_confirmation_and_method(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();
        $plan = $this->makePlan();
        $this->attachSubscription($tenant, 'trialing');

        // No confirmation → rejected, nothing written.
        $this->postJson(
            "/api/v1/admin/teachers/{$teacher->id}/subscription/manual",
            $this->payload($plan, ['confirm_payment_received' => false]),
            $this->authHeaders($admin, $tenant),
        )->assertStatus(422)->assertJsonValidationErrors('confirm_payment_received');

        $this->assertDatabaseCount('manual_subscription_operations', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    // ── 9. Access really ends when the manual window lapses ──────────

    public function test_access_ends_when_the_manual_period_expires(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();
        $plan = $this->makePlan();
        $this->attachSubscription($tenant, 'trialing');

        $this->postJson(
            "/api/v1/admin/teachers/{$teacher->id}/subscription/manual",
            $this->payload($plan, ['duration_value' => 1, 'duration_unit' => 'day']),
            $this->authHeaders($admin, $tenant),
        )->assertOk();

        $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant))->assertOk();

        // Time passes beyond both the legacy end and the admin grant.
        TenantSubscription::withoutTenant()->where('tenant_id', $tenant->id)
            ->update(['ends_at' => now()->subDay(), 'admin_ends_at' => now()->subDay()]);
        PlanEngine::bumpVersion();

        $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant))->assertStatus(402);
    }

    // ── 10. History endpoint returns the audit trail ─────────────────

    public function test_history_lists_past_operations(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();
        $plan = $this->makePlan();

        $this->postJson(
            "/api/v1/admin/teachers/{$teacher->id}/subscription/manual",
            $this->payload($plan),
            $this->authHeaders($admin, $tenant),
        )->assertOk();

        $this->getJson("/api/v1/admin/teachers/{$teacher->id}/subscription/history", $this->authHeaders($admin, $tenant))
            ->assertOk()
            ->assertJsonPath('operations.0.operation_type', 'activate')
            ->assertJsonPath('operations.0.payment_reference', fn ($v) => is_string($v) && str_starts_with($v, 'CLIQ-REF-'))
            ->assertJsonPath('operations.0.performed_by_name', fn ($v) => $v === $admin->name);
    }

    // ── 11. CliQ reference shown on the payment list ─────────────────

    public function test_cliq_reference_is_surfaced_in_the_admin_payments_list(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();
        $plan = $this->makePlan();

        $this->postJson(
            "/api/v1/admin/teachers/{$teacher->id}/subscription/manual",
            $this->payload($plan, ['payment_reference' => 'CLIQ-LIST-42']),
            $this->authHeaders($admin, $tenant),
        )->assertOk();

        $this->getJson('/api/v1/admin/payments?gateway=manual', $this->authHeaders($admin, $tenant))
            ->assertOk()
            ->assertJsonPath('payments.0.transaction_id', 'CLIQ-LIST-42')
            ->assertJsonPath('payments.0.status', 'completed');
    }
}
