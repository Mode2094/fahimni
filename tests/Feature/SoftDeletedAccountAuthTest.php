<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Spatie\Permission\Models\Role;
use Symfony\Component\Uid\UuidV7;

/**
 * Soft-deleted accounts must never dead-end authentication.
 *
 * A soft-deleted row keeps its address reserved by `users_email_unique`, while
 * a plain `where('email')` hides it — so a login/registration attempt hit the
 * unique index and surfaced as `error=retry` («تعارض مؤقت أثناء إنشاء الحساب»).
 * These tests pin the platform-wide contract: ownership-proving flows
 * reactivate the account (non-destructively, with its workspace), blind
 * registrations are refused with a clear, recoverable message, and no path can
 * trip the unique constraint.
 */
class SoftDeletedAccountAuthTest extends TenantTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function fakeGoogle(string $email, string $name = 'Deleted Teacher'): void
    {
        $googleUser = Mockery::mock(SocialiteUser::class);
        $googleUser->shouldReceive('getEmail')->andReturn($email);
        $googleUser->shouldReceive('getName')->andReturn($name);
        $googleUser->shouldReceive('getNickname')->andReturn('nick');
        $googleUser->shouldReceive('getId')->andReturn('1234567890');

        $provider = Mockery::mock(SocialiteProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    private function signedState(array $overrides = []): string
    {
        $payload = array_merge([
            'role' => 'teacher',
            'name' => null,
            'phone' => null,
            'exp' => time() + 600,
            'n' => Str::random(16),
        ], $overrides);

        $encoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        return $encoded.'.'.hash_hmac('sha256', $encoded, (string) config('app.key'));
    }

    private function callbackWith(string $state)
    {
        return $this->get('/api/v1/auth/google/callback?state='.urlencode($state));
    }

    /**
     * A teacher with an owned workspace, soft-deleted exactly like
     * AdminController::teacherDestroy() does (user + all member tenants).
     *
     * @return array{0: User, 1: Tenant, 2: string}
     */
    private function softDeletedTeacher(string $password = 'password-123'): array
    {
        $email = strtolower('deleted-'.Str::random(8).'@test.local');

        $user = User::factory()->create([
            'email' => $email,
            'password' => bcrypt($password),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->assignRole(Role::findOrCreate('Teacher', 'web'));

        $tenant = Tenant::create([
            'id' => (string) UuidV7::generate(),
            'name' => 'Deleted Academy',
            'slug' => 'deleted-'.Str::random(6),
            'email' => $email,
            'owner_id' => $user->id,
            'status' => 'active',
            'locale' => 'ar',
            'timezone' => 'Asia/Riyadh',
            'max_students' => 100,
            'storage_limit_mb' => 5000,
            'metadata' => [],
        ]);

        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
            'metadata' => [],
        ]);

        Tenant::whereKey($tenant->id)->delete();
        $user->delete();

        return [$user->fresh(), $tenant->fresh(), $email];
    }

    public function test_google_login_reactivates_a_soft_deleted_teacher_instead_of_dead_ending(): void
    {
        PlatformSetting::set('teacher_registration_enabled', true);

        [$user, $tenant, $email] = $this->softDeletedTeacher();
        $this->fakeGoogle($email);

        $response = $this->callbackWith($this->signedState(['role' => 'teacher']));

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');

        // The exact production dead-end.
        $this->assertStringNotContainsString('error=retry', $location);
        $this->assertStringContainsString('step=done', $location);

        // Account + workspace are back, with no duplicate rows.
        $this->assertFalse(User::withTrashed()->where('email', $email)->first()->trashed());
        $this->assertSame(1, User::withTrashed()->where('email', $email)->count());
        $this->assertSame(1, Tenant::where('owner_id', $user->id)->count());
        $this->assertSame(1, TenantUser::where('user_id', $user->id)->count());
        $this->assertNotNull(Tenant::find($tenant->id), 'the owned workspace must be restored');
        $this->assertTrue(User::find($user->id)->hasRole('Teacher'));
    }

    public function test_google_login_reactivates_before_the_2fa_challenge(): void
    {
        PlatformSetting::set('teacher_registration_enabled', true);

        [$user, , $email] = $this->softDeletedTeacher();
        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
        ])->save();

        $this->fakeGoogle($email);

        $location = (string) $this->callbackWith($this->signedState(['role' => 'teacher']))
            ->headers->get('Location');

        $this->assertStringContainsString('step=2fa', $location);
        $this->assertStringNotContainsString('error=retry', $location);
        $this->assertFalse($user->fresh()->trashed(), 'a 2FA challenge must not be issued for an unresolved account');
    }

    public function test_web_password_login_reactivates_a_soft_deleted_user(): void
    {
        [, , $email] = $this->softDeletedTeacher();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'password-123',
        ], ['Origin' => 'http://localhost:3000', 'Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('user.email', $email);
        $this->assertFalse(User::withTrashed()->where('email', $email)->first()->trashed());
    }

    public function test_wrong_password_does_not_reactivate_a_deleted_account(): void
    {
        [, , $email] = $this->softDeletedTeacher();

        $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'not-the-password',
        ])->assertStatus(401);

        $this->assertTrue(User::withTrashed()->where('email', $email)->first()->trashed());
    }

    public function test_register_with_a_previously_deleted_email_is_rejected_clearly(): void
    {
        [, , $email] = $this->softDeletedTeacher();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Imposter',
            'email' => $email,
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'email_previously_deleted');

        // No second row and no reactivation: the address stays with its owner.
        $this->assertSame(1, User::withTrashed()->where('email', $email)->count());
        $this->assertTrue(User::withTrashed()->where('email', $email)->first()->trashed());
    }

    public function test_find_by_email_sees_soft_deleted_rows_and_restore_brings_back_the_workspace(): void
    {
        [$user, , $email] = $this->softDeletedTeacher();

        // The exact bug: a plain lookup misses the reserved address.
        $this->assertNull(User::where('email', $email)->first());
        $this->assertTrue(User::findByEmail($email)->trashed());

        User::findByEmail($email)->restoreWithWorkspaces();

        $this->assertFalse($user->fresh()->trashed());
        $this->assertNotNull(Tenant::where('owner_id', $user->id)->first(), 'the owned workspace must be restored');
    }
}
