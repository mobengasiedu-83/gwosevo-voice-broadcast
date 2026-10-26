<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $matrix = [
            'Client Member' => ['contacts.view', 'contacts.manage', 'campaigns.view', 'campaigns.manage', 'audio.manage', 'reports.view'],
            'Super Administrator' => ['billing.manage', 'clients.manage', 'contacts.view', 'contacts.manage', 'campaigns.view', 'campaigns.manage', 'campaigns.approve', 'audit.view', 'telephony.view', 'reports.view', 'operations.view', 'users.manage', 'audio.manage', 'audio.approve', 'telephony.manage', 'broadcast.operate', 'broadcast.emergency'],
            'Administrator' => ['billing.manage', 'clients.manage', 'contacts.view', 'contacts.manage', 'campaigns.view', 'campaigns.manage', 'campaigns.approve', 'audit.view', 'telephony.view', 'reports.view', 'operations.view', 'audio.manage', 'audio.approve'],
            'Telephony Administrator' => ['telephony.view', 'telephony.manage'],
            'Campaign Manager' => ['contacts.view', 'contacts.manage', 'campaigns.view', 'campaigns.manage', 'audio.manage'],
            'Approver' => ['campaigns.view', 'campaigns.approve', 'audio.approve'],
            'Operator' => ['campaigns.view', 'operations.view', 'broadcast.operate'],
            'Analyst' => ['campaigns.view', 'reports.view'],
            'Auditor' => ['audit.view', 'campaigns.view', 'contacts.view'],
        ];
        foreach ($matrix as $role => &$permissions) {
            $permissions[] = 'dashboard.view';
            $permissions[] = 'dashboard.live';
            if (! in_array($role, ['Campaign Manager', 'Client Member'], true)) {
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
        User::whereHas('clientMembership')->each(fn ($user) => $user->syncRoles(['Client Member']));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
