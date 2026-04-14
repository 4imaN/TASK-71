<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ── Permissions ───────────────────────────────────────────────────────
        $permissions = [
            // Catalog
            'catalog.view',
            'catalog.filter',
            // Reservation
            'reservation.create',
            'reservation.view.own',
            'reservation.cancel.own',
            'reservation.reschedule.own',
            'reservation.check-in',
            'reservation.view.all',
            'reservation.confirm',
            'reservation.cancel.any',
            // Services (content editor)
            'service.create',
            'service.edit',
            'service.delete',
            'service.schedule.manage',
            // Admin
            'admin.users.manage',
            'admin.roles.manage',
            'admin.policies.manage',
            'admin.dictionaries.manage',
            'admin.form-rules.manage',
            'admin.audit-logs.view',
            'admin.import.manage',
            'admin.export',
            'admin.backup.manage',
            'admin.session.revoke',
        ];

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        // ── Roles ─────────────────────────────────────────────────────────────
        $learner = Role::firstOrCreate(['name' => 'learner', 'guard_name' => 'web']);
        $learner->syncPermissions([
            'catalog.view', 'catalog.filter',
            'reservation.create', 'reservation.view.own',
            'reservation.cancel.own', 'reservation.reschedule.own',
            'reservation.check-in',
        ]);

        $editor = Role::firstOrCreate(['name' => 'content_editor', 'guard_name' => 'web']);
        $editor->syncPermissions([
            'catalog.view', 'catalog.filter',
            'reservation.create', 'reservation.view.own', 'reservation.cancel.own',
            'reservation.reschedule.own', 'reservation.check-in',
            'service.create', 'service.edit', 'service.delete', 'service.schedule.manage',
        ]);

        $admin = Role::firstOrCreate(['name' => 'administrator', 'guard_name' => 'web']);
        $admin->syncPermissions(Permission::all());
    }
}
