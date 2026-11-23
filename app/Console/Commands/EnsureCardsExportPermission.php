<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Additive-only fix for the `cards.export` permission.
 *
 * Why this exists
 * ---------------
 * `RolePermissionSeeder` uses `syncPermissions()`, which REPLACES a role's
 * permissions with the seeder's list. Running it on production could therefore
 * drop any permission an admin granted by hand. This command does the one
 * thing the release needs — make sure `cards.export` exists and that the
 * Teacher role holds it — and touches nothing else:
 *
 *   • creates the permission only when it is missing,
 *   • grants it to Teacher only when the role does not already hold it,
 *   • never removes, rewrites or re-syncs any other permission,
 *   • is idempotent (a second run changes nothing),
 *   • prints the exact expected diff and, by default, makes NO change.
 *
 * Safe usage:
 *   php artisan permissions:ensure-cards-export           # dry-run (default)
 *   php artisan permissions:ensure-cards-export --apply   # writes
 */
class EnsureCardsExportPermission extends Command
{
    protected $signature = 'permissions:ensure-cards-export
                            {--apply : create the permission / grant it to Teacher (default is a dry-run)}
                            {--guard=web : permission guard to inspect}';

    protected $description = 'Idempotently ensure the cards.export permission exists and is granted to the Teacher role, without touching any other permission.';

    public function handle(): int
    {
        $guard = (string) $this->option('guard');
        $apply = (bool) $this->option('apply');

        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $role = Role::query()->where('name', 'Teacher')->where('guard_name', $guard)->first();

        if (! $role) {
            $this->error("Role 'Teacher' (guard {$guard}) does not exist — refusing to act on a database that has not been seeded.");

            return self::FAILURE;
        }

        $permission = Permission::query()->where('name', 'cards.export')->where('guard_name', $guard)->first();

        $action = 'none';
        $willCreate = false;
        $willGrant = false;

        if (! $permission) {
            $willCreate = true;
        } elseif (! $role->hasPermissionTo('cards.export')) {
            $willGrant = true;
        }

        // ── expected diff (always printed, before any write) ──────────────
        $this->line($apply ? 'Applying the targeted permission change.' : 'DRY-RUN — no changes will be written.');
        $this->line('  guard                 : '.$guard);
        $this->line('  Teacher role          : found (#'.$role->id.')');
        $this->line('  cards.export exists   : '.($permission ? 'yes' : 'no — will create'));
        $this->line('  Teacher holds it      : '.($permission && $role->hasPermissionTo('cards.export') ? 'yes' : 'no — will grant'));
        $this->line('  Teacher permission set: '.$role->permissions()->count().' (preserved as-is)');

        if (! $apply) {
            $this->newLine();
            $this->info($this->plannedSummary($willCreate, $willGrant).' Re-run with --apply to execute.');

            return self::SUCCESS;
        }

        if ($willCreate) {
            $permission = Permission::create(['name' => 'cards.export', 'guard_name' => $guard]);
            $registrar->forgetCachedPermissions();
            $action = 'created+granted';
        }

        if ($permission && ! $role->hasPermissionTo('cards.export')) {
            // givePermissionTo ADDS one permission; it is not syncPermissions().
            $role->givePermissionTo('cards.export');
            $registrar->forgetCachedPermissions();
            $action = $willCreate ? 'created+granted' : 'granted';
        }

        $fresh = Role::query()->where('name', 'Teacher')->where('guard_name', $guard)->first();

        $this->newLine();
        $this->info(sprintf(
            'done (%s) — Teacher now holds %d permission(s), cards.export=%s',
            $action,
            $fresh ? $fresh->permissions()->count() : -1,
            ($fresh && $fresh->hasPermissionTo('cards.export')) ? 'yes' : 'no',
        ));
        $this->line('  no other permission was added, removed or modified.');

        return self::SUCCESS;
    }

    private function plannedSummary(bool $willCreate, bool $willGrant): string
    {
        return match (true) {
            $willCreate => 'PLAN: create cards.export and grant it to Teacher.',
            $willGrant => 'PLAN: grant the existing cards.export to Teacher.',
            default => 'PLAN: nothing to do — already in place.',
        };
    }
}
