<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class BillingPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['billing.view', 'billing.manage', 'dashboard.view'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Role::findOrCreate('Super Administrator', 'web')->givePermissionTo(['billing.view', 'billing.manage']);
        Role::findOrCreate('Billing Administrator', 'web')->givePermissionTo(['billing.view', 'billing.manage', 'dashboard.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
