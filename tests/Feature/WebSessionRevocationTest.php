<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * A web session must be revocable server-side: password change, password
 * reset, account deactivation and device reset all bump users.auth_version,
 * which the EnsureAccountActive middleware compares against the value stored
 * in each live session. This is what makes "log out every other device"
 * possible without enumerating the session store.
 */
class WebSessionRevocationTest extends TenantTestCase
{
    private const ORIGIN = 'http://localhost:3000';

    private function statefulHeaders(): array
    {
        return ['Origin' => self::ORIGIN, 'Accept' => 'application/json'];
    }

    private function webLogin(string $email, string $password = 'password-123'): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
        ], $this->statefulHeaders());

        $response->assertOk();

        $name = (string) config('session.cookie');
        $this->assertNotNull($response->getCookie($name), 'Expected the session cookie to be set.');

        $plain = (string) $this->app['session']->getId();

        return $plain;
    }

    /**
     * Issue the next request as a fresh browser would: reset the guard and the
     * in-memory session store, then present the session cookie.
     */
    private function asCookie(string $id)
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->withCredentials()
            ->withCookie((string) config('session.cookie'), $id)
            ->withHeaders(['Origin' => self::ORIGIN, 'Accept' => 'application/json']);
    }

    private function asAdmin(User $admin, ?string $tenantId = null)
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $headers = ['Origin' => self::ORIGIN, 'Accept' => 'application/json'];
        if ($tenantId) {
            $headers['X-Tenant-Id'] = $tenantId;
        }

        return $this->withHeader('Authorization', 'Bearer '.$admin->createToken('test')->plainTextToken)
            ->withHeaders($headers);
    }

    private function makeWebUser(string $password = 'password-123', array $attrs = []): User
    {
        return $this->makeUser(array_merge([
            'email' => 'rev-'.strtolower(Str::random(8)).'@test.local',
            'password' => Hash::make($password),
        ], $attrs));
    }

    private function makeAdmin(): User
    {
        $admin = $this->makeWebUser('admin-123');
        $admin->forceFill(['is_super_admin' => true, 'is_active' => true])->save();

        return $admin;
    }

    public function test_password_change_keeps_the_current_session_and_revokes_the_others(): void
    {
        $user = $this->makeWebUser();
        $cookieA = $this->webLogin($user->email);
        $cookieB = $this->webLogin($user->email);

        $this->asCookie($cookieA)->putJson('/api/v1/user/password', [
            'current_password' => 'password-123',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertOk();

        // The session that performed the change keeps working…
        $this->asCookie($cookieA)->getJson('/api/v1/auth/me')->assertOk();

        // …every other session is dead.
        $this->asCookie($cookieB)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_password_reset_revokes_every_web_session(): void
    {
        $user = $this->makeWebUser();
        $cookie = $this->webLogin($user->email);

        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'reset-password-123',
            'password_confirmation' => 'reset-password-123',
        ])->assertOk();

        $this->asCookie($cookie)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_deactivating_a_student_revokes_their_web_session(): void
    {
        $student = $this->makeWebUser();
        $cookie = $this->webLogin($student->email);

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $admin = $this->makeAdmin();

        $this->asAdmin($admin, $tenant->id)
            ->putJson('/api/v1/admin/students/'.$student->id.'/status', ['is_active' => false])
            ->assertOk();

        $this->asCookie($cookie)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_deactivating_a_teacher_revokes_their_web_session(): void
    {
        $teacher = $this->makeWebUser();
        Role::findOrCreate('Teacher', 'web');
        $teacher->assignRole('Teacher');
        $cookie = $this->webLogin($teacher->email);

        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);
        $admin = $this->makeAdmin();

        $this->asAdmin($admin, $tenant->id)
            ->putJson('/api/v1/admin/teachers/'.$teacher->id.'/status', ['is_active' => false])
            ->assertOk();

        $this->asCookie($cookie)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_sessions_of_a_deactivated_account_are_rejected_even_without_a_version_bump(): void
    {
        $user = $this->makeWebUser();
        $cookie = $this->webLogin($user->email);

        // Enforced directly in the database (e.g. a maintenance action).
        $user->forceFill(['is_active' => false])->save();

        $this->asCookie($cookie)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_reactivated_account_cannot_resume_an_old_session(): void
    {
        $user = $this->makeWebUser();
        $cookie = $this->webLogin($user->email);

        $user->increment('auth_version');
        $user->forceFill(['is_active' => false])->save();
        $user->forceFill(['is_active' => true])->save();

        // The version bump survives reactivation: the old cookie stays dead
        // until the user signs in again.
        $this->asCookie($cookie)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_failed_login_does_not_revive_or_break_an_existing_session(): void
    {
        $user = $this->makeWebUser();
        $cookie = $this->webLogin($user->email);

        // Wrong password: still a 401, and the good session is unaffected.
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'definitely-wrong',
        ], $this->statefulHeaders())->assertStatus(401);

        $this->asCookie($cookie)->getJson('/api/v1/auth/me')->assertOk();
    }
}
