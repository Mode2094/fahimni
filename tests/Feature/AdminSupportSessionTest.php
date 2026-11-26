<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;

/**
 * Super-Admin SUPPORT SESSION (Admin Impersonation) + admin custom-domain
 * management.
 *
 * Covers: authorization, the one-time token, entering one teacher's workspace,
 * normal content writes being allowed, sensitive writes (money / credentials /
 * ownership / deletes) being refused, expiry restoring the admin, nesting
 * prevention, tenant isolation, audit with the real admin identity, and the
 * Super-Admin domain connect/verify/disconnect flow (including single-owner
 * and unverified-not-served rules).
 */
class AdminSupportSessionTest extends TenantTestCase
{
    private function superAdmin(Tenant $tenant): User
    {
        Role::findOrCreate('Teacher', 'web');

        $admin = $this->makeUser(['is_super_admin' => true, 'is_active' => true]);
        $this->attachToTenant($admin, $tenant, 'owner');

        return $admin;
    }

    private function teacherIn(Tenant $tenant): User
    {
        Role::findOrCreate('Teacher', 'web');

        $teacher = $this->makeUser(['phone' => '+962790000000']);
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $teacher->assignRole('Teacher');

        return $teacher;
    }

    private function stateful(User $user, Tenant $tenant): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken,
            'X-Tenant-Id' => $tenant->id,
            'Accept' => 'application/json',
            'Origin' => 'http://localhost:3000',
        ];
    }

    private function plainStateful(Tenant $tenant): array
    {
        return [
            'X-Tenant-Id' => $tenant->id,
            'Accept' => 'application/json',
            'Origin' => 'http://localhost:3000',
        ];
    }

    /** Drop cached guards so the swapped session is re-resolved. */
    private function asBrowser(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
    }

    /** Begin + activate in one go; the browser session is now the teacher. */
    private function enterSupport(Tenant $tenant, User $admin, User $teacher): void
    {
        $token = $this->postJson(
            "/api/v1/admin/support/{$teacher->id}/begin",
            [],
            $this->stateful($admin, $tenant),
        )->assertOk()->json('token');

        $this->postJson('/api/v1/admin/support/activate', ['token' => $token], $this->stateful($admin, $tenant))
            ->assertOk()
            ->assertJsonPath('admin_support.active', true)
            ->assertJsonPath('admin_support.mode', 'support');

        $this->asBrowser();
    }

    // ── Authorization ────────────────────────────────────────────────

    public function test_only_a_super_admin_can_begin_or_activate(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->teacherIn($tenant);
        $other = $this->teacherIn($tenant);

        $this->postJson("/api/v1/admin/support/{$teacher->id}/begin", [], $this->stateful($other, $tenant))
            ->assertStatus(403);

        $this->postJson('/api/v1/admin/support/activate', ['token' => 'x'], $this->stateful($other, $tenant))
            ->assertStatus(403);
    }

    public function test_an_admin_account_can_never_be_impersonated(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);
        $otherAdmin = $this->makeUser(['is_super_admin' => true]);
        $this->attachToTenant($otherAdmin, $tenant, 'admin');

        // `begin` only accepts a real Teacher account — an admin is not found.
        $this->postJson("/api/v1/admin/support/{$otherAdmin->id}/begin", [], $this->stateful($admin, $tenant))
            ->assertStatus(404);
    }

    // ── One-time token ───────────────────────────────────────────────

    public function test_the_entry_token_is_single_use(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);
        $teacher = $this->teacherIn($tenant);

        $token = $this->postJson("/api/v1/admin/support/{$teacher->id}/begin", [], $this->stateful($admin, $tenant))
            ->assertOk()->json('token');

        $this->postJson('/api/v1/admin/support/activate', ['token' => $token], $this->stateful($admin, $tenant))->assertOk();

        // Leave, then replay the SAME token (e.g. a leaked URL): it is consumed.
        $this->asBrowser();
        $this->withHeaders($this->plainStateful($tenant))->deleteJson('/api/v1/admin/support')->assertOk();
        $this->asBrowser();

        $this->postJson('/api/v1/admin/support/activate', ['token' => $token], $this->stateful($admin, $tenant))
            ->assertStatus(422)->assertJsonPath('code', 'TOKEN_INVALID');
    }

    public function test_a_token_is_bound_to_the_admin_that_minted_it(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);
        $teacher = $this->teacherIn($tenant);

        $token = $this->postJson("/api/v1/admin/support/{$teacher->id}/begin", [], $this->stateful($admin, $tenant))
            ->assertOk()->json('token');

        Cache::put('admin_support_token:'.$token, [
            'actor_id' => 'someone-else',
            'user_id' => $teacher->id,
            'tenant_id' => $tenant->id,
        ], now()->addMinute());

        $this->postJson('/api/v1/admin/support/activate', ['token' => $token], $this->stateful($admin, $tenant))
            ->assertStatus(403);
    }

    // ── Entering the workspace ───────────────────────────────────────

    public function test_entering_support_switches_to_the_teacher_and_stop_restores_the_admin(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);
        $teacher = $this->teacherIn($tenant);

        $this->enterSupport($tenant, $admin, $teacher);

        // The session now resolves as the teacher, with the support banner state.
        $this->withHeaders($this->plainStateful($tenant))->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $teacher->id)
            ->assertJsonPath('admin_support.active', true)
            ->assertJsonPath('admin_support.actor_id', $admin->id)
            ->assertJsonPath('admin_support.tenant_id', $tenant->id);

        // Exit — the admin is restored, the marker cleared.
        $this->withHeaders($this->plainStateful($tenant))->deleteJson('/api/v1/admin/support')
            ->assertOk()
            ->assertJsonPath('user.id', $admin->id)
            ->assertJsonPath('admin_support', null);

        $this->asBrowser();
        $this->withHeaders($this->plainStateful($tenant))->getJson('/api/v1/auth/me')
            ->assertOk()->assertJsonPath('user.id', $admin->id);
    }

    public function test_a_support_session_cannot_be_nested(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);
        $teacher = $this->teacherIn($tenant);
        $other = $this->teacherIn($tenant);

        $this->enterSupport($tenant, $admin, $teacher);

        // A second support session is refused (the impersonated teacher is not a
        // platform admin), and so is stacking a read-only view-as on top — admin
        // power can never be borrowed while another session is open.
        $this->withHeaders($this->plainStateful($tenant))
            ->postJson("/api/v1/admin/support/{$other->id}/begin")
            ->assertStatus(403);

        $this->withHeaders($this->plainStateful($tenant))
            ->postJson("/api/v1/admin/view-as/{$other->id}")
            ->assertStatus(403);

        // Still exactly one active session, still the original teacher.
        $this->withHeaders($this->plainStateful($tenant))->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('admin_support.user_id', $teacher->id);
    }

    // ── Write policy ─────────────────────────────────────────────────

    public function test_normal_content_writes_are_allowed_in_support_mode(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $admin = $this->superAdmin($tenant);
        $teacher = $this->teacherIn($tenant);

        $this->enterSupport($tenant, $admin, $teacher);

        // Connecting the teacher's custom domain is exactly the kind of help the
        // support session is for — it must pass.
        $this->withHeaders($this->plainStateful($tenant))
            ->putJson('/api/v1/teacher/domain', ['domain' => 'support-helped.com'])
            ->assertOk();

        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenant->id,
            'custom_domain' => 'support-helped.com',
        ]);

        // ...and it was audited under the REAL admin identity.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.support.write',
            'user_id' => $admin->id,
        ]);
    }

    public function test_sensitive_and_destructive_writes_are_refused(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $admin = $this->superAdmin($tenant);
        $teacher = $this->teacherIn($tenant);

        $this->enterSupport($tenant, $admin, $teacher);
        $base = $this->plainStateful($tenant);

        // Credentials / secrets.
        $this->withHeaders($base)->putJson('/api/v1/user/password', [
            'current_password' => 'x', 'password' => 'y', 'password_confirmation' => 'y',
        ])->assertStatus(403)->assertJsonPath('code', 'SUPPORT_SENSITIVE_BLOCKED');

        $this->withHeaders($base)->postJson('/api/v1/auth/2fa/enable', ['code' => '123456'])
            ->assertStatus(403)->assertJsonPath('code', 'SUPPORT_SENSITIVE_BLOCKED');

        // Money / billing (a subscription write).
        $this->withHeaders($base)->postJson('/api/v1/subscriptions', [])
            ->assertStatus(403)->assertJsonPath('code', 'SUPPORT_SENSITIVE_BLOCKED');

        // Every DELETE (data destruction) — even of the teacher's own domain.
        $this->withHeaders($base)->deleteJson('/api/v1/teacher/domain')
            ->assertStatus(403)->assertJsonPath('code', 'SUPPORT_SENSITIVE_BLOCKED');
    }

    // ── Session lifetime ─────────────────────────────────────────────

    public function test_an_expired_support_session_is_closed_and_the_admin_returns(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);
        $teacher = $this->teacherIn($tenant);

        $this->enterSupport($tenant, $admin, $teacher);

        // Time passes: the live marker is now expired, but the session is still
        // the teacher's — exactly what happens if the admin leaves the tab open.
        $session = $this->app['session'];
        $state = $session->get('admin_support');
        $state['expires_at'] = now()->subMinute()->toIso8601String();
        $session->put('admin_support', $state);

        $this->asBrowser();
        $this->withHeaders($this->plainStateful($tenant))->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'SUPPORT_EXPIRED');

        // The expiry was audited under the real admin...
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'admin.support.expired',
            'user_id' => $admin->id,
        ]);

        // ...and the admin session was restored, not lost.
        $this->asBrowser();
        $this->withHeaders($this->plainStateful($tenant))->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $admin->id)
            ->assertJsonPath('admin_support', null);
    }

    // ── Isolation ────────────────────────────────────────────────────

    public function test_a_support_session_covers_only_the_target_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $this->attachSubscription($tenantA);
        $this->attachSubscription($tenantB);

        $admin = $this->superAdmin($tenantA);
        $teacherA = $this->teacherIn($tenantA);

        $this->enterSupport($tenantA, $admin, $teacherA);

        // The impersonated teacher is not a member of tenant B: a request that
        // tries to target it is rejected by the tenant middleware.
        $this->withHeaders([
            'X-Tenant-Id' => $tenantB->id,
            'Accept' => 'application/json',
            'Origin' => 'http://localhost:3000',
        ])->getJson('/api/v1/courses')->assertStatus(403);

        // Tenant B's own data is untouched.
        $this->assertNull(TenantSetting::withoutTenant()->where('tenant_id', $tenantB->id)->first());
    }

    // ── Audit identity ───────────────────────────────────────────────

    public function test_start_and_stop_are_audited_with_the_real_admin(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);
        $teacher = $this->teacherIn($tenant);

        $this->enterSupport($tenant, $admin, $teacher);
        $this->withHeaders($this->plainStateful($tenant))->deleteJson('/api/v1/admin/support')->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.support.start', 'user_id' => $admin->id, 'entity_id' => $teacher->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.support.stop', 'user_id' => $admin->id, 'entity_id' => $teacher->id]);
    }

    // ── Super-Admin custom-domain management ─────────────────────────

    public function test_admin_can_connect_verify_and_disconnect_a_teacher_domain(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->superAdmin($tenant);
        $teacher = $this->teacherIn($tenant);

        $headers = $this->stateful($admin, $tenant);

        // Connect — stores the apex, pending DNS, with real instructions.
        $this->putJson("/api/v1/admin/teachers/{$teacher->id}/domain", ['domain' => 'www.academy-jo.com'], $headers)
            ->assertOk()
            ->assertJsonPath('domain', 'academy-jo.com')
            ->assertJsonPath('status', 'pending_dns')
            ->assertJsonPath('dns_instructions.cname_host', 'www');

        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenant->id,
            'custom_domain' => 'academy-jo.com',
        ]);

        // Not verified yet → the edge refuses to issue a certificate.
        $this->getJson('/api/v1/public/tls/ask?domain=academy-jo.com')->assertForbidden();

        // A live verify with no matching DNS answer stays unverified.
        $this->postJson("/api/v1/admin/teachers/{$teacher->id}/domain/verify", [], $headers)
            ->assertOk()
            ->assertJsonPath('verified', false);

        // Simulate the teacher having published the records: verified + served.
        $setting = TenantSetting::withoutTenant()->where('tenant_id', $tenant->id)->firstOrFail();
        $meta = $setting->metadata ?? [];
        $meta['domain_verified'] = true;
        $meta['domain_verified_at'] = now()->toIso8601String();
        $setting->update(['metadata' => $meta]);

        $this->getJson('/api/v1/public/tls/ask?domain=academy-jo.com')->assertOk();
        $this->getJson('/api/v1/public/tls/ask?domain=www.academy-jo.com')->assertOk();

        $lookup = $this->getJson('/api/v1/public/domain/academy-jo.com')->assertOk();
        $this->assertSame($tenant->id, $lookup->json('site.id'));

        $this->getJson("/api/v1/admin/teachers/{$teacher->id}/domain", $headers)
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('verified', true)
            ->assertJsonPath('https_enabled', true);

        // Disconnect — the teacher keeps only the free platform link.
        $this->deleteJson("/api/v1/admin/teachers/{$teacher->id}/domain", $headers)->assertOk();
        $this->assertNull(TenantSetting::withoutTenant()->where('tenant_id', $tenant->id)->first()->custom_domain);
        $this->getJson('/api/v1/public/tls/ask?domain=academy-jo.com')->assertForbidden();
    }

    public function test_admin_cannot_connect_a_domain_owned_by_another_teacher(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $adminA = $this->superAdmin($tenantA);
        $teacherA = $this->teacherIn($tenantA);
        $teacherB = $this->teacherIn($tenantB);

        $this->putJson("/api/v1/admin/teachers/{$teacherA->id}/domain", ['domain' => 'shared-name.com'], $this->stateful($adminA, $tenantA))
            ->assertOk();

        $adminB = $this->superAdmin($tenantB);
        $this->putJson("/api/v1/admin/teachers/{$teacherB->id}/domain", ['domain' => 'shared-name.com'], $this->stateful($adminB, $tenantB))
            ->assertStatus(422);

        // The original owner keeps the domain.
        $this->assertSame('shared-name.com', TenantSetting::withoutTenant()->where('tenant_id', $tenantA->id)->first()->custom_domain);
    }

    public function test_admin_domain_management_requires_a_super_admin(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $teacher = $this->teacherIn($tenant);

        $this->getJson("/api/v1/admin/teachers/{$teacher->id}/domain", $this->stateful($teacher, $tenant))
            ->assertStatus(403);
    }
}
