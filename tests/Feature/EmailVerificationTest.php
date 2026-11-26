<?php

namespace Tests\Feature;

use App\Mail\PlatformNotification;
use App\Models\PlatformSetting;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Uid\UuidV7;

/**
 * Email verification, end to end at the HTTP layer.
 *
 * The emailed link carries the RAW token; the DB stores only its sha256. The
 * verify endpoint must therefore hash the incoming token before comparing it —
 * otherwise every verification link fails ("Invalid verification link") and no
 * student or teacher can ever verify their address.
 *
 * Also asserts the link returns to the teacher's OWN domain when the signup
 * started there.
 */
class EmailVerificationTest extends TenantTestCase
{
    private const DOMAIN = 'verify-teacher.com';

    /** The raw token embedded in the queued verification email. */
    private function tokenFromVerificationMail(string $email): string
    {
        $url = null;

        Mail::assertQueued(PlatformNotification::class, function (PlatformNotification $mail) use ($email, &$url) {
            if (! $mail->hasTo($email)) {
                return false;
            }

            $action = $mail->data['action_url'] ?? null;

            if (is_string($action) && str_contains($action, '/verify-email/')) {
                $url = $action;

                return true;
            }

            return false;
        });

        $this->assertIsString($url, 'The verification email must carry a /verify-email/ action URL.');

        return (string) substr($url, strrpos($url, '/verify-email/') + strlen('/verify-email/'));
    }

    private function issueVerification(User $user): string
    {
        $this->actingAs($user)->postJson('/api/v1/auth/email/resend')->assertOk();

        return $this->tokenFromVerificationMail($user->email);
    }

    public function test_the_emailed_raw_token_verifies_the_account(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'verify-me@test.local', 'email_verified_at' => null]);

        $raw = $this->issueVerification($user);
        $this->assertNotSame('', $raw);

        // The stored value is a hash, never the raw token.
        $this->assertNotSame($raw, $user->fresh()->email_verify_token);

        $this->actingAs($user)
            ->postJson('/api/v1/auth/email/verify', ['token' => $raw])
            ->assertOk()
            ->assertJsonPath('verified', true);

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->email_verified_at);
        $this->assertNull($fresh->email_verify_token);
        $this->assertNull($fresh->email_verify_token_expires_at);
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'bad-token-verify@test.local', 'email_verified_at' => null]);
        $this->issueVerification($user);

        $this->actingAs($user)
            ->postJson('/api/v1/auth/email/verify', ['token' => 'not-the-real-token'])
            ->assertStatus(422);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_an_expired_token_is_rejected(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'expired-verify@test.local', 'email_verified_at' => null]);
        $raw = $this->issueVerification($user);

        $user->forceFill(['email_verify_token_expires_at' => now()->subMinute()])->save();

        $this->actingAs($user)
            ->postJson('/api/v1/auth/email/verify', ['token' => $raw])
            ->assertStatus(422);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verification_is_idempotent_once_verified(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'already-verified@test.local', 'email_verified_at' => null]);
        $raw = $this->issueVerification($user);

        $this->actingAs($user)->postJson('/api/v1/auth/email/verify', ['token' => $raw])->assertOk();

        // A second call with the now-cleared token still reports verified=true
        // (and never errors), so a double-click / refresh is harmless.
        $this->actingAs($user)
            ->postJson('/api/v1/auth/email/verify', ['token' => $raw])
            ->assertOk()
            ->assertJsonPath('verified', true);
    }

    public function test_the_verification_link_keeps_the_teacher_domain(): void
    {
        Mail::fake();
        PlatformSetting::set('platform_domain', 'fahimni.me');
        config(['app.frontend_url' => 'https://fahimni.me']);

        $tenant = $this->makeTenant();
        TenantSetting::withoutTenant()->create([
            'id' => UuidV7::generate(),
            'tenant_id' => $tenant->id,
            'custom_domain' => self::DOMAIN,
            'metadata' => ['domain_verified' => true, 'domain_verified_at' => now()->toIso8601String()],
        ]);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'طالب',
            'email' => 'verify-on-domain@test.local',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => 'Student',
            'tenant_slug' => $tenant->slug,
        ], ['Origin' => 'https://'.self::DOMAIN])->assertSuccessful();

        $url = null;
        Mail::assertQueued(PlatformNotification::class, function (PlatformNotification $mail) use (&$url) {
            $action = $mail->data['action_url'] ?? null;
            if (is_string($action) && str_contains($action, '/verify-email/')) {
                $url = $action;

                return true;
            }

            return false;
        });

        $this->assertIsString($url);
        $this->assertStringStartsWith('https://'.self::DOMAIN.'/verify-email/', $url);
    }
}
