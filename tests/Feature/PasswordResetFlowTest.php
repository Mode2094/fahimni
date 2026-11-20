<?php

namespace Tests\Feature;

use App\Mail\PlatformNotification;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The full password-reset cycle, end to end at the HTTP layer:
 *
 *   request → a real reset email whose link points at the frontend page →
 *   set a new password with the token → log in with it.
 *
 * Plus the guarantees that keep it safe: uniform responses (no account
 * enumeration), the honesty flag derived from mailer config only, token
 * expiry/reuse rejection, and that a mailer failure never leaks or 500s.
 */
class PasswordResetFlowTest extends TestCase
{
    private function user(string $email = 'reset-user@test.local'): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => Hash::make('old-password-123'),
        ]);
    }

    /** The token embedded in the queued reset email. */
    private function tokenFromQueuedMail(string $email): string
    {
        $url = null;

        Mail::assertQueued(PlatformNotification::class, function (PlatformNotification $mail) use ($email, &$url) {
            if (! $mail->hasTo($email)) {
                return false;
            }
            $url = $mail->data['action_url'] ?? null;

            return true;
        });

        $this->assertIsString($url, 'The reset email must carry an action URL.');
        $this->assertStringContainsString('/reset-password?', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame($email, $query['email'] ?? null, 'Reset link must carry the account email.');

        return (string) ($query['token'] ?? '');
    }

    public function test_reset_email_points_at_the_frontend_and_token_sets_a_new_password(): void
    {
        Mail::fake();
        $user = $this->user();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonPath('delivery', 'unconfigured'); // test mailer is `array`

        $token = $this->tokenFromQueuedMail($user->email);
        $this->assertNotSame('', $token);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password-456',
            'password_confirmation' => 'new-password-456',
        ])->assertOk()->assertJsonPath('login_path', '/student/login');

        $this->assertTrue(Hash::check('new-password-456', $user->fresh()->password));
        $this->assertFalse(Hash::check('old-password-123', $user->fresh()->password));

        // The new password actually works for login.
        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'new-password-456',
        ])->assertOk();
    }

    public function test_unknown_email_returns_the_same_200_shape_and_sends_nothing(): void
    {
        Mail::fake();
        $this->user('known@test.local');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody-xyz@test.local'])
            ->assertOk()
            ->assertJsonStructure(['message', 'delivery'])
            ->assertJsonMissingPath('exists');

        Mail::assertNothingQueued();
    }

    public function test_delivery_signal_follows_mailer_config_not_account_existence(): void
    {
        Mail::fake();
        $this->user('configured@test.local');

        // A deliverable mailer → both a real and an unknown address say `email`.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.mailgun.org']);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'configured@test.local'])
            ->assertOk()->assertJsonPath('delivery', 'email');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@test.local'])
            ->assertOk()->assertJsonPath('delivery', 'email');

        // A logging mailer → both say `unconfigured` (no email could arrive).
        config(['mail.default' => 'log']);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'configured@test.local'])
            ->assertOk()->assertJsonPath('delivery', 'unconfigured');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@test.local'])
            ->assertOk()->assertJsonPath('delivery', 'unconfigured');
    }

    public function test_a_reset_token_cannot_be_used_twice(): void
    {
        Mail::fake();
        $user = $this->user('reuse@test.local');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $token = $this->tokenFromQueuedMail($user->email);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'first-new-pass',
            'password_confirmation' => 'first-new-pass',
        ])->assertOk();

        // Replaying the same token must fail.
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'second-new-pass',
            'password_confirmation' => 'second-new-pass',
        ])->assertStatus(400);
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $user = $this->user('bad-token@test.local');

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => 'not-a-real-token',
            'password' => 'whatever-123',
            'password_confirmation' => 'whatever-123',
        ])->assertStatus(400);

        $this->assertTrue(Hash::check('old-password-123', $user->fresh()->password));
    }

    public function test_password_confirmation_must_match(): void
    {
        Mail::fake();
        $user = $this->user('mismatch@test.local');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $token = $this->tokenFromQueuedMail($user->email);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-password-456',
            'password_confirmation' => 'different-789',
        ])->assertStatus(422);
    }

    public function test_arabic_locale_returns_arabic_password_messages(): void
    {
        // Production ships APP_LOCALE=ar, so the reset endpoint must answer in
        // Arabic (lang/ar/passwords.php) — not fall back to English.
        app()->setLocale('ar');

        $user = $this->user('arabic@test.local');

        $res = $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => 'not-a-real-token',
            'password' => 'whatever-123',
            'password_confirmation' => 'whatever-123',
        ])->assertStatus(400);

        $this->assertStringContainsString('غير صالح', (string) $res->json('message'));
        $this->assertStringNotContainsString('invalid', (string) $res->json('message'));
    }

    public function test_forgot_password_is_uniform_even_when_the_mailer_fails(): void
    {
        // An unreachable SMTP host makes the send throw; the endpoint must still
        // answer 200 with the same shape (no 500, no account-existence oracle).
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 1,
        ]);

        $user = $this->user('mailer-down@test.local');

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])
            ->assertOk()
            ->assertJsonStructure(['message', 'delivery']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@test.local'])
            ->assertOk()
            ->assertJsonStructure(['message', 'delivery']);
    }
}
