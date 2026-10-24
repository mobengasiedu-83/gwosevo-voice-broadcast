<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ClientManagementPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['clients.manage', 'dashboard.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        foreach (['Super Administrator', 'Administrator'] as $name) {
            Role::findOrCreate($name, 'web')->givePermissionTo(['clients.manage', 'dashboard.view']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
