<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'system.manage',
            'tenants.view', 'tenants.create', 'tenants.edit', 'tenants.suspend', 'tenants.delete',
            'plans.create', 'plans.edit', 'plans.delete',
            'subscriptions.override',
            'users.manage',
            'settings.manage',
            'analytics.view', 'reports.export',
            'profile.edit', 'profile.view',
            'courses.create', 'courses.edit', 'courses.delete', 'courses.publish',
            'sections.manage',
            'lessons.manage',
            'content.video.add', 'content.file.add', 'content.text.edit',
            'students.view', 'students.enroll', 'students.remove',
            'cards.generate', 'cards.export', 'cards.view', 'cards.redeem', 'cards.manage',
            'questions.manage', 'questions.create', 'questions.edit', 'questions.delete',
            'exams.create', 'exams.edit', 'exams.update', 'exams.delete', 'exams.view', 'exams.grade', 'exams.view_results', 'exams.attempt',
            'analytics.view_own',
            'live.create', 'live.manage',
            'settings.manage_own', 'branding.customize',
            'plans.manage', 'subscriptions.manage', 'payments.view',
            'invoices.manage', 'transactions.view', 'features.manage',
            'tenant.settings',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $adminRole = Role::findOrCreate('Super Admin', 'web');

        $teacherRole = Role::findOrCreate('Teacher', 'web');
        $teacherPermissions = [
            'profile.edit', 'profile.view',
            'courses.create', 'courses.edit', 'courses.delete', 'courses.publish',
            'sections.manage',
            'lessons.manage',
            'content.video.add', 'content.file.add', 'content.text.edit',
            'students.view', 'students.enroll', 'students.remove',
            'cards.generate', 'cards.export', 'cards.view', 'cards.manage',
            'questions.manage', 'questions.create', 'questions.edit', 'questions.delete',
            'exams.create', 'exams.edit', 'exams.update', 'exams.delete', 'exams.view', 'exams.grade', 'exams.view_results',
            'analytics.view_own',
            'live.create', 'live.manage',
            'settings.manage_own', 'branding.customize',
            'tenant.settings',
        ];

        $studentRole = Role::findOrCreate('Student', 'web');
        $studentPermissions = [
            'profile.edit', 'profile.view',
            'cards.redeem',
            'exams.view', 'exams.attempt',
        ];

        $adminRole->syncPermissions(Permission::all());
        $teacherRole->syncPermissions($teacherPermissions);
        $studentRole->syncPermissions($studentPermissions);
    }
}
