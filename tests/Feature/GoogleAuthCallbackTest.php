<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\TenantSubscription;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\TeacherCodeService;
use App\Support\TOTP;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Spatie\Permission\Models\Role;
use Symfony\Component\Uid\UuidV7;

/**
 * Regression tests for the Google OAuth callback (HTTP 500 in production).
 *
 * Root cause: the callback inserted a tenant with columns that do not exist
 * (`owner_user_id`, `is_active`) and omitted the NOT NULL `tenants.email`,
 * outside any transaction — so provisioning failed (SQLSTATE 23502) and could
 * leave a user without a tenant — or failed partially.
 *
 * These tests pin the contract: correct schema, atomic provisioning, no
 * duplicates, session (no token in the URL), the teacher-registration gate,
 * and that a provisioned teacher can actually reach the dashboard.
 */
class GoogleAuthCallbackTest extends TenantTestCase
{
    /** The public origin an SPA/browser request carries in production. */
    private const STATE_ORIGIN = 'https://fahimni.me';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Pin `sanctum.stateful` to the production list. The test default includes
     * `localhost`, which would hide the exact failure this file guards against.
     */
    private function useProductionStatefulDomains(): void
    {
        config(['sanctum.stateful' => ['fahimni.me', 'www.fahimni.me']]);
    }

    /** The browser's session id (plaintext), replayed via withCookie(). */
    private function sessionCookie($response): string
    {
        $name = (string) config('session.cookie');
        $cookie = $response->getCookie($name);
        $this->assertNotNull($cookie, 'Expected the session cookie '.$name.' to be set.');

        return (string) $this->app['session']->getId();
    }

    /** Issue a request as a fresh browser carrying the session cookie. */
    private function asCookie(string $cookie, array $extraHeaders = [])
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->withCredentials()
            ->withCookie((string) config('session.cookie'), $cookie)
            ->withHeaders(array_merge(['Origin' => self::STATE_ORIGIN, 'Accept' => 'application/json'], $extraHeaders));
    }

    private function fakeGoogle(string $email, string $name = 'New Teacher'): void
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

    /** Build the exact HMAC-signed state the redirect endpoint issues. */
    private function signedState(array $overrides = []): string
    {
        $payload = array_merge([
            'role' => 'teacher',
            'name' => null,
            // Most teacher fixtures must be fully provisioned, so a valid
            // phone is the default; the "no phone" path is exercised explicitly.
            'phone' => '+962790000000',
            'exp' => time() + 600,
            'n' => Str::random(16),
        ], $overrides);

        $encoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        return $encoded.'.'.hash_hmac('sha256', $encoded, (string) config('app.key'));
    }

    private function callbackWith(string $state)
    {
        return $this->get('/api/v1/auth/google/callback?state='.urlencode($state).'&code=fake-code');
    }

    /**
     * A lowercase address: the callback normalizes Google emails, so a mixed
     * case fixture would (correctly) not match what gets stored.
     */
    private function freshEmail(string $prefix = 'teacher'): string
    {
        return strtolower($prefix.'-'.Str::random(8).'@test.local');
    }

    /** An active plan so first registration gets the 7-day trial. */
    private function makePlan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'id' => UuidV7::generate(),
            'name' => 'Basic',
            'slug' => 'basic-'.Str::random(6),
            'monthly_price' => 19.99,
            'yearly_price' => 199.99,
            'currency' => 'JOD',
            'trial_days' => 7,
            'is_active' => true,
            'is_public' => true,
            'sort_order' => 0,
        ]);
    }

    public function test_new_teacher_google_login_provisions_a_workspace_and_reaches_the_dashboard(): void
    {
        PlatformSetting::set('teacher_registration_enabled', true);

        $email = $this->freshEmail('teacher');
        $this->fakeGoogle($email, 'New Teacher');

        $this->makePlan();

        $response = $this->callbackWith($this->signedState([
            'role' => 'teacher',
            'name' => 'New Teacher',
            'phone' => '+962790000000',
        ]));

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('step=done', $location);
        $this->assertStringNotContainsString('token=', $location);

        $user = User::where('email', $email)->first();
        $this->assertNotNull($user, 'the Google callback must create the user');
        $this->assertTrue($user->hasRole('Teacher'), 'Google teachers must get the Teacher role');

        // The tenant must be written with the REAL schema columns.
        $tenant = Tenant::where('owner_id', $user->id)->first();
        $this->assertNotNull($tenant, 'the teacher must own a tenant');
        $this->assertSame('active', $tenant->status);
        $this->assertSame('tutor', $tenant->type);
        $this->assertSame($email, $tenant->email);
        $this->assertNotEmpty($tenant->teacher_code);

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenant->id,
            'academy_name' => 'New Teacher',
        ]);

        // Registration grants a trial so the teacher is not instantly 402'd.
        $this->assertDatabaseHas('tenant_subscriptions', [
            'tenant_id' => $tenant->id,
            'status' => 'trialing',
        ]);

        // The real teacher dashboard endpoint answers for this account.
        $this->getJson('/api/v1/dashboard', $this->authHeaders($user, $tenant))->assertOk();
    }

    public function test_repeat_google_login_creates_no_duplicate_account_tenant_or_membership(): void
    {
        PlatformSetting::set('teacher_registration_enabled', true);
        $this->makePlan();

        $email = $this->freshEmail('teacher');
        $this->fakeGoogle($email, 'New Teacher');

        $this->callbackWith($this->signedState(['role' => 'teacher']))->assertRedirect();
        $this->callbackWith($this->signedState(['role' => 'teacher']))->assertRedirect();

        $user = User::where('email', $email)->firstOrFail();
        $tenant = Tenant::where('owner_id', $user->id)->firstOrFail();

        $this->assertSame(1, User::where('email', $email)->count());
        $this->assertSame(1, Tenant::where('owner_id', $user->id)->count());
        $this->assertSame(1, TenantUser::where('user_id', $user->id)->count());
        $this->assertSame(1, TenantSetting::where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, TenantSubscription::where('tenant_id', $tenant->id)->count());
    }

    public function test_student_google_login_never_gets_an_owner_tenant(): void
    {
        $email = $this->freshEmail('student');
        $this->fakeGoogle($email, 'New Student');

        $this->callbackWith($this->signedState(['role' => 'student']))->assertRedirect();

        $user = User::where('email', $email)->firstOrFail();

        $this->assertSame(0, Tenant::where('owner_id', $user->id)->count());
        $this->assertFalse($user->hasRole('Teacher'));
    }

    public function test_student_google_signup_preserves_qr_activation_return_path(): void
    {
        $tenant = $this->makeTenant();
        $email = $this->freshEmail('student');
        $this->fakeGoogle($email, 'QR Student');

        $response = $this->callbackWith($this->signedState([
            'role' => 'student',
            'tenant_slug' => $tenant->slug,
            'redirect_to' => '/card/ABCDEF123456',
        ]));

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('redirect_to=%2Fcard%2FABCDEF123456', $location);
        $this->assertStringNotContainsString('token=', $location);
        $this->assertDatabaseHas('users', ['email' => $email]);
    }

    public function test_student_google_login_restores_the_selected_teacher_session_context(): void
    {
        $this->useProductionStatefulDomains();
        $tenant = $this->makeTenant();
        $email = $this->freshEmail('student');
        $student = $this->makeUser(['email' => $email]);
        $this->attachToTenant($student, $tenant, 'student');
        $this->fakeGoogle($email, 'Existing Student');

        $response = $this->callbackWith($this->signedState([
            'role' => 'student',
            'tenant_slug' => $tenant->slug,
        ]));
        $response->assertRedirect();

        $this->asCookie($this->sessionCookie($response))
            ->getJson('/api/v1/dashboard')
            ->assertOk();
    }

    public function test_student_google_registration_returns_to_the_activation_card_without_a_token_in_the_url(): void
    {
        $tenant = $this->makeTenant();
        $email = $this->freshEmail('student');
        $this->fakeGoogle($email, 'New Student');

        $response = $this->callbackWith($this->signedState([
            'role' => 'student',
            'tenant_slug' => $tenant->slug,
            'redirect_to' => '/card/ABCDEF123456',
        ]));

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('step=done', $location);
        $this->assertStringContainsString('redirect_to=%2Fcard%2FABCDEF123456', $location);
        $this->assertStringNotContainsString('token=', $location);
        $this->assertDatabaseHas('users', ['email' => $email]);
    }

    public function test_student_google_login_preserves_an_existing_teacher_workspace(): void
    {
        $this->useProductionStatefulDomains();
        $tenant = $this->makeTenant();
        $email = $this->freshEmail('student');
        $student = $this->makeUser(['email' => $email]);
        $this->attachToTenant($student, $tenant, 'student');
        $this->fakeGoogle($email, 'Existing Student');

        $response = $this->callbackWith($this->signedState([
            'role' => 'student',
            'tenant_slug' => $tenant->slug,
        ]));
        $response->assertRedirect();

        $cookie = $this->sessionCookie($response);
        $this->asCookie($cookie)->getJson('/api/v1/dashboard')->assertOk();
    }

    public function test_mixed_case_google_email_normalizes_to_one_account(): void
    {
        PlatformSetting::set('teacher_registration_enabled', true);

        $lower = $this->freshEmail('teacher');
        $mixed = strtoupper(substr($lower, 0, 8)).substr($lower, 8);

        $this->fakeGoogle($mixed, 'New Teacher');
        $this->callbackWith($this->signedState(['role' => 'teacher']))->assertRedirect();

        $this->assertSame(1, User::where('email', $lower)->count(), 'the email must be stored lowercased');
        $this->assertSame(0, User::where('email', $mixed)->count(), 'no case-variant duplicate account');
    }

    public function test_failed_tenant_provisioning_rolls_back_the_whole_signup(): void
    {
        PlatformSetting::set('teacher_registration_enabled', true);

        $email = $this->freshEmail('teacher');
        $this->fakeGoogle($email, 'New Teacher');

        // Fail the tenant insert AFTER the user row has been written, to prove
        // the user is rolled back with it (no orphan account).
        $this->mock(TeacherCodeService::class, function ($mock) {
            $mock->shouldReceive('generate')->andThrow(new \RuntimeException('teacher code service unavailable'));
        });

        $response = $this->callbackWith($this->signedState(['role' => 'teacher']));

        $response->assertStatus(500);

        $this->assertNull(
            User::where('email', $email)->first(),
            'a failed signup must not leave a user behind',
        );
        $this->assertSame(0, Tenant::count(), 'a failed signup must not leave a tenant behind');
    }

    public function test_web_google_login_uses_the_session_and_places_no_token_in_the_url(): void
    {
        PlatformSetting::set('teacher_registration_enabled', true);

        $email = $this->freshEmail('teacher');
        $this->fakeGoogle($email, 'New Teacher');

        $response = $this->callbackWith($this->signedState(['role' => 'teacher']));

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');

        // The browser flow must never carry a bearer token in the URL.
        $this->assertStringNotContainsString('token=', $location);
        $this->assertStringNotContainsString('access_token', $location);
        $this->assertStringContainsString('step=done', $location);

        // An HttpOnly session cookie is what authenticates the next request.
        $this->assertNotNull($response->getCookie((string) config('session.cookie')));
    }

    public function test_new_teacher_is_rejected_when_registration_is_disabled(): void
    {
        PlatformSetting::set('teacher_registration_enabled', false);

        $email = $this->freshEmail('teacher');
        $this->fakeGoogle($email, 'New Teacher');

        $response = $this->callbackWith($this->signedState(['role' => 'teacher']));

        $response->assertRedirect();
        $this->assertStringContainsString(
            'error=teacher_registration_disabled',
            (string) $response->headers->get('Location'),
        );

        // The gate must be enforced BEFORE any write.
        $this->assertNull(User::where('email', $email)->first(), 'a blocked signup must not create a user');
        $this->assertSame(0, Tenant::count());
    }

    /**
     * The full production hop: Google callback → Set-Cookie → the BFF's
     * `/api/session/me` → `/api/v1/auth/me` with the PUBLIC origin. This is the
     * chain that showed «تعذر إنشاء الجلسة» when the BFF forwarded an internal
     * origin that is absent from `SANCTUM_STATEFUL_DOMAINS`.
     */
    public function test_google_callback_session_authenticates_auth_me_for_the_public_origin(): void
    {
        $this->useProductionStatefulDomains();
        PlatformSetting::set('teacher_registration_enabled', true);
        $this->makePlan();

        $email = $this->freshEmail('teacher');
        $this->fakeGoogle($email, 'New Teacher');

        $response = $this->callbackWith($this->signedState(['role' => 'teacher']));
        $response->assertRedirect();
        $this->assertStringContainsString('step=done', (string) $response->headers->get('Location'));

        $cookie = $this->sessionCookie($response);

        $me = $this->asCookie($cookie)->getJson('/api/v1/auth/me');

        $me->assertOk()->assertJsonPath('user.email', $email);
        $this->assertContains('Teacher', (array) $me->json('user.roles'));

        // Stateful middleware ran: the session middleware refreshed the cookie.
        $this->assertNotNull(
            $me->getCookie((string) config('session.cookie')),
            'a stateful request must pass through the session middleware',
        );
    }

    /**
     * The failing hop, pinned: the SAME valid session cookie is ignored when
     * the forwarded origin is not in `SANCTUM_STATEFUL_DOMAINS` — no stateful
     * middleware, no session, 401, and not even a session cookie in response.
     */
    public function test_google_callback_session_is_ignored_for_a_non_stateful_origin(): void
    {
        $this->useProductionStatefulDomains();
        PlatformSetting::set('teacher_registration_enabled', true);
        $this->makePlan();

        $email = $this->freshEmail('teacher');
        $this->fakeGoogle($email, 'New Teacher');

        $cookie = $this->sessionCookie($this->callbackWith($this->signedState(['role' => 'teacher'])));

        // `http://localhost:3000` is exactly what Next.js forwarded behind the
        // proxy (its own listen address), and it is not a stateful domain.
        $response = $this->asCookie($cookie, ['Origin' => 'http://localhost:3000'])
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(401);

        // Decisive signal: with no stateful middleware there is no session
        // middleware either, so Laravel never issues (or refreshes) a cookie.
        $this->assertNull(
            $response->getCookie((string) config('session.cookie')),
            'a non-stateful request must not start or refresh a session',
        );
    }

    /** Google login must never bypass an enabled second factor. */
    public function test_google_login_for_a_2fa_user_defers_until_the_second_factor(): void
    {
        $this->useProductionStatefulDomains();
        PlatformSetting::set('teacher_registration_enabled', true);
        $this->makePlan();

        $email = $this->freshEmail('teacher');
        $user = User::factory()->create([
            'email' => $email,
            'password' => bcrypt('password-123'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
        ])->save();

        $this->fakeGoogle($email, 'New Teacher');

        $response = $this->callbackWith($this->signedState(['role' => 'teacher']));
        $response->assertRedirect();
        $this->assertStringContainsString('step=2fa', (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString('token=', (string) $response->headers->get('Location'));

        $cookie = $this->sessionCookie($response);

        // Still not signed in: the pending challenge lives server-side.
        $this->asCookie($cookie)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        // Completing the factor signs the user in over the same session.
        $this->asCookie($cookie)
            ->postJson('/api/v1/auth/2fa/confirm', ['code' => TOTP::code('JBSWY3DPEHPK3PXP')])
            ->assertOk()
            ->assertJsonPath('user.email', $email)
            ->assertJsonMissingPath('token');
    }

    // ───────────────── teacher phone requirement (Google flow) ──────────────

    /**
     * A teacher who signs up with Google but supplies no phone must NOT get a
     * workspace or a trial yet: the callback stops at «أكمل بيانات حسابك».
     */
    public function test_teacher_google_signup_without_phone_defers_workspace_and_trial(): void
    {
        $this->useProductionStatefulDomains();
        PlatformSetting::set('teacher_registration_enabled', true);
        $this->makePlan();

        $email = $this->freshEmail('teacher');
        $this->fakeGoogle($email, 'No Phone');

        $response = $this->callbackWith($this->signedState(['role' => 'teacher', 'phone' => null]));
        $response->assertRedirect();

        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('step=profile_required', $location);
        $this->assertStringNotContainsString('token=', $location);

        $user = User::where('email', $email)->firstOrFail();
        $this->assertTrue($user->hasRole('Teacher'), 'the account still gets the Teacher role');
        $this->assertNull($user->phone, 'no phone is invented');
        $this->assertSame(0, Tenant::where('owner_id', $user->id)->count(), 'no workspace before the phone');
        $this->assertSame(0, TenantSubscription::withoutTenant()->count(), 'no trial before the phone');
    }

    /**
     * Completing the profile starts the workspace + trial exactly once; a
     * second call is a no-op and can never duplicate them.
     */
    public function test_completing_profile_provisions_the_workspace_and_trial_once(): void
    {
        $this->useProductionStatefulDomains();
        PlatformSetting::set('teacher_registration_enabled', true);
        $this->makePlan();

        $email = $this->freshEmail('teacher');
        $this->fakeGoogle($email, 'No Phone');

        $response = $this->callbackWith($this->signedState(['role' => 'teacher', 'phone' => null]));
        $cookie = $this->sessionCookie($response);

        $done = $this->asCookie($cookie)->postJson('/api/v1/auth/complete-profile', [
            'name' => 'Completed Teacher',
            'phone' => '0791234567',
        ]);

        $done->assertOk();
        $this->assertSame('+962791234567', $done->json('user.phone'), 'stored in canonical E.164');
        $this->assertFalse((bool) $done->json('user.requires_profile_completion'));

        $tenantId = $done->json('tenant_id');
        $this->assertNotNull($tenantId);

        $user = User::where('email', $email)->firstOrFail();
        $this->assertSame('Completed Teacher', $user->name);
        $this->assertSame(1, Tenant::where('owner_id', $user->id)->count());
        $this->assertSame(1, TenantSubscription::withoutTenant()->where('tenant_id', $tenantId)->count());

        // Idempotent: finishing again must not create a second tenant/trial.
        $this->asCookie($cookie)->postJson('/api/v1/auth/complete-profile', [
            'name' => 'Completed Teacher',
            'phone' => '+962791234567',
        ])->assertOk();

        $this->assertSame(1, Tenant::where('owner_id', $user->id)->count());
        $this->assertSame(1, TenantUser::where('user_id', $user->id)->count());
        $this->assertSame(1, TenantSubscription::withoutTenant()->where('tenant_id', $tenantId)->count());
    }

    /** A teacher without a phone is blocked from tenant routes server-side. */
    public function test_teacher_without_phone_is_blocked_from_tenant_routes(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);

        $user = $this->makeUser(['phone' => null]);
        Role::findOrCreate('Teacher', 'web');
        $user->assignRole('Teacher');
        $this->attachToTenant($user, $tenant, 'owner');

        $this->getJson('/api/v1/dashboard', $this->authHeaders($user, $tenant))
            ->assertStatus(403)
            ->assertJsonPath('code', 'profile_incomplete');
    }

    /** A teacher with a phone on file reaches the dashboard normally. */
    public function test_teacher_with_phone_reaches_tenant_routes(): void
    {
        $tenant = $this->makeTenant();
        $this->attachSubscription($tenant);

        $user = $this->makeUser(['phone' => '+962791234567']);
        Role::findOrCreate('Teacher', 'web');
        $user->assignRole('Teacher');
        $this->attachToTenant($user, $tenant, 'owner');

        $this->getJson('/api/v1/dashboard', $this->authHeaders($user, $tenant))->assertOk();
    }

    /** Existing teacher accounts (no phone) are never deleted or suspended. */
    public function test_legacy_teacher_without_phone_keeps_its_workspace_and_trial(): void
    {
        $tenant = $this->makeTenant();
        $subscription = $this->attachSubscription($tenant);

        $user = $this->makeUser(['phone' => null]);
        Role::findOrCreate('Teacher', 'web');
        $user->assignRole('Teacher');
        $this->attachToTenant($user, $tenant, 'owner');

        // The gate blocks dashboard use but must not touch the data.
        $this->getJson('/api/v1/dashboard', $this->authHeaders($user, $tenant))->assertStatus(403);

        $this->assertDatabaseHas('tenants', ['id' => $tenant->id]);
        $this->assertDatabaseHas('tenant_subscriptions', ['id' => $subscription->id, 'status' => 'trialing']);
        $this->assertDatabaseHas('tenant_user', ['tenant_id' => $tenant->id, 'user_id' => $user->id]);
        $this->assertNotNull(User::find($user->id), 'the account must survive');
        $this->assertSame($subscription->trial_ends_at?->timestamp, TenantSubscription::find($subscription->id)->trial_ends_at?->timestamp);
    }
}
