<?php

namespace Tests\Feature;

use App\Mail\PlatformNotification;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\RequestFrontend;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Uid\UuidV7;

/**
 * Outgoing email links must return to the teacher's OWN domain when the request
 * started there — not to the platform. Otherwise a student who signed up or
 * reset a password on `teacher.com` is dropped onto the platform host and loses
 * the teacher's context (and branding).
 *
 * The host is trusted ONLY after the domain resolver confirms it is a live
 * tenant address; everything else falls back to the platform frontend URL.
 */
class EmailHostContextTest extends TenantTestCase
{
    private const PLATFORM = 'fahimni.me';

    private const DOMAIN = 'teacher-links.com';

    protected function setUp(): void
    {
        parent::setUp();

        PlatformSetting::set('platform_domain', self::PLATFORM);
        config(['app.frontend_url' => 'https://'.self::PLATFORM]);
    }

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

    private function requestFrom(string $origin): Request
    {
        return Request::create('/', 'POST', [], [], [], ['HTTP_ORIGIN' => $origin]);
    }

    // ── helper resolution ───────────────────────────────────────────────────

    public function test_a_live_teacher_domain_is_used_as_the_link_base(): void
    {
        $this->verifiedDomain();

        $this->assertSame(
            'https://'.self::DOMAIN,
            RequestFrontend::baseUrl($this->requestFrom('https://'.self::DOMAIN)),
        );
    }

    public function test_the_platform_host_falls_back_to_the_configured_frontend(): void
    {
        $this->assertSame(
            'https://'.self::PLATFORM,
            RequestFrontend::baseUrl($this->requestFrom('https://'.self::PLATFORM)),
        );
    }

    public function test_an_unknown_host_falls_back_to_the_configured_frontend(): void
    {
        // Never a tenant value: an arbitrary Origin must not be able to make the
        // platform emit attacker-controlled links.
        $this->assertSame(
            'https://'.self::PLATFORM,
            RequestFrontend::baseUrl($this->requestFrom('https://evil.example.com')),
        );
    }

    public function test_a_verified_www_variant_is_used_as_the_link_base(): void
    {
        $this->verifiedDomain();

        $this->assertSame(
            'https://www.'.self::DOMAIN,
            RequestFrontend::baseUrl($this->requestFrom('https://www.'.self::DOMAIN)),
        );
    }

    // ── password reset email ────────────────────────────────────────────────

    public function test_password_reset_link_returns_to_the_teacher_domain(): void
    {
        Mail::fake();
        $this->verifiedDomain();

        $user = User::factory()->create([
            'email' => 'reset-on-domain@test.local',
            'password' => Hash::make('old-password-123'),
        ]);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email], [
            'Origin' => 'https://'.self::DOMAIN,
        ])->assertOk();

        $url = null;
        Mail::assertQueued(PlatformNotification::class, function (PlatformNotification $mail) use (&$url) {
            $url = $mail->data['action_url'] ?? null;

            return true;
        });

        $this->assertIsString($url);
        $this->assertStringStartsWith('https://'.self::DOMAIN.'/reset-password?', $url);
    }

    public function test_password_reset_link_stays_on_the_platform_for_a_platform_request(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'reset-on-platform@test.local',
            'password' => Hash::make('old-password-123'),
        ]);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email], [
            'Origin' => 'https://'.self::PLATFORM,
        ])->assertOk();

        $url = null;
        Mail::assertQueued(PlatformNotification::class, function (PlatformNotification $mail) use (&$url) {
            $url = $mail->data['action_url'] ?? null;

            return true;
        });

        $this->assertStringStartsWith('https://'.self::PLATFORM.'/reset-password?', (string) $url);
    }
}
