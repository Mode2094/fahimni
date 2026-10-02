<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Uid\UuidV7;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SUPER_ADMIN_EMAIL');
        $password = env('SUPER_ADMIN_PASSWORD');

        if (! $email || ! $password) {
            Log::warning('SuperAdminSeeder skipped: set SUPER_ADMIN_EMAIL and SUPER_ADMIN_PASSWORD in .env');

            return;
        }

        // Fail closed on FRESH production installs: never create an admin
        // with a known placeholder/weak password. When the admin already
        // exists the seeder only warns and leaves the account untouched —
        // so a weak value in .env can never break the deploy pipeline.
        $placeholder = ['CHANGE_ME', 'CHANGE_ME_STRONG', 'password', '12345678'];
        $isWeak = in_array(strtolower(trim($password)), $placeholder, true) || strlen($password) < 8;

        if ($isWeak && app()->environment('production') && ! User::where('email', $email)->exists()) {
            Log::error('SuperAdminSeeder aborted: SUPER_ADMIN_PASSWORD is a placeholder or too weak for a fresh production admin.');
            throw new \RuntimeException('SuperAdminSeeder aborted: SUPER_ADMIN_PASSWORD must be a strong, non-placeholder value.');
        }

        if ($isWeak) {
            Log::warning('SuperAdminSeeder: SUPER_ADMIN_PASSWORD is weak — existing admin account was left unchanged.');
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'id' => UuidV7::generate(),
                'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
                'password' => Hash::make($password),
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        // is_super_admin is intentionally NOT mass-assignable (privilege flag),
        // so it must be set with forceFill at this trusted site. The old
        // mass-assignment here was silently dropped, producing a fresh
        // "super admin" who could not reach the admin panel.
        if ($user->wasRecentlyCreated || env('SUPER_ADMIN_RESET_PASSWORD', false)) {
            $user->forceFill([
                'password' => Hash::make($password),
                'is_super_admin' => true,
                'is_active' => true,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();
        }

        $user->assignRole('Super Admin');
    }
}
