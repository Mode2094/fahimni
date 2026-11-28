<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\MathCaptcha;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Regression guard for the admin/teacher/student CAPTCHA gate.
 *
 * The bug this pins: the API already rejected email login/registration when
 * `captcha_enabled` was on, but NO frontend form rendered a CAPTCHA field — so
 * enabling the setting locked every password login out with a bare
 * "Invalid captcha answer." The server contract below is what the (now wired)
 * forms must satisfy.
 */
class CaptchaAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The array cache store lives for the whole PHP process, while each
        // test rolls its DB writes back — flush it so a cached
        // `platform_settings.*` value can never leak between tests.
        Cache::flush();
    }

    private function enableCaptcha(): void
    {
        PlatformSetting::set('captcha_enabled', true);
    }

    /** Issue a fresh challenge and read its expected answer (create() keeps it). */
    private function freshChallenge(): array
    {
        $challenge = MathCaptcha::create();
        $answer = Cache::get('captcha:'.$challenge['captcha_id']);

        return [$challenge['captcha_id'], (string) $answer];
    }

    private function makeUser(string $password = 'password-123'): User
    {
        return User::factory()->create([
            'email' => 'captcha-'.uniqid().'@test.local',
            'password' => bcrypt($password),
        ]);
    }

    public function test_login_requires_a_valid_captcha_when_enabled(): void
    {
        $this->enableCaptcha();
        $user = $this->makeUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password-123',
        ])->assertStatus(422)->assertJsonPath('message', 'Invalid captcha answer.');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password-123',
            'captcha_id' => 'not-a-real-id',
            'captcha_answer' => '0',
        ])->assertStatus(422)->assertJsonPath('message', 'Invalid captcha answer.');
    }

    public function test_login_succeeds_with_the_correct_captcha(): void
    {
        $this->enableCaptcha();
        $user = $this->makeUser();

        [$id, $answer] = $this->freshChallenge();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password-123',
            'captcha_id' => $id,
            'captcha_answer' => $answer,
        ]);

        $response->assertOk()->assertJsonPath('user.email', $user->email);
    }

    public function test_a_captcha_cannot_be_replayed(): void
    {
        $this->enableCaptcha();
        $user = $this->makeUser();

        [$id, $answer] = $this->freshChallenge();

        $payload = [
            'email' => $user->email,
            'password' => 'password-123',
            'captcha_id' => $id,
            'captcha_answer' => $answer,
        ];

        $this->postJson('/api/v1/auth/login', $payload)->assertOk();
        // Single-use: the same challenge id must never verify twice.
        $this->postJson('/api/v1/auth/login', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Invalid captcha answer.');
    }

    public function test_login_ignores_captcha_when_disabled(): void
    {
        // Default state: no row (or a false row) — no write needed.
        PlatformSetting::forgetCache('captcha_enabled');
        $user = $this->makeUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password-123',
        ])->assertOk();
    }

    public function test_register_requires_a_valid_captcha_when_enabled(): void
    {
        $this->enableCaptcha();
        PlatformSetting::set('registration_enabled', true);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'New Teacher',
            'email' => 'new-teacher-'.uniqid().'@test.local',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => 'Teacher',
            'phone' => '+962791234567',
        ])->assertStatus(422)->assertJsonPath('message', 'Invalid captcha answer.');
    }
}
