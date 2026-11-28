<?php

namespace Tests\Feature;

use App\Http\Middleware\TrustTenantDomainsStatefully;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\TenantDomainResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\Uid\UuidV7;

/**
 * A teacher's own domain / subdomain must be able to run the cookie-session
 * (BFF) auth flow — without ever opening Sanctum's stateful list to an
 * arbitrary (spoofed) Origin.
 *
 * Server-side verification: only a VERIFIED custom domain of an ACTIVE tenant
 * (or a live `{slug}.{platform}` subdomain) is trusted; everything else keeps
 * the default (stateless) behaviour.
 */
class TenantDomainSessionTest extends TenantTestCase
{
    private const PLATFORM = 'fahimni.me';

    protected function setUp(): void
    {
        parent::setUp();

        PlatformSetting::set('platform_domain', self::PLATFORM);

        // Re-pin the static list the way production does (the env var is unset
        // in the test runner, so the config default would be localhost only).
        config(['sanctum.stateful' => [self::PLATFORM, 'www.'.self::PLATFORM, 'api.'.self::PLATFORM]]);
    }

    private function verifiedDomain(string $domain): Tenant
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

    private function unverifiedDomain(string $domain): Tenant
    {
        $tenant = $this->makeTenant();

        TenantSetting::withoutTenant()->create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'custom_domain' => $domain,
            'metadata' => ['domain_verified' => false],
        ]);

        return $tenant;
    }

    /** Run the trust middleware for one request and report the final list. */
    private function statefulAfter(string $origin): array
    {
        // Start from the platform-only baseline so calls never leak into each
        // other through the shared config repository.
        config(['sanctum.stateful' => [self::PLATFORM, 'www.'.self::PLATFORM, 'api.'.self::PLATFORM]]);

        $request = Request::create('/api/v1/auth/me', 'GET', [], [], [], ['HTTP_ORIGIN' => $origin]);
        app(TrustTenantDomainsStatefully::class)->handle($request, fn () => response('ok'));

        return config('sanctum.stateful', []);
    }

    private function statefulFrom(string $origin): bool
    {
        $this->statefulAfter($origin);
        $request = Request::create('/api/v1/auth/me', 'GET', [], [], [], ['HTTP_ORIGIN' => $origin]);

        return EnsureFrontendRequestsAreStateful::fromFrontend($request);
    }

    public function test_a_verified_custom_domain_becomes_stateful(): void
    {
        $this->verifiedDomain('teacher-ahmad.com');

        $this->assertContains('teacher-ahmad.com', $this->statefulAfter('https://teacher-ahmad.com'));
        $this->assertTrue($this->statefulFrom('https://teacher-ahmad.com'));
    }

    public function test_the_www_variant_of_a_verified_domain_is_stateful(): void
    {
        $this->verifiedDomain('teacher-ahmad.com');

        $this->assertTrue($this->statefulFrom('https://www.teacher-ahmad.com'));
    }

    public function test_a_connected_but_unverified_domain_is_never_trusted(): void
    {
        $this->unverifiedDomain('pending-teacher.com');

        $this->assertNotContains('pending-teacher.com', $this->statefulAfter('https://pending-teacher.com'));
        $this->assertFalse($this->statefulFrom('https://pending-teacher.com'));
    }

    public function test_a_foreign_or_spoofed_origin_is_never_trusted(): void
    {
        // No tenant at all — an attacker host, and a look-alike of the platform.
        $this->assertNotContains('attacker.example', $this->statefulAfter('https://attacker.example'));
        $this->assertNotContains('fahimni.me.evil.com', $this->statefulAfter('https://fahimni.me.evil.com'));
        $this->assertFalse($this->statefulFrom('https://fahimni.me.evil.com'));
        $this->assertFalse($this->statefulFrom('https://evil.fahimni.me.attacker.com'));
    }

    public function test_an_active_teacher_subdomain_is_stateful_but_an_unknown_one_is_not(): void
    {
        $tenant = $this->makeTenant();
        $tenant->update(['slug' => 'live-academy']);

        $this->assertTrue($this->statefulFrom('https://live-academy.'.self::PLATFORM));
        $this->assertFalse($this->statefulFrom('https://nobody-here.'.self::PLATFORM));
    }

    public function test_a_suspended_tenant_domain_is_no_longer_trusted(): void
    {
        $tenant = $this->verifiedDomain('suspended-teacher.com');
        $this->assertTrue($this->statefulFrom('https://suspended-teacher.com'));

        $tenant->update(['status' => 'suspended']);

        $this->assertFalse($this->statefulFrom('https://suspended-teacher.com'));
    }

    public function test_two_teacher_domains_resolve_to_their_own_tenants_only(): void
    {
        $a = $this->verifiedDomain('teacher-a.com');
        $b = $this->verifiedDomain('teacher-b.com');

        $resolver = app(TenantDomainResolver::class);
        $this->assertTrue($resolver->belongsToActiveTenant('teacher-a.com'));
        $this->assertTrue($resolver->belongsToActiveTenant('teacher-b.com'));

        // Never a sibling domain, and never a suffix look-alike.
        $this->assertFalse($resolver->belongsToActiveTenant('teacher-c.com'));
        $this->assertFalse($resolver->belongsToActiveTenant('teacher-a.com.evil.com'));
        $this->assertFalse($resolver->belongsToActiveTenant('xteacher-a.com'));

        $this->assertNotSame($a->id, $b->id);
    }

    public function test_the_platform_hosts_keep_their_static_stateful_behaviour(): void
    {
        // No DB row at all: the platform's own hosts come from config.
        $this->assertContains(self::PLATFORM, $this->statefulAfter('https://'.self::PLATFORM));
        $this->assertTrue($this->statefulFrom('https://'.self::PLATFORM));
        $this->assertTrue($this->statefulFrom('https://api.'.self::PLATFORM));
    }

    public function test_a_spoofed_host_header_alone_never_grants_stateful_trust(): void
    {
        $this->verifiedDomain('teacher-ahmad.com');

        // Trust is derived from Referer/Origin ONLY, exactly like Sanctum. A
        // forged Host / X-Forwarded-Host (which the browser cannot set anyway)
        // must not be enough to make a foreign request stateful.
        $request = Request::create('/api/v1/auth/me', 'GET', [], [], [], [
            'HTTP_HOST' => 'teacher-ahmad.com',
            'HTTP_X_FORWARDED_HOST' => 'teacher-ahmad.com',
        ]);
        app(TrustTenantDomainsStatefully::class)->handle($request, fn () => response('ok'));

        $this->assertNotContains('teacher-ahmad.com', config('sanctum.stateful', []));
    }

    public function test_a_referer_header_is_honoured_like_sanctum_does(): void
    {
        $this->verifiedDomain('teacher-ahmad.com');

        $request = Request::create('/api/v1/auth/me', 'GET', [], [], [], [
            'HTTP_REFERER' => 'https://teacher-ahmad.com/courses',
        ]);
        app(TrustTenantDomainsStatefully::class)->handle($request, fn () => response('ok'));

        $this->assertContains('teacher-ahmad.com', config('sanctum.stateful'));
    }

    public function test_the_session_cookie_is_set_for_a_verified_domain_origin_and_not_for_a_foreign_one(): void
    {
        $this->verifiedDomain('teacher-ahmad.com');

        $trusted = $this->getJson('/api/v1/auth/me', [
            'Origin' => 'https://teacher-ahmad.com',
            'Accept' => 'application/json',
        ]);
        $this->assertNotNull(
            $trusted->getCookie((string) config('session.cookie')),
            'A verified teacher domain must receive the session cookie (stateful flow).',
        );

        $foreign = $this->getJson('/api/v1/auth/me', [
            'Origin' => 'https://attacker.example',
            'Accept' => 'application/json',
        ]);
        $this->assertNull(
            $foreign->getCookie((string) config('session.cookie')),
            'A foreign origin must never start a stateful session.',
        );
    }

    public function test_a_session_established_on_one_teacher_domain_cannot_reach_another_tenant(): void
    {
        $tenantA = $this->verifiedDomain('teacher-a.com');
        $tenantB = $this->makeTenant();

        $user = User::factory()->create([
            'email' => 'student-'.strtolower(Str::random(8)).'@test.local',
            'password' => bcrypt('password-123'),
        ]);
        $this->attachToTenant($user, $tenantA, 'student');

        // The login itself runs stateful BECAUSE the domain is verified — this
        // exercises the middleware end-to-end, not just the predicate.
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password-123',
        ], ['Origin' => 'https://teacher-a.com', 'Accept' => 'application/json']);

        $login->assertOk();
        $cookie = (string) $this->app['session']->getId();

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        // Pointing the same session at ANOTHER tenant must be refused: the
        // backend authorizes by membership, never by a client-supplied id.
        $this->withCredentials()
            ->withCookie((string) config('session.cookie'), $cookie)
            ->withHeaders([
                'Origin' => 'https://teacher-a.com',
                'Accept' => 'application/json',
                'X-Tenant-Id' => $tenantB->id,
            ])
            ->getJson('/api/v1/dashboard')
            ->assertStatus(403);
    }
}
