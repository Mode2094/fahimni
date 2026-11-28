<?php

namespace Tests\Feature;

use App\Mail\PlatformNotification;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The operator commands that make launch/production handling safe:
 *  • fahimni:mail-test          — proves real (or honestly-not-real) delivery
 *  • fahimni:set-super-admin-email — changes the admin login without touching
 *                                    the password, roles or any data.
 */
class AuthCommandsTest extends TestCase
{
    private function superAdmin(string $email = 'old-admin@test.local', string $password = 'secret-pass-123'): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'password' => Hash::make($password),
        ]);
        $user->forceFill(['is_super_admin' => true])->save();

        return $user;
    }

    public function test_mail_test_reports_failure_for_a_non_delivering_mailer(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);

        $this->artisan('fahimni:mail-test', ['to' => 'ops@test.local'])
            ->assertExitCode(1);
    }

    public function test_mail_test_succeeds_for_a_real_mailer_and_sends_the_message(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.mailgun.org']);

        $this->artisan('fahimni:mail-test', ['to' => 'ops@test.local'])
            ->assertExitCode(0);

        Mail::assertSent(PlatformNotification::class, fn (PlatformNotification $m) => $m->hasTo('ops@test.local'));
    }

    public function test_mail_test_rejects_an_invalid_recipient(): void
    {
        $this->artisan('fahimni:mail-test', ['to' => 'not-an-email'])
            ->assertExitCode(1);
    }

    public function test_set_super_admin_email_changes_only_the_email(): void
    {
        $admin = $this->superAdmin('old-admin@test.local');

        $this->artisan('fahimni:set-super-admin-email', [
            'email' => 'new-admin@test.local',
            '--force' => true,
        ])->assertExitCode(0);

        $fresh = $admin->fresh();
        $this->assertSame('new-admin@test.local', $fresh->email);
        $this->assertTrue($fresh->is_super_admin);
        // Password is untouched (only the email column was dirty).
        $this->assertTrue(Hash::check('secret-pass-123', $fresh->password));
    }

    public function test_set_super_admin_email_refuses_an_address_already_in_use(): void
    {
        $admin = $this->superAdmin('old-admin@test.local');
        User::factory()->create(['email' => 'taken@test.local']);

        $this->artisan('fahimni:set-super-admin-email', [
            'email' => 'taken@test.local',
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertSame('old-admin@test.local', $admin->fresh()->email);
    }

    public function test_set_super_admin_email_can_sync_the_env_file(): void
    {
        $this->superAdmin('old-admin@test.local');

        $envFile = tempnam(sys_get_temp_dir(), 'fahimni-env-');
        file_put_contents($envFile, "APP_ENV=production\nSUPER_ADMIN_EMAIL=old-admin@test.local\nAPP_DEBUG=false\n");

        try {
            $this->artisan('fahimni:set-super-admin-email', [
                'email' => 'new-admin@test.local',
                '--env-file' => $envFile,
                '--force' => true,
            ])->assertExitCode(0);

            $contents = (string) file_get_contents($envFile);
            $this->assertStringContainsString('SUPER_ADMIN_EMAIL=new-admin@test.local', $contents);
            $this->assertStringContainsString('APP_ENV=production', $contents);
            $this->assertStringContainsString('APP_DEBUG=false', $contents);
        } finally {
            @unlink($envFile);
        }
    }
}
