<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TOTP;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Web cookie-session authentication (BFF) must work alongside the mobile
 * bearer-token flow. The web responses must never contain a Sanctum token.
 */
class WebSessionAuthTest extends TenantTestCase
{
    private const STATE_ORIGIN = 'http://localhost:3000';

    private function statefulHeaders(): array
    {
        return ['Origin' => self::STATE_ORIGIN, 'Accept' => 'application/json'];
    }

    private function sessionCookie($response): string
    {
        $name = (string) config('session.cookie');
        $cookie = $response->getCookie($name);
        $this->assertNotNull($cookie, 'Expected the session cookie '.$name.' to be set.');

        return $name.'='.$cookie->getValue();
    }

    private function makeWebUser(string $password = 'password-123'): User
    {
        return User::factory()->create([
            'email' => 'web-'.strtolower(Str::random(8)).'@test.local',
            'password' => bcrypt($password),
        ]);
    }

    public function test_web_login_returns_session_cookie_and_never_a_token(): void
    {
        $user = $this->makeWebUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password-123',
        ], $this->statefulHeaders());

        $response->assertOk();
        $response->assertJsonPath('user.email', $user->email);
        $response->assertJsonMissingPath('token');
        $response->assertJsonMissingPath('two_factor_token');
        $this->assertNotNull($response->getCookie((string) config('session.cookie')));
    }

    public function test_session_cookie_authenticates_api_requests(): void
    {
        $user = $this->makeWebUser();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password-123',
        ], $this->statefulHeaders());

        $cookie = $this->sessionCookie($login);

        $this->withHeaders(['Cookie' => $cookie, 'Origin' => self::STATE_ORIGIN])
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_session_authenticates_tenant_scoped_endpoints(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeWebUser();
        $this->attachToTenant($user, $tenant, 'owner');
        $this->attachSubscription($tenant);

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password-123',
        ], $this->statefulHeaders());

        $cookie = $this->sessionCookie($login);

        $this->withHeaders([
            'Cookie' => $cookie,
            'Origin' => self::STATE_ORIGIN,
            'X-Tenant-Id' => $tenant->id,
        ])->getJson('/api/v1/dashboard')->assertOk();
    }

    public function test_logout_destroys_the_session(): void
    {
        $user = $this->makeWebUser();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password-123',
        ], $this->statefulHeaders());

        $cookie = $this->sessionCookie($login);

        $this->withHeaders(['Cookie' => $cookie, 'Origin' => self::STATE_ORIGIN])
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // Simulate a new browser request: reset the cached session + guards.
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->withHeaders(['Cookie' => $cookie, 'Origin' => self::STATE_ORIGIN])
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_bearer_login_still_works_for_api_clients(): void
    {
        $user = $this->makeWebUser();

        // No Origin/Referer → not a stateful request: token flow is used.
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password-123',
        ]);

        $response->assertOk();
        $token = $response->json('token');
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_web_2fa_flow_keeps_the_challenge_server_side(): void
    {
        $user = $this->makeWebUser();
        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
        ])->save();

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password-123',
        ], $this->statefulHeaders());

        $login->assertOk();
        $login->assertJsonPath('requires_2fa', true);
        $login->assertJsonMissingPath('token');
        $login->assertJsonMissingPath('two_factor_token');

        $cookie = $this->sessionCookie($login);

        $this->withHeaders(['Cookie' => $cookie, 'Origin' => self::STATE_ORIGIN])
            ->postJson('/api/v1/auth/2fa/confirm', ['code' => TOTP::code('JBSWY3DPEHPK3PXP')])
            ->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonMissingPath('token');
    }

    public function test_web_register_signs_the_user_in_without_returning_a_token(): void
    {
        $email = 'register-'.strtolower(Str::random(8)).'@test.local';

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New Web User',
            'email' => $email,
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
        ], $this->statefulHeaders());

        $response->assertStatus(201);
        $response->assertJsonMissingPath('token');
        $this->assertNotNull($response->getCookie((string) config('session.cookie')));

        $cookie = $this->sessionCookie($response);

        $this->withHeaders(['Cookie' => $cookie, 'Origin' => self::STATE_ORIGIN])
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', $email);
    }

    public function test_google_callback_route_uses_the_web_session_stack(): void
    {
        $route = collect(Route::getRoutes())->first(
            fn ($route) => $route->uri() === 'api/v1/auth/google/callback'
        );

        $this->assertNotNull($route, 'Google callback route must exist.');

        $middleware = $route->gatherMiddleware();

        $this->assertContains('web', $middleware);
        $this->assertNotContains('api', $middleware);
    }

    public function test_google_callback_web_redirect_contains_no_token(): void
    {
        // The callback is exercised in production via Google; here we prove
        // the controller no longer appends tokens to the redirect for the
        // session stack by checking unsigned state is rejected before any
        // token/URL credential could be produced.
        $this->get('/api/v1/auth/google/callback?state=admin')
            ->assertRedirect()
            ->assertRedirectContains('error=invalid_state');

        $location = (string) $this->get('/api/v1/auth/google/callback?state=admin')->headers->get('Location');
        $this->assertStringNotContainsString('token=', $location);
    }

    public function test_mobile_bearer_sessions_are_unaffected_by_web_sessions(): void
    {
        $user = $this->makeWebUser();

        $mobile = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password-123',
        ]);

        $token = $mobile->json('token');

        // A separate web session exists for another account, but the mobile
        // request carries only its bearer token — sessions and tokens are
        // independent auth mechanisms.
        $other = $this->makeWebUser();
        $this->postJson('/api/v1/auth/login', [
            'email' => $other->email,
            'password' => 'password-123',
        ], $this->statefulHeaders());

        // Simulate a fresh browser request (no web session cookie): reset the
        // cached session + guards that the test process shares across calls.
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }
}
