<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class ClientLifecyclePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $names = ['clients.view', 'clients.profile', 'clients.users', 'clients.verify', 'clients.restrict', 'clients.export', 'clients.activity', 'clients.settings'];
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
