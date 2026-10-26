<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_lifecycle_permissions_staff_assignment_and_seed_persistence(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Super Administrator');
        $this->actingAs($admin)->get(route('roles.index'))->assertOk()->assertSee('Role management');
        $this->post(route('roles.store'), ['name' => 'Finance officer', 'permissions' => ['dashboard.view', 'billing.manage']])->assertRedirect(route('roles.index'));
        $role = Role::findByName('Finance officer');
        $this->assertTrue($role->hasPermissionTo('billing.manage'));
        $this->post(route('staff.store'), ['name' => 'Finance staff', 'email' => 'finance@example.com', 'role' => $role->name, 'password' => 'StrongPassword123!', 'password_confirmation' => 'StrongPassword123!'])->assertRedirect(route('staff.index'));
        $staff = User::where('email', 'finance@example.com')->firstOrFail();
        $this->put(route('roles.update', $role), ['name' => 'Finance reviewer', 'permissions' => ['dashboard.view']])->assertRedirect(route('roles.index'));
        $this->assertSame(1, $staff->fresh()->auth_version);
        $this->assertFalse($staff->fresh()->can('billing.manage'));
        $this->deleteJson(route('roles.destroy', $role))->assertUnprocessable();
        $this->post(route('staff.role', $staff), ['role' => 'Analyst'])->assertRedirect();
        $this->delete(route('roles.destroy', $role))->assertRedirect();
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
        $analyst = Role::findByName('Analyst');
        $this->put(route('roles.update', $analyst), ['name' => 'Analyst', 'permissions' => ['dashboard.view']])->assertRedirect();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertFalse($analyst->fresh()->hasPermissionTo('reports.view'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.role_permissions_changed', 'actor_id' => $admin->id]);
    }

    public function test_only_super_admin_can_manage_roles_and_protected_roles_cannot_change(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Administrator');
        $user->givePermissionTo('users.manage');
        $role = Role::findByName('Analyst');
        $this->actingAs($user)->get(route('roles.index'))->assertForbidden();
        $this->post(route('roles.store'), ['name' => 'Test'])->assertForbidden();
        $this->put(route('roles.update', $role), ['name' => 'Analyst'])->assertForbidden();
        $this->delete(route('roles.destroy', $role))->assertForbidden();
        $user->syncRoles(['Super Administrator']);
        foreach (['Super Administrator', 'Client Member'] as $name) {
            $protected = Role::findByName($name);
            $this->put(route('roles.update', $protected), ['name' => 'Changed'])->assertForbidden();
            $this->delete(route('roles.destroy', $protected))->assertForbidden();
        }
        $this->postJson(route('roles.store'), ['name' => 'Invalid', 'permissions' => ['made.up']])->assertUnprocessable();
        $this->postJson(route('roles.store'), ['name' => 'Client Member'])->assertUnprocessable();
        $this->putJson(route('roles.update', $role), ['name' => 'Renamed'])->assertUnprocessable();
    }
}
