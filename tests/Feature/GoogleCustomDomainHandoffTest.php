<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\GoogleHandoff;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Symfony\Component\Uid\UuidV7;

/**
 * Google sign-in on a teacher's OWN domain.
 *
 * The central OAuth callback runs on the platform host, so it can prove WHO the
 * student is but its session cookie never reaches `teacher.com`. These tests pin
 * the cross-host handoff: the callback mints a single-use, browser-bound ticket;
 * the teacher host redeems it for its own session — with no token in any URL and
 * no way to replay, fix onto another browser, or cross into another tenant.
 */
class GoogleCustomDomainHandoffTest extends TenantTestCase
{
    private const PLATFORM = 'fahimni.me';

    private const DOMAIN = 'teacher-a.com';

    protected function setUp(): void
    {
        parent::setUp();

        PlatformSetting::set('platform_domain', self::PLATFORM);
        config(['sanctum.stateful' => [self::PLATFORM, 'www.'.self::PLATFORM, 'api.'.self::PLATFORM]]);
        config(['services.google.client_id' => 'test-client-id', 'services.google.client_secret' => 'test-secret']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** A tenant whose custom domain is VERIFIED (live) — the resolver accepts it. */
    private function verifiedDomain(string $domain = self::DOMAIN): Tenant
    {
        $tenant = $this->makeTenant();

        TenantSetting::withoutTenant()->create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'custom_domain' => $domain,
            'metadata' => ['domain_verified' => true, 'domain_verified_at' => now()->toIso8601String()],
        ]);

        return $tenant;
    }

    private function fakeGoogle(string $email, string $name = 'Student'): void
    {
        $googleUser = Mockery::mock(SocialiteUser::class);
        $googleUser->shouldReceive('getEmail')->andReturn($email);
        $googleUser->shouldReceive('getName')->andReturn($name);
        $googleUser->shouldReceive('getNickname')->andReturn('nick');
        $googleUser->shouldReceive('getId')->andReturn('1234567890');

        $provider = Mockery::mock(SocialiteProvider::class);
        $provider->shouldReceive('with')->andReturnSelf();
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($googleUser);
        $provider->shouldReceive('redirect')->andReturn(redirect()->away('https://accounts.google.com/mock'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    /** Capture the signed state the redirect endpoint would send to Google. */
    private function fakeGoogleCapturingState(array &$captured): void
    {
        $provider = Mockery::mock(SocialiteProvider::class);
        $provider->shouldReceive('with')->andReturnUsing(function ($args) use (&$captured, $provider) {
            $captured = $args;

            return $provider;
        });
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturn(redirect()->away('https://accounts.google.com/mock'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    private function decodeState(string $state): array
    {
        [$encoded] = explode('.', $state);

        return json_decode((string) base64_decode(strtr($encoded, '-_', '+/'), true) ?: '', true) ?? [];
    }

    /** Build the exact HMAC-signed state the redirect endpoint issues. */
    private function signedState(array $overrides = []): string
    {
        $payload = array_merge([
            'role' => 'student',
            'name' => null,
            'phone' => null,
            'tenant_slug' => null,
            'redirect_to' => null,
            'handoff_host' => null,
            'bh' => null,
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

    /** Redeem a ticket the way the teacher host's BFF does. */
    private function redeem(string $origin, string $ticket, string $binding)
    {
        return $this->postJson('/api/v1/auth/google/redeem', [
            'ticket' => $ticket,
            'binding' => $binding,
        ], ['Origin' => $origin, 'Accept' => 'application/json']);
    }

    /** The ticket id from a `…/auth/google/complete#ticket=…` redirect. */
    private function ticketFromRedirect($response): string
    {
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('https://'.self::DOMAIN.'/auth/google/complete#ticket=', $location);

        return (string) Str::after($location, 'ticket=');
    }

    // ── redirect: a custom host requires a browser binding ───────────────────

    public function test_a_custom_domain_redirect_refuses_without_a_binding(): void
    {
        $this->verifiedDomain();
        $this->fakeGoogle('student@test.local');

        $this->getJson('/api/v1/auth/google/redirect?role=student', [
            'Origin' => 'https://'.self::DOMAIN,
            'Accept' => 'application/json',
        ])->assertStatus(422);
    }

    public function test_a_custom_domain_redirect_embeds_the_verified_host_and_binding(): void
    {
        $this->verifiedDomain();
        $captured = [];
        $this->fakeGoogleCapturingState($captured);

        $this->getJson('/api/v1/auth/google/redirect?role=student&binding=abcdef0123456789abcdef', [
            'Origin' => 'https://'.self::DOMAIN,
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonStructure(['url']);

        $state = $this->decodeState((string) ($captured['state'] ?? ''));
        $this->assertSame(self::DOMAIN, $state['handoff_host'] ?? null);
        $this->assertSame(
            GoogleHandoff::bindingHash('abcdef0123456789abcdef'),
            $state['bh'] ?? null,
        );
    }

    public function test_a_platform_host_keeps_the_single_host_flow(): void
    {
        $captured = [];
        $this->fakeGoogleCapturingState($captured);

        $this->getJson('/api/v1/auth/google/redirect?role=student', [
            'Origin' => 'https://'.self::PLATFORM,
            'Accept' => 'application/json',
        ])->assertOk();

        $state = $this->decodeState((string) ($captured['state'] ?? ''));
        $this->assertNull($state['handoff_host'] ?? null);
        $this->assertNull($state['bh'] ?? null);
    }

    // ── callback: mints a ticket, never a platform session ───────────────────

    public function test_the_callback_hands_off_a_single_use_ticket_to_the_teacher_host(): void
    {
        $this->verifiedDomain();
        $this->fakeGoogle('student@test.local');

        $binding = 'abcdef0123456789abcdef';
        $state = $this->signedState([
            'role' => 'student',
            'handoff_host' => self::DOMAIN,
            'bh' => GoogleHandoff::bindingHash($binding),
        ]);

        $response = $this->callbackWith($state);
        $response->assertRedirect();

        $ticket = $this->ticketFromRedirect($response);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $ticket);

        // Redeem once on the correct host + browser.
        $this->redeem('https://'.self::DOMAIN, $ticket, $binding)
            ->assertOk()
            ->assertJsonPath('user.email', 'student@test.local');
    }

    public function test_a_ticket_cannot_be_replayed(): void
    {
        $this->verifiedDomain();
        $this->fakeGoogle('student@test.local');

        $binding = 'abcdef0123456789abcdef';
        $ticket = $this->ticketFromRedirect($this->callbackWith($this->signedState([
            'role' => 'student',
            'handoff_host' => self::DOMAIN,
            'bh' => GoogleHandoff::bindingHash($binding),
        ])));

        $this->redeem('https://'.self::DOMAIN, $ticket, $binding)->assertOk();

        // Second redemption finds nothing (delete-on-read).
        $this->redeem('https://'.self::DOMAIN, $ticket, $binding)->assertStatus(410);
    }

    public function test_a_ticket_is_rejected_for_the_wrong_browser_binding(): void
    {
        $this->verifiedDomain();
        $this->fakeGoogle('student@test.local');

        $ticket = $this->ticketFromRedirect($this->callbackWith($this->signedState([
            'role' => 'student',
            'handoff_host' => self::DOMAIN,
            'bh' => GoogleHandoff::bindingHash('the-real-browser-binding'),
        ])));

        $this->redeem('https://'.self::DOMAIN, $ticket, 'a-different-browser-value')
            ->assertStatus(403);
    }

    public function test_a_ticket_minted_for_one_host_is_not_redeemable_on_another(): void
    {
        $this->verifiedDomain(self::DOMAIN);
        $this->verifiedDomain('teacher-b.com');
        $this->fakeGoogle('student@test.local');

        $binding = 'abcdef0123456789abcdef';
        $ticket = $this->ticketFromRedirect($this->callbackWith($this->signedState([
            'role' => 'student',
            'handoff_host' => self::DOMAIN,
            'bh' => GoogleHandoff::bindingHash($binding),
        ])));

        $this->redeem('https://teacher-b.com', $ticket, $binding)->assertStatus(403);
    }

    public function test_redeeming_establishes_a_session_with_the_right_tenant_context(): void
    {
        $tenant = $this->verifiedDomain();
        $email = 'student-'.strtolower(Str::random(6)).'@test.local';
        $student = User::factory()->create(['email' => $email]);
        $this->attachToTenant($student, $tenant, 'student');
        $this->fakeGoogle($email, 'Existing Student');

        $binding = 'abcdef0123456789abcdef';
        $ticket = $this->ticketFromRedirect($this->callbackWith($this->signedState([
            'role' => 'student',
            'handoff_host' => self::DOMAIN,
            'bh' => GoogleHandoff::bindingHash($binding),
        ])));

        $this->redeem('https://'.self::DOMAIN, $ticket, $binding)
            ->assertOk()
            ->assertJsonPath('tenant_id', $tenant->id);

        // The session cookie the BFF would forward now authenticates the user.
        $cookie = (string) $this->app['session']->getId();
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->withCredentials()
            ->withCookie((string) config('session.cookie'), $cookie)
            ->withHeaders(['Origin' => 'https://'.self::DOMAIN, 'Accept' => 'application/json'])
            ->getJson('/api/v1/dashboard')
            ->assertOk();
    }

    public function test_a_forged_or_expired_ticket_fails_closed(): void
    {
        $this->verifiedDomain();

        $this->redeem('https://'.self::DOMAIN, Str::random(64), 'abcdef0123456789abcdef')
            ->assertStatus(410);

        $this->redeem('https://'.self::DOMAIN, 'not-a-ticket', 'abcdef0123456789abcdef')
            ->assertStatus(410);
    }

    public function test_redeem_is_refused_from_a_platform_host(): void
    {
        $this->verifiedDomain();

        $this->redeem('https://'.self::PLATFORM, str_repeat('a', 64), 'abcdef0123456789abcdef')
            ->assertStatus(422);
    }
}
