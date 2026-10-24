<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        Role::findOrCreate('Client', 'web')->syncPermissions([]);
        Permission::findOrCreate('clients.manage', 'web');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $matrix = [
            'Super Administrator' => ['billing.manage', 'billing.view', 'clients.manage', 'contacts.view', 'contacts.manage', 'campaigns.view', 'campaigns.manage', 'campaigns.approve', 'audit.view', 'telephony.view', 'reports.view', 'operations.view', 'users.manage', 'audio.manage', 'audio.approve', 'telephony.manage', 'broadcast.operate', 'broadcast.emergency'],
            'Administrator' => ['clients.manage'],
            'Billing Administrator' => ['billing.manage', 'billing.view'],
            'Telephony Administrator' => ['telephony.view', 'telephony.manage'],
            'Campaign Manager' => ['contacts.view', 'contacts.manage', 'campaigns.view', 'campaigns.manage', 'audio.manage'],
            'Approver' => ['campaigns.view', 'campaigns.approve', 'audio.approve'],
            'Operator' => ['campaigns.view', 'operations.view', 'broadcast.operate'],
            'Analyst' => ['campaigns.view', 'reports.view'],
            'Auditor' => ['audit.view', 'campaigns.view', 'contacts.view'],
        ];
        foreach ($matrix as $role => &$permissions) {
            $permissions[] = 'dashboard.view';
            if ($role === 'Billing Administrator') {
                continue;
            }
            $permissions[] = 'dashboard.live';
            if ($role !== 'Campaign Manager') {
                $permissions[] = 'dashboard.global';
            }
            if (in_array($role, ['Super Administrator', 'Telephony Administrator', 'Operator'], true)) {
                $permissions[] = 'dashboard.wallboard';
                $permissions[] = 'dashboard.health';
            }
        }
        unset($permissions);
        foreach (array_unique(array_merge(...array_values($matrix))) as $name) {
            Permission::findOrCreate($name, 'web');
        }
        foreach ($matrix as $name => $permissions) {
            Role::findOrCreate($name, 'web')->syncPermissions($permissions);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
