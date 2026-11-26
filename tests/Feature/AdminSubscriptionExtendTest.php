<?php

namespace Tests\Feature;

use App\Models\FsEntitlement;
use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Plans\PlanEngine;
use App\Services\FsEntitlementSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

/**
 * Super Admin "extend subscription" — the administrative access grant.
 *
 * The brief: a valid extension must make the teacher ACTIVE immediately and
 * keep them out of the payment page, whatever the billing provider reports,
 * without ever faking a payment/invoice and without losing paid days.
 *
 * These tests fail on the old implementation (which only rewrote the local
 * row's `status`/`ends_at` — ignored by the access resolver when the row was
 * Freemius-owned, and with no cache invalidation) and pass on the fix.
 */
class AdminSubscriptionExtendTest extends TenantTestCase
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

    /**
     * The exact production shape of the broken row: a Freemius-owned local
     * mirror that is ACTIVE with a future end but carries no bound license —
     * so the access resolver ignores it and the teacher is sent to /pricing.
     */
    private function freemiusMirror(Tenant $tenant, SubscriptionPlan $plan, array $overrides = []): TenantSubscription
    {
        return TenantSubscription::withoutTenant()->create(array_merge([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_source' => 'freemius',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'auto_renew' => true,
        ], $overrides));
    }

    private function assertApproxDaysFromNow(Carbon $date, float $expectedDays, string $msg = ''): void
    {
        $actual = now()->diffInSeconds($date, false) / 86400;
        $this->assertTrue(abs($actual - $expectedDays) < 0.05, $msg." (expected ~{$expectedDays}d, got {$actual}d)");
    }

    // ── 1. The real production bug ───────────────────────────────────

    public function test_extending_a_freemius_mirror_grants_immediate_access_without_faking_payment(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();

        $sub = $this->freemiusMirror($tenant, $plan);

        // Baseline: this exact row grants NO access through the gate.
        $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant))->assertStatus(402);

        $res = $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend", [
            'days' => 30,
        ], $this->authHeaders($admin, $tenant));

        $res->assertOk()
            ->assertJsonPath('already_applied', false);
        $this->assertNotNull($res->json('access_ends_at'));

        $sub->refresh();
        $this->assertNotNull($sub->admin_ends_at);
        $this->assertApproxDaysFromNow($sub->admin_ends_at, 30);

        // The teacher is immediately active — same session/token, no payment.
        $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant))->assertOk();
        $this->getJson('/api/v1/teacher/plan', $this->authHeaders($teacher, $tenant))
            ->assertOk()
            ->assertJsonPath('active', true);

        // Payment state is NOT faked: the row keeps its original status and no
        // invoice/payment row is invented.
        $this->assertSame('active', $sub->status);
        $this->assertDatabaseCount('invoices', 0);
    }

    // ── 2. Expired subscription starts from today ────────────────────

    public function test_extending_an_expired_subscription_starts_today_and_keeps_honest_status(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();

        $sub = TenantSubscription::withoutTenant()->create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'expired',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subDays(40),
            'ends_at' => now()->subDays(10),
            'auto_renew' => false,
        ]);

        $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant))->assertStatus(402);

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend", ['days' => 30], $this->authHeaders($admin, $tenant))
            ->assertOk();

        $sub->refresh();
        // Expired → the new window starts from today, not from the dead end.
        $this->assertApproxDaysFromNow($sub->admin_ends_at, 30);
        // The payment status is left truthful (never flipped to "active").
        $this->assertSame('expired', $sub->status);

        $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant))->assertOk();
    }

    // ── 3. In-term extension keeps remaining days ────────────────────

    public function test_extending_an_active_subscription_keeps_remaining_days(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();

        // Legacy (non-Freemius) active row with 10 days left.
        $sub = TenantSubscription::withoutTenant()->create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subDays(20),
            'ends_at' => now()->addDays(10),
            'auto_renew' => true,
        ]);

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend", ['days' => 30], $this->authHeaders($admin, $tenant))
            ->assertOk();

        $sub->refresh();
        // 10 remaining + 30 added = ~40 days, no days lost.
        $this->assertApproxDaysFromNow($sub->admin_ends_at, 40);
        $this->assertApproxDaysFromNow($sub->ends_at, 40);
    }

    // ── 4. Trial extension keeps the trial's remaining days ──────────

    public function test_extending_a_trial_keeps_the_remaining_trial_days(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();

        $sub = TenantSubscription::withoutTenant()->create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->subDays(2),
            'trial_ends_at' => now()->addDays(5),
            'auto_renew' => true,
        ]);

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend", ['days' => 30], $this->authHeaders($admin, $tenant))
            ->assertOk();

        $sub->refresh();
        // 5 remaining trial days + 30 = ~35 days.
        $this->assertApproxDaysFromNow($sub->admin_ends_at, 35);
    }

    // ── 5. Idempotency: repeated request_id ──────────────────────────

    public function test_repeated_request_id_does_not_double_extend(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin();
        $sub = $this->freemiusMirror($tenant, $plan);

        $rid = 'req-'.Str::random(12);

        $first = $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend", [
            'days' => 30, 'request_id' => $rid,
        ], $this->authHeaders($admin, $tenant))->assertOk();

        $afterFirst = $sub->fresh()->admin_ends_at->toIso8601String();

        $second = $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend", [
            'days' => 30, 'request_id' => $rid,
        ], $this->authHeaders($admin, $tenant))->assertOk();

        $second->assertJsonPath('already_applied', true);
        $this->assertSame($afterFirst, $sub->fresh()->admin_ends_at->toIso8601String());
        $this->assertNotSame($first->json('access_ends_at'), null);
    }

    // ── 6. Idempotency: no request_id (cooldown) ─────────────────────

    public function test_double_submit_without_request_id_is_cooldown_guarded(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin();
        $sub = $this->freemiusMirror($tenant, $plan);

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend", ['days' => 30], $this->authHeaders($admin, $tenant))->assertOk();
        $afterFirst = $sub->fresh()->admin_ends_at->toIso8601String();

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend", ['days' => 30], $this->authHeaders($admin, $tenant))
            ->assertOk()
            ->assertJsonPath('already_applied', true);

        $this->assertSame($afterFirst, $sub->fresh()->admin_ends_at->toIso8601String());
    }

    // ── 7. Authorization ─────────────────────────────────────────────

    public function test_non_admin_cannot_extend(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);

        // The tenant has an active subscription, so the request clears the
        // subscription gate and is stopped by authorization alone.
        $sub = $this->attachSubscription($tenant, 'trialing');

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend", ['days' => 30], $this->authHeaders($teacher, $tenant))
            ->assertStatus(403);

        $this->assertNull($sub->fresh()->admin_ends_at);
    }

    // ── 8. Isolation: another tenant is untouched ────────────────────

    public function test_extension_only_affects_the_intended_tenant(): void
    {
        $plan = $this->makePlan();
        $admin = $this->superAdmin();

        $tenantA = $this->makeTenant();
        $teacherA = $this->teacherIn($tenantA);
        $subA = $this->freemiusMirror($tenantA, $plan);

        $tenantB = $this->makeTenant();
        $teacherB = $this->teacherIn($tenantB);
        $subB = $this->freemiusMirror($tenantB, $plan);

        $this->postJson("/api/v1/admin/subscriptions/{$subA->id}/extend", ['days' => 30], $this->authHeaders($admin, $tenantA))
            ->assertOk();

        $this->assertNotNull($subA->fresh()->admin_ends_at);
        $this->assertNull($subB->fresh()->admin_ends_at);

        $this->getJson('/api/v1/courses', $this->authHeaders($teacherA, $tenantA))->assertOk();
        $this->getJson('/api/v1/courses', $this->authHeaders($teacherB, $tenantB))->assertStatus(402);
    }

    // ── 9. Audit trail ───────────────────────────────────────────────

    public function test_extension_is_audit_logged_with_full_details(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin();
        $sub = $this->freemiusMirror($tenant, $plan);

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend", [
            'days' => 30,
            'reason' => 'Goodwill for outage',
        ], $this->authHeaders($admin, $tenant))->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.subscription_extended',
            'entity_id' => $sub->id,
        ]);
    }

    // ── 10. The grant really ends when admin_ends_at elapses ─────────

    public function test_access_ends_when_the_admin_grant_expires(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();
        $sub = $this->freemiusMirror($tenant, $plan);

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend", ['days' => 30], $this->authHeaders($admin, $tenant))->assertOk();
        $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant))->assertOk();

        // Time passes beyond the grant (only the admin field moves).
        $sub->forceFill(['admin_ends_at' => now()->subDay()])->save();
        PlanEngine::bumpVersion();

        $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant))->assertStatus(402);
    }

    // ── 11. A stale Freemius webhook must not revoke the grant ───────

    public function test_freemius_license_deletion_does_not_revoke_admin_access(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();

        $sub = $this->freemiusMirror($tenant, $plan, ['fs_license_id' => 'LIC-ADMIN']);

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend", ['days' => 30], $this->authHeaders($admin, $tenant))->assertOk();
        $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant))->assertOk();

        // The provider (later, or out of order) deletes the license.
        app(FsEntitlementSyncService::class)->deleteByLicenseId('LIC-ADMIN');

        // The paid mirror is deactivated, but the administrative access stands.
        $this->assertSame('expired', $sub->fresh()->status);
        $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant))->assertOk();
    }

    // ── 12. Super Admin UI data: honest access summary ───────────────

    public function test_admin_teacher_detail_reports_admin_extension_and_access(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $admin = $this->superAdmin();
        $sub = $this->freemiusMirror($tenant, $plan);

        // AdminController::teacherShow looks teachers up through the spatie
        // role, which is seeded in production but not by the test migrator.
        \Spatie\Permission\Models\Role::findOrCreate('Teacher', 'web');
        $teacher->assignRole('Teacher');

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend", ['days' => 30], $this->authHeaders($admin, $tenant))->assertOk();

        $this->getJson("/api/v1/admin/teachers/{$teacher->id}", $this->authHeaders($admin, $tenant))
            ->assertOk()
            ->assertJsonPath('access.access_active', true)
            ->assertJsonPath('access.admin_extension', true)
            ->assertJsonPath('access.admin_grant_days', 30)
            ->assertJsonPath('access.access_source', 'admin');

        $this->assertNotNull($this->getJson("/api/v1/admin/teachers/{$teacher->id}", $this->authHeaders($admin, $tenant))->json('access.days_left'));
    }

    // ── 13. A paid Freemius entitlement alone must pass the gate ─────

    public function test_freemius_entitlement_alone_passes_the_subscription_gate(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);

        FsEntitlement::create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'fs_license_id' => 'LIC-PAID',
            'plan_id' => $plan->id,
            'type' => 'subscription',
            'status' => 'active',
            'expiration' => now()->addMonth(),
            'is_canceled' => false,
        ]);
        PlanEngine::bumpVersion();

        // A genuinely paid teacher must never be sent to /pricing.
        $this->getJson('/api/v1/courses', $this->authHeaders($teacher, $tenant))->assertOk();
        $this->getJson('/api/v1/teacher/plan', $this->authHeaders($teacher, $tenant))
            ->assertOk()
            ->assertJsonPath('active', true);
    }

    // ── 14. The empty admin tenant header must not abort the extension ──

    public function test_extension_survives_the_empty_tenant_header_the_panel_sends(): void
    {
        $plan = $this->makePlan();
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin();
        $sub = $this->freemiusMirror($tenant, $plan);

        // Exactly what the admin panel sends: an empty X-Tenant-Id for a
        // platform-wide Super Admin action. On Postgres an empty uuid aborts
        // the surrounding transaction, and the follow-up `fresh()` used to 500.
        $headers = $this->authHeaders($admin, $tenant);
        $headers['X-Tenant-Id'] = '';

        $this->postJson("/api/v1/admin/subscriptions/{$sub->id}/extend", ['days' => 30], $headers)
            ->assertOk()
            ->assertJsonPath('already_applied', false);

        $this->assertNotNull($sub->fresh()->admin_ends_at);

        // The audit row exists; its details name the real tenant, and the
        // tenant_id column is never an empty/invalid uuid.
        $log = \App\Models\AuditLog::where('action', 'admin.subscription_extended')
            ->where('entity_id', $sub->id)
            ->first();
        $this->assertNotNull($log);
        $this->assertSame($tenant->id, $log->details['tenant_id']);
        $this->assertTrue(
            $log->tenant_id === null || \Symfony\Component\Uid\Uuid::isValid($log->tenant_id),
            'tenant_id must be NULL or a valid UUID, never an empty string.'
        );
    }
}
