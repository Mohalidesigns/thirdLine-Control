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
            // Frameworks & regulatory obligations (Phase 8)
            'view frameworks', 'manage frameworks', 'map controls', 'approve control-mappings',
            'view obligations', 'manage obligations', 'assign obligations', 'submit obligations',
            'approve obligation-submissions', 'waive obligations',
            'view regulatory-changes', 'assess regulatory-changes', 'action regulatory-changes',
            'install content-packs',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $all = Permission::pluck('name')->toArray();

        // BRD §4 roles. Two second-line tiers, first-line owner/manager,
        // read-only executive, and the system administrator.
        Role::findOrCreate('System Administrator', 'web')->syncPermissions($all);

        // Installing regulatory content packs changes platform-wide data, so
        // it stays with the System Administrator alongside the other
        // platform-level permissions.
        Role::findOrCreate('Control Function Head', 'web')->syncPermissions(array_diff($all, [
            'manage users', 'manage settings', 'manage sso', 'install content-packs',
        ]));

        Role::findOrCreate('Control Officer', 'web')->syncPermissions([
            'view controls', 'create controls', 'edit controls',
            'view risks', 'create risks', 'edit risks', 'map risks',
            'view tests', 'execute tests', 'build test-scripts', 'rate controls',
            'view exceptions', 'create exceptions', 'remediate exceptions',
            'view compensating-controls', 'create compensating-controls',
            'view spot-checks', 'conduct spot-checks',
            'view dashboards', 'export reports',
            'view frameworks', 'map controls',
            'view obligations', 'submit obligations',
            'view regulatory-changes', 'assess regulatory-changes',
        ]);

        Role::findOrCreate('Control Owner', 'web')->syncPermissions([
            'view controls', 'view exceptions', 'remediate exceptions',
            'view compensating-controls', 'create compensating-controls',
            'view dashboards',
            'view frameworks', 'view obligations', 'submit obligations',
        ]);

        Role::findOrCreate('Line Manager', 'web')->syncPermissions([
            'view controls', 'view risks', 'view tests', 'view exceptions',
            'view compensating-controls', 'view spot-checks', 'view dashboards',
            'view frameworks', 'view obligations', 'view regulatory-changes',
        ]);

        Role::findOrCreate('Executive Viewer', 'web')->syncPermissions([
            'view controls', 'view risks', 'view tests', 'view exceptions',
            'view compensating-controls', 'view spot-checks', 'view dashboards', 'export reports',
            'view frameworks', 'view obligations', 'view regulatory-changes',
        ]);
    }
}
