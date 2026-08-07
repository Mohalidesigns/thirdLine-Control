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
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            // Control library
            'view controls', 'create controls', 'edit controls', 'approve controls', 'retire controls',
            // Risk register
            'view risks', 'create risks', 'edit risks', 'map risks',
            // Testing
            'view tests', 'execute tests', 'review tests', 'build test-scripts', 'approve test-scripts',
            'rate controls', 'approve ratings',
            // Exceptions
            'view exceptions', 'create exceptions', 'remediate exceptions', 'close exceptions',
            'approve extensions', 'accept risk',
            // Compensating controls
            'view compensating-controls', 'create compensating-controls', 'approve compensating-controls',
            // Spot checks
            'view spot-checks', 'conduct spot-checks',
            // Reporting
            'view dashboards', 'export reports',
            // Administration
            'manage settings', 'manage users', 'manage escalations',
            // Platform foundations (Phase 7)
            'manage sso', 'view audit log', 'export audit log',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $all = Permission::pluck('name')->toArray();

        // BRD §4 roles. Two second-line tiers, first-line owner/manager,
        // read-only executive, and the system administrator.
        Role::findOrCreate('System Administrator', 'web')->syncPermissions($all);

        Role::findOrCreate('Control Function Head', 'web')->syncPermissions(array_diff($all, [
            'manage users', 'manage settings', 'manage sso',
        ]));

        Role::findOrCreate('Control Officer', 'web')->syncPermissions([
            'view controls', 'create controls', 'edit controls',
            'view risks', 'create risks', 'edit risks', 'map risks',
            'view tests', 'execute tests', 'build test-scripts', 'rate controls',
            'view exceptions', 'create exceptions', 'remediate exceptions',
            'view compensating-controls', 'create compensating-controls',
            'view spot-checks', 'conduct spot-checks',
            'view dashboards', 'export reports',
        ]);

        Role::findOrCreate('Control Owner', 'web')->syncPermissions([
            'view controls', 'view exceptions', 'remediate exceptions',
            'view compensating-controls', 'create compensating-controls',
            'view dashboards',
        ]);

        Role::findOrCreate('Line Manager', 'web')->syncPermissions([
            'view controls', 'view risks', 'view tests', 'view exceptions',
            'view compensating-controls', 'view spot-checks', 'view dashboards',
        ]);

        Role::findOrCreate('Executive Viewer', 'web')->syncPermissions([
            'view controls', 'view risks', 'view tests', 'view exceptions',
            'view compensating-controls', 'view spot-checks', 'view dashboards', 'export reports',
        ]);
    }
}
