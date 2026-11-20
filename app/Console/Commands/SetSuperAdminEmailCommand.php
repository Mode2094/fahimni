<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * fahimni:set-super-admin-email — change the existing super-admin's login email
 * SAFELY: only the `email` column is written, so the password, roles, 2FA
 * settings, and every related row (data) stay exactly as they are.
 *
 * A fresh install seeds a placeholder admin address (admin@<domain> /
 * admin@example.com). Use this on the server to point it at the real operator
 * inbox instead of reseeding — which would touch the password.
 */
class SetSuperAdminEmailCommand extends Command
{
    protected $signature = 'fahimni:set-super-admin-email
        {email : the new super-admin login address}
        {--from= : current address (needed only if several super admins exist)}
        {--env-file= : also update SUPER_ADMIN_EMAIL in this env file}
        {--force : skip the interactive confirmation}';

    protected $description = 'Change the super-admin email, preserving the password, roles and all data.';

    public function handle(): int
    {
        $new = strtolower(trim((string) $this->argument('email')));

        if (Validator::make(['email' => $new], ['email' => 'required|email'])->fails()) {
            $this->error("\"{$new}\" is not a valid email address.");

            return self::FAILURE;
        }

        $from = $this->option('from') !== null ? strtolower(trim((string) $this->option('from'))) : null;

        $query = User::query()->where('is_super_admin', true);

        if ($from !== null) {
            $query->where('email', $from);
        }

        $admins = $query->get();

        if ($admins->isEmpty()) {
            $this->error($from !== null
                ? "No super admin with email {$from}."
                : 'No super admin found (is_super_admin = true).');

            return self::FAILURE;
        }

        if ($admins->count() > 1) {
            $this->error('Several super admins exist — disambiguate with --from=<current-email>.');
            $admins->each(fn (User $u) => $this->line('  - '.$u->email));

            return self::FAILURE;
        }

        /** @var User $admin */
        $admin = $admins->first();
        $current = strtolower((string) $admin->email);

        if ($current === $new) {
            $this->info("Super-admin email is already {$new} — nothing to change.");
            $this->maybeUpdateEnvFile($new);

            return self::SUCCESS;
        }

        if (User::where('email', $new)->whereKeyNot($admin->getKey())->exists()) {
            $this->error("Another account already uses {$new}.");

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['User id', (string) $admin->getKey()],
            ['Current email', $current],
            ['New email', $new],
            ['Password', 'UNCHANGED (preserved)'],
            ['Roles', $admin->getRoleNames()->implode(', ') ?: '(none)'],
        ]);

        if (! $this->option('force') && ! $this->confirm("Change the super-admin email from {$current} to {$new}?", false)) {
            $this->warn('Aborted — nothing changed.');

            return self::FAILURE;
        }

        // Only the email column is dirty → save() writes just that column and
        // leaves the password, roles, 2FA, and all relations untouched.
        $admin->email = $new;
        $admin->save();

        $this->info("Super-admin email changed: {$current} → {$new}");
        $this->line('Password, roles and data were NOT modified.');

        $this->maybeUpdateEnvFile($new);

        return self::SUCCESS;
    }

    /**
     * Optionally keep SUPER_ADMIN_EMAIL in the operator's env file in sync with
     * the DB — the Google-OAuth admin self-provision check reads this value.
     */
    private function maybeUpdateEnvFile(string $new): void
    {
        $path = (string) ($this->option('env-file') ?: '');

        if ($path === '') {
            return;
        }

        if (! is_file($path)) {
            $this->warn("Env file not found: {$path} — skipped.");

            return;
        }

        $contents = (string) file_get_contents($path);
        $mode = @fileperms($path) & 0777;

        if (preg_match('/^SUPER_ADMIN_EMAIL=.*$/m', $contents)) {
            $contents = preg_replace('/^SUPER_ADMIN_EMAIL=.*$/m', 'SUPER_ADMIN_EMAIL='.$new, $contents, 1);
        } else {
            $contents = rtrim($contents, "\n")."\nSUPER_ADMIN_EMAIL={$new}\n";
        }

        if (@file_put_contents($path, $contents, LOCK_EX) === false) {
            $this->warn("Could not write {$path} — update SUPER_ADMIN_EMAIL manually.");

            return;
        }

        if ($mode) {
            @chmod($path, $mode);
        }

        $this->info("Updated SUPER_ADMIN_EMAIL in {$path}");
    }
}
