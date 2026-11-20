<?php

namespace Tests\Feature;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The production fix for `cards.export` must be strictly additive: it may add
 * the missing permission and grant it to Teacher, but it must never remove or
 * rewrite any other permission a database already holds (that is exactly why
 * the full RolePermissionSeeder — which uses syncPermissions() — must not be
 * used as a production hot-fix).
 */
class EnsureCardsExportPermissionTest extends TestCase
{
    private function seedRoles(): void
    {
        $this->seed(RolePermissionSeeder::class);
    }

    private function teacher(): Role
    {
        return Role::query()->where('name', 'Teacher')->where('guard_name', 'web')->firstOrFail();
    }

    private function teacherPermissionNames(): array
    {
        return $this->teacher()->permissions()->pluck('name')->sort()->values()->all();
    }

    public function test_dry_run_makes_no_change(): void
    {
        $this->seedRoles();
        Permission::query()->where('name', 'cards.export')->delete();

        $before = DB::table('permissions')->count();

        $this->artisan('permissions:ensure-cards-export')->assertSuccessful();

        $this->assertSame($before, DB::table('permissions')->count(), 'dry-run must not create anything');
        $this->assertFalse(
            Permission::query()->where('name', 'cards.export')->exists(),
            'dry-run must not create the permission',
        );
    }

    public function test_apply_creates_and_grants_without_touching_other_permissions(): void
    {
        $this->seedRoles();
        $teacher = $this->teacher();

        // A permission an admin granted by hand — the fix must preserve it.
        $custom = Permission::create(['name' => 'custom.admin.grant', 'guard_name' => 'web']);
        $teacher->givePermissionTo('custom.admin.grant');

        // Simulate production where cards.export is missing entirely.
        Permission::query()->where('name', 'cards.export')->delete();
        $before = $this->teacherPermissionNames();
        $this->assertNotContains('cards.export', $before);

        $this->artisan('permissions:ensure-cards-export', ['--apply' => true])->assertSuccessful();

        $after = $this->teacherPermissionNames();

        $this->assertContains('cards.export', $after, 'cards.export must be granted to Teacher');
        $this->assertContains('custom.admin.grant', $after, 'a hand-granted permission must be preserved');
        // Every permission Teacher had before must still be there (pure additive).
        $this->assertSame([], array_values(array_diff($before, $after)), 'no existing Teacher permission may be lost');
        $this->assertTrue($custom->fresh() !== null);
    }

    public function test_apply_is_idempotent(): void
    {
        $this->seedRoles();

        $this->artisan('permissions:ensure-cards-export', ['--apply' => true])->assertSuccessful();
        $first = $this->teacherPermissionNames();
        $permCount = DB::table('permissions')->count();

        // Second run must change nothing.
        $this->artisan('permissions:ensure-cards-export', ['--apply' => true])->assertSuccessful();

        $this->assertSame($first, $this->teacherPermissionNames(), 'Teacher permissions must be stable across runs');
        $this->assertSame($permCount, DB::table('permissions')->count(), 'no duplicate permission rows');
        $this->assertSame(
            1,
            Permission::query()->where('name', 'cards.export')->count(),
            'exactly one cards.export permission row',
        );
    }

    public function test_refuses_when_role_is_missing(): void
    {
        // No seeding: the Teacher role does not exist yet.
        Permission::query()->where('name', 'cards.export')->delete();

        $this->artisan('permissions:ensure-cards-export', ['--apply' => true])->assertFailed();
    }

    public function test_other_roles_are_not_modified(): void
    {
        $this->seedRoles();
        $student = Role::query()->where('name', 'Student')->where('guard_name', 'web')->firstOrFail();
        $before = $student->permissions()->pluck('name')->sort()->values()->all();

        Permission::query()->where('name', 'cards.export')->delete();
        $this->artisan('permissions:ensure-cards-export', ['--apply' => true])->assertSuccessful();

        $after = $student->fresh()->permissions()->pluck('name')->sort()->values()->all();
        $this->assertSame($before, $after, 'the Student role must be untouched');
        $this->assertNotContains('cards.export', $after, 'cards.export is a teacher permission, not a student one');
    }
}
