<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Plans\PlanEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Super Admin FREE-TRIAL extension.
 *
 * The brief: an admin must be able to extend the free trial of ONE teacher
 * only — quick options (1/2/7/14/30) or any positive number of days — with the
 * current and new trial end shown before saving. An active trial keeps its
 * remaining days; a lapsed trial starts from now and is re-activated. It must
 * never convert to paid, never create an invoice, never change the plan/limits,
 * never wipe teacher data, and never touch a paid subscription.
 */
class AdminTrialExtendTest extends TenantTestCase
{
    private function superAdmin(): User
    {
        $user = $this->makeUser();
        $user->forceFill(['is_super_admin' => true, 'is_active' => true])->save();

        return $user;
    }

    private function makePlan(): SubscriptionPlan
    {
        $plan = SubscriptionPlan::create([
            'id' => UuidV7::generate(),
            'name' => 'Basic',
            'slug' => 'basic-'.Str::random(6),
            'monthly_price' => 29.0,
            'yearly_price' => 290.0,
            'currency' => 'JOD',
            'trial_days' => 7,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ]);

        PlanFeature::create([
            'id' => UuidV7::generate(),
            'plan_id' => $plan->id,
            'feature_key' => 'courses_limit',
            'feature_value' => '100',
            'feature_type' => 'numeric',
            'sort_order' => 0,
        ]);

        return $plan;
    }

    private function teacherIn(Tenant $tenant): User
    {
        $user = $this->makeUser();
        $this->attachToTenant($user, $tenant, 'teacher');

        return $user;
    }

    private function trial(Tenant $tenant, SubscriptionPlan $plan, array $overrides = []): TenantSubscription
    {
        return TenantSubscription::withoutTenant()->create(array_merge([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subDay(),
            'trial_ends_at' => now()->addDays(5),
            'auto_renew' => true,
        ], $overrides));
    }

    private function assertApproxDaysFromNow(Carbon $date, float $expectedDays, string $msg = ''): void
    {
        $actual = now()->diffInSeconds($date, false) / 86400;
        $this->assertTrue(abs($actual - $expectedDays) < 0.05, $msg." (expected ~{$expectedDays}d, got {$actual}d)");
    }

    // ── 1. Active trial keeps remaining days; quick options work ─────

    public function test_extending_a_live_trial_adds_days_and_keeps_the_rest(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin();
        $sub = $this->trial($tenant, $plan, ['trial_ends_at' => now()->addDays(5)]);

        foreach ([1, 2, 7, 14, 30] as $extra) {
            $before = $sub->fresh()->trial_ends_at->copy();
            $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend-trial", ['days' => $extra], $this->authHeaders($admin, $tenant))
                ->assertOk()
                ->assertJsonPath('already_applied', false)
                ->assertJsonPath('reactivated', false);

            // Each extension is additive and preserves the remaining window.
            $expected = $before->copy()->addDays($extra);
            $this->assertApproxDaysFromNow($sub->fresh()->trial_ends_at, now()->diffInSeconds($expected, false) / 86400);
        }

        // Status stays "trialing" — no silent conversion to a paid plan.
        $this->assertSame('trialing', $sub->fresh()->status);
        $this->assertSame($plan->id, $sub->fresh()->plan_id);
        $this->assertDatabaseCount('invoices', 0);
    }

    // ── 2. Custom number of days ─────────────────────────────────────

    public function test_extending_by_a_custom_number_of_days(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin();
        $sub = $this->trial($tenant, $plan, ['trial_ends_at' => now()->addDays(3)]);

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend-trial", ['days' => 45], $this->authHeaders($admin, $tenant))
            ->assertOk();

        // 3 remaining + 45 = ~48 days.
        $this->assertApproxDaysFromNow($sub->fresh()->trial_ends_at, 48);
    }

    // ── 3. Lapsed trial starts from now and re-activates access ──────

    public function test_extending_a_lapsed_trial_reactivates_access_from_now(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();

        $sub = $this->trial($tenant, $plan, [
            'trial_ends_at' => now()->subDays(10),
            'starts_at' => now()->subDays(20),
        ]);

        // Baseline: the lapsed trial grants no access.
        $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant))->assertStatus(402);

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend-trial", ['days' => 30], $this->authHeaders($admin, $tenant))
            ->assertOk()
            ->assertJsonPath('reactivated', true);

        $sub->refresh();
        // Expired → the new window starts from today, not from the dead end.
        $this->assertApproxDaysFromNow($sub->trial_ends_at, 30);
        $this->assertSame('trialing', $sub->status);

        // Same session/token — access is live immediately, with no payment.
        $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant))->assertOk();
    }

    // ── 4. Days-left / trial-end are surfaced (fixes the "—" bug) ────

    public function test_teacher_detail_reports_trial_end_and_days_left(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();
        $this->trial($tenant, $plan, ['trial_ends_at' => now()->addDays(5)]);

        // AdminController::teacherShow resolves teachers through the spatie
        // role, which is seeded in production but not by the test migrator.
        \Spatie\Permission\Models\Role::findOrCreate('Teacher', 'web');
        $teacher->assignRole('Teacher');

        $res = $this->getJson("/api/v1/admin/teachers/{$teacher->id}", $this->authHeaders($admin, $tenant))
            ->assertOk()
            ->assertJsonPath('access.access_active', true)
            ->assertJsonPath('access.access_source', 'legacy')
            ->assertJsonPath('access.can_extend_trial', true)
            ->assertJsonPath('access.trial_active', true);

        // The trial end must be a REAL date, and days-left a REAL number —
        // never the "—" placeholder the UI used to show during a trial.
        $this->assertNotNull($res->json('access.trial_ends_at'));
        $this->assertNotNull($res->json('access.access_ends_at'));
        $this->assertNotNull($res->json('access.days_left'));
        $this->assertNotNull($res->json('access.trial_days_left'));
    }

    // ── 5. Paid subscriptions are never touched ──────────────────────

    public function test_a_paid_active_subscription_is_refused_and_unchanged(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin();

        $sub = TenantSubscription::withoutTenant()->create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'auto_renew' => true,
        ]);
        $endBefore = $sub->ends_at->toIso8601String();

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend-trial", ['days' => 30], $this->authHeaders($admin, $tenant))
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_a_trial');

        $sub->refresh();
        $this->assertNull($sub->trial_ends_at);
        $this->assertSame($endBefore, $sub->ends_at->toIso8601String());
        $this->assertNull($sub->admin_ends_at);
        $this->assertSame('active', $sub->status);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_a_provider_owned_row_is_refused_even_when_trialing(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin();

        $sub = $this->trial($tenant, $plan, [
            'billing_source' => 'freemius',
            'status' => 'trialing',
            'fs_license_id' => 'LIC-PAID',
        ]);

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend-trial", ['days' => 30], $this->authHeaders($admin, $tenant))
            ->assertStatus(422);

        // The provider-owned mirror keeps its original trial end.
        $this->assertTrue($sub->fresh()->trial_ends_at->lt(now()->addDays(5)->addMinute()));
    }

    public function test_a_canceled_paid_row_without_a_trial_window_is_refused(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin();

        $sub = TenantSubscription::withoutTenant()->create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'canceled',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subDays(5),
            'auto_renew' => false,
        ]);

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend-trial", ['days' => 30], $this->authHeaders($admin, $tenant))
            ->assertStatus(422);

        $sub->refresh();
        $this->assertNull($sub->trial_ends_at);
        $this->assertSame('canceled', $sub->status);
    }

    // ── 6. Teacher data (courses/plan) is preserved ──────────────────

    public function test_extension_never_wipes_teacher_content_or_changes_the_plan(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin();
        $sub = $this->trial($tenant, $plan);

        $course = Course::factory()->create(['tenant_id' => $tenant->id]);
        $coursesBefore = Course::withoutTenant()->where('tenant_id', $tenant->id)->count();

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend-trial", ['days' => 7], $this->authHeaders($admin, $tenant))
            ->assertOk();

        $this->assertSame($plan->id, $sub->fresh()->plan_id);
        $this->assertSame($coursesBefore, Course::withoutTenant()->where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseHas('courses', ['id' => $course->id, 'tenant_id' => $tenant->id]);
    }

    // ── 7. Idempotency ───────────────────────────────────────────────

    public function test_repeated_request_id_does_not_double_extend(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin();
        $sub = $this->trial($tenant, $plan, ['trial_ends_at' => now()->addDays(5)]);
        $rid = 'req-'.Str::random(12);

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend-trial", ['days' => 30, 'request_id' => $rid], $this->authHeaders($admin, $tenant))
            ->assertOk();
        $afterFirst = $sub->fresh()->trial_ends_at->toIso8601String();

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend-trial", ['days' => 30, 'request_id' => $rid], $this->authHeaders($admin, $tenant))
            ->assertOk()
            ->assertJsonPath('already_applied', true);

        $this->assertSame($afterFirst, $sub->fresh()->trial_ends_at->toIso8601String());
    }

    public function test_double_submit_without_request_id_is_cooldown_guarded(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin();
        $sub = $this->trial($tenant, $plan);

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend-trial", ['days' => 14], $this->authHeaders($admin, $tenant))->assertOk();
        $afterFirst = $sub->fresh()->trial_ends_at->toIso8601String();

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend-trial", ['days' => 14], $this->authHeaders($admin, $tenant))
            ->assertOk()
            ->assertJsonPath('already_applied', true);

        $this->assertSame($afterFirst, $sub->fresh()->trial_ends_at->toIso8601String());
    }

    // ── 8. Authorization ─────────────────────────────────────────────

    public function test_non_admin_cannot_extend_a_trial(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $sub = $this->attachSubscription($tenant, 'trialing');

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend-trial", ['days' => 30], $this->authHeaders($teacher, $tenant))
            ->assertStatus(403);

        // The trial end is untouched by the unauthorized attempt.
        $this->assertTrue($sub->fresh()->trial_ends_at->between(now()->addDays(6), now()->addDays(8)));
    }

    // ── 9. Isolation: only the intended teacher changes ──────────────

    public function test_extension_only_affects_the_intended_teacher(): void
    {
        $plan = $this->makePlan();
        $admin = $this->superAdmin();

        $tenantA = $this->makeTenant();
        $teacherA = $this->teacherIn($tenantA);
        $subA = $this->trial($tenantA, $plan, ['trial_ends_at' => now()->addDays(2)]);

        $tenantB = $this->makeTenant();
        $teacherB = $this->teacherIn($tenantB);
        $subB = $this->trial($tenantB, $plan, ['trial_ends_at' => now()->addDays(2)]);
        $endB = $subB->trial_ends_at->toIso8601String();

        $this->postJson("/api/v1/admin/subscriptions/{$subA->id}/extend-trial", ['days' => 30], $this->authHeaders($admin, $tenantA))
            ->assertOk();

        $this->assertApproxDaysFromNow($subA->fresh()->trial_ends_at, 32);
        $this->assertSame($endB, $subB->fresh()->trial_ends_at->toIso8601String());
    }

    // ── 10. Audit trail ──────────────────────────────────────────────

    public function test_trial_extension_is_audit_logged_with_full_details(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin();
        $sub = $this->trial($tenant, $plan, ['trial_ends_at' => now()->addDays(5)]);
        $previous = $sub->trial_ends_at->toIso8601String();

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend-trial", [
            'days' => 7,
            'reason' => 'Goodwill — teacher onboarding week',
        ], $this->authHeaders($admin, $tenant))->assertOk();

        $log = \App\Models\AuditLog::where('action', 'admin.subscription_trial_extended')
            ->where('entity_id', $sub->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame($tenant->id, $log->details['tenant_id']);
        $this->assertSame(7, $log->details['days']);
        $this->assertSame($previous, $log->details['previous_trial_ends_at']);
        $this->assertNotSame($previous, $log->details['new_trial_ends_at']);
        $this->assertSame('Goodwill — teacher onboarding week', $log->details['reason']);
    }

    // ── 11. Validation ───────────────────────────────────────────────

    public function test_days_must_be_a_positive_integer(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin();
        $sub = $this->trial($tenant, $plan);

        foreach ([0, -3, 'abc'] as $bad) {
            $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend-trial", ['days' => $bad], $this->authHeaders($admin, $tenant))
                ->assertStatus(422);
        }
    }

    // ── 12. The trial really ends when the extended date elapses ─────

    public function test_access_ends_when_the_extended_trial_elapses(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();
        $sub = $this->trial($tenant, $plan, ['trial_ends_at' => now()->addDays(1)]);

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend-trial", ['days' => 7], $this->authHeaders($admin, $tenant))->assertOk();
        $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant))->assertOk();

        // Time passes beyond the extended trial end.
        $sub->forceFill(['trial_ends_at' => now()->subDay()])->save();
        PlanEngine::bumpVersion();

        $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant))->assertStatus(402);
    }

    // ── 13. The empty admin tenant header must not abort the trial extend ──

    public function test_trial_extension_survives_the_empty_tenant_header_the_panel_sends(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin();
        $sub = $this->trial($tenant, $plan, ['trial_ends_at' => now()->addDays(5)]);
        $previous = $sub->trial_ends_at->toIso8601String();

        // Exactly what the admin panel sends for a platform-wide Super Admin
        // action: an empty X-Tenant-Id. On Postgres an empty uuid aborts the
        // surrounding transaction, and the follow-up `fresh()` used to 500 with
        // "Server Error" even though the row was already updated.
        $headers = $this->authHeaders($admin, $tenant);
        $headers['X-Tenant-Id'] = '';

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend-trial", [
            'days' => 7,
            'reason' => 'Goodwill — teacher onboarding week',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('already_applied', false);

        $this->assertApproxDaysFromNow($sub->fresh()->trial_ends_at, 12);

        // The audit row exists and never carries an empty/invalid tenant id.
        $log = \App\Models\AuditLog::where('action', 'admin.subscription_trial_extended')
            ->where('entity_id', $sub->id)
            ->first();
        $this->assertNotNull($log);
        $this->assertSame($previous, $log->details['previous_trial_ends_at'] ?? null);
        $this->assertSame($tenant->id, $log->details['tenant_id']);
        $this->assertTrue(
            $log->tenant_id === null || \Symfony\Component\Uid\Uuid::isValid($log->tenant_id),
            'tenant_id must be NULL or a valid UUID, never an empty string.'
        );
    }
}
