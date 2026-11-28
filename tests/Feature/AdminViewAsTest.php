<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;

/**
 * Admin "View-As" — safe, read-only impersonation.
 *
 * Contract: only a super admin may start; another admin can never be
 * previewed; the session then behaves as the target but every state-changing
 * request is refused server-side; exiting restores the admin.
 */
class AdminViewAsTest extends TenantTestCase
{
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

    /** After switching users, drop cached guards so the session is re-resolved. */
    private function asBrowser(): void
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
    }

    /** @test */
    public function test_only_a_super_admin_can_start_view_as(): void
    {
        $tenant = $this->makeTenant();
        $teacher = $this->makeUser();
        $this->attachToTenant($teacher, $tenant, 'teacher');
        $target = $this->makeUser();
        $this->attachToTenant($target, $tenant, 'student');

        $this->postJson("/api/v1/admin/view-as/{$target->id}", [], $this->stateful($teacher, $tenant))
            ->assertStatus(403);

        $this->assertSame($teacher->id, $teacher->fresh()->id);
    }

    /** @test */
    public function test_an_admin_account_can_never_be_previewed(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser(['is_super_admin' => true]);
        $this->attachToTenant($admin, $tenant, 'owner');
        $otherAdmin = $this->makeUser(['is_super_admin' => true]);
        $this->attachToTenant($otherAdmin, $tenant, 'admin');

        $this->postJson("/api/v1/admin/view-as/{$otherAdmin->id}", [], $this->stateful($admin, $tenant))
            ->assertStatus(403);
    }

    /** @test */
    public function test_an_admin_cannot_preview_their_own_account(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser(['is_super_admin' => true]);
        $this->attachToTenant($admin, $tenant, 'owner');

        $this->postJson("/api/v1/admin/view-as/{$admin->id}", [], $this->stateful($admin, $tenant))
            ->assertStatus(422);
    }

    /** @test */
    public function test_view_as_switches_the_session_to_the_target_and_blocks_writes(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser(['is_super_admin' => true]);
        $this->attachToTenant($admin, $tenant, 'owner');
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $start = $this->postJson("/api/v1/admin/view-as/{$student->id}", [], $this->stateful($admin, $tenant));
        $start->assertOk()
            ->assertJsonPath('user.id', $student->id)
            ->assertJsonPath('view_as.active', true)
            ->assertJsonPath('view_as.read_only', true)
            ->assertJsonPath('view_as.actor_id', $admin->id);

        $this->asBrowser();
        $base = $this->plainStateful($tenant);

        // The session now resolves as the impersonated student, and /me carries
        // the banner state so the UI can warn + offer an exit.
        $me = $this->withHeaders($base)->getJson('/api/v1/auth/me');
        $me->assertOk()->assertJsonPath('user.id', $student->id)->assertJsonPath('view_as.active', true);

        // A read is allowed.
        $this->withHeaders($base)->getJson('/api/v1/student/profile')->assertOk();

        // Every write is refused before it can reach any controller.
        $this->withHeaders($base)->postJson('/api/v1/student/favorites/anything')->assertStatus(403)->assertJsonPath('code', 'VIEW_AS_READ_ONLY');
        $this->withHeaders($base)->putJson('/api/v1/user/profile', ['name' => 'hacked'])->assertStatus(403)->assertJsonPath('code', 'VIEW_AS_READ_ONLY');
        $this->withHeaders($base)->deleteJson('/api/v1/lesson-questions/not-real')->assertStatus(403)->assertJsonPath('code', 'VIEW_AS_READ_ONLY');

        // Nothing leaked through.
        $this->assertSame($student->fresh()->name, $student->name);
    }

    /** @test */
    public function test_the_guard_also_covers_routes_registered_outside_the_main_group(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser(['is_super_admin' => true]);
        $this->attachToTenant($admin, $tenant, 'owner');
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $this->postJson("/api/v1/admin/view-as/{$student->id}", [], $this->stateful($admin, $tenant))->assertOk();

        $this->asBrowser();
        $base = $this->plainStateful($tenant);

        // These write routes live in their own groups, not the main one — the
        // global guard must still refuse them so a preview can never mutate
        // uploads on the impersonated account's behalf.
        $this->withHeaders($base)->postJson('/api/v1/upload')
            ->assertStatus(403)->assertJsonPath('code', 'VIEW_AS_READ_ONLY');
        $this->withHeaders($base)->postJson('/api/v1/assignment-upload')
            ->assertStatus(403)->assertJsonPath('code', 'VIEW_AS_READ_ONLY');
    }

    /** @test */
    public function test_stopping_view_as_restores_the_admin(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser(['is_super_admin' => true]);
        $this->attachToTenant($admin, $tenant, 'owner');
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');

        $this->postJson("/api/v1/admin/view-as/{$student->id}", [], $this->stateful($admin, $tenant))->assertOk();

        $this->asBrowser();
        $base = $this->plainStateful($tenant);

        $stop = $this->withHeaders($base)->deleteJson('/api/v1/admin/view-as');
        $stop->assertOk()->assertJsonPath('user.id', $admin->id)->assertJsonPath('view_as', null);

        $this->asBrowser();
        $me = $this->withHeaders($base)->getJson('/api/v1/auth/me');
        $me->assertOk()->assertJsonPath('user.id', $admin->id)->assertJsonPath('view_as', null);

        // Writes are allowed again for the restored admin.
        $this->withHeaders($base)->putJson('/api/v1/user/profile', ['name' => 'Admin Renamed'])->assertOk();
        $this->assertSame('Admin Renamed', $admin->fresh()->name);
    }

    /** @test */
    public function test_a_view_as_session_cannot_be_nested(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser(['is_super_admin' => true]);
        $this->attachToTenant($admin, $tenant, 'owner');
        $student = $this->makeUser();
        $this->attachToTenant($student, $tenant, 'student');
        $other = $this->makeUser();
        $this->attachToTenant($other, $tenant, 'student');

        $this->postJson("/api/v1/admin/view-as/{$student->id}", [], $this->stateful($admin, $tenant))->assertOk();

        // The nested attempt is itself a write and is refused by the read-only
        // guard while the marker is active.
        $this->asBrowser();
        $this->withHeaders($this->plainStateful($tenant))
            ->postJson("/api/v1/admin/view-as/{$other->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'VIEW_AS_READ_ONLY');
    }

    /** @test */
    public function test_stopping_without_an_active_session_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->makeUser(['is_super_admin' => true]);
        $this->attachToTenant($admin, $tenant, 'owner');

        $this->withHeaders($this->stateful($admin, $tenant))
            ->deleteJson('/api/v1/admin/view-as')
            ->assertStatus(422);
    }
}
