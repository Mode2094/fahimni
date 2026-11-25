<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Regression guard for the "orphan teacher account" defect (audit 2026-11-25).
 *
 * The gate that blocks teacher self-registration (when
 * `teacher_registration_enabled` is off and no invitation exists) used to run
 * *after* `User::create()`. A rejected sign-up therefore left a role-less,
 * tenant-less user row that also reserved the e-mail address, so the owner
 * could never register with it again.
 *
 * The gate now runs before the user row is written.
 */
class TeacherRegistrationGateTest extends TenantTestCase
{
    private function freshEmail(string $prefix = 'gate'): string
    {
        return $prefix.'-'.strtolower(Str::random(8)).'@test.local';
    }

    /** @return array<string, string> */
    private function payload(string $email, string $role): array
    {
        $payload = [
            'name' => 'Gate Test',
            'email' => $email,
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'role' => $role,
        ];

        if ($role === 'Teacher') {
            $payload['phone'] = '0791234567';
        }

        return $payload;
    }

    public function test_gate_rejected_teacher_registration_creates_no_orphan_account(): void
    {
        Role::findOrCreate('Teacher', 'web');
        PlatformSetting::set('teacher_registration_enabled', false);

        $email = $this->freshEmail();

        $this->postJson('/api/v1/auth/register', $this->payload($email, 'Teacher'))
            ->assertStatus(403);

        $this->assertSame(
            0,
            User::withTrashed()->where('email', $email)->count(),
            'a gate-rejected teacher signup must not leave an orphan account behind'
        );

        // The address must remain free for the owner's legitimate signup.
        $this->postJson('/api/v1/auth/register', $this->payload($email, 'Student'))
            ->assertStatus(201);

        $this->assertSame(1, User::where('email', $email)->count());
    }

    public function test_unknown_public_tenant_creates_no_orphan_account(): void
    {
        $email = $this->freshEmail('tenant');

        $payload = $this->payload($email, 'Student');
        $payload['tenant_slug'] = 'does-not-exist-'.Str::random(6);

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertStatus(404);

        $this->assertSame(
            0,
            User::withTrashed()->where('email', $email)->count(),
            'a signup for an unknown workspace must not create an account'
        );
    }
}
