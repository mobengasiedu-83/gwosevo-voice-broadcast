<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Foundation;
use App\Services\StaffRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleManagementController extends Controller
{
    private function authorizeManager(Request $request): void
    {
        abort_unless($request->user()->isSuperAdministrator(), 403);
    }

    public function index(Request $request)
    {
        $this->authorizeManager($request);
        $roles = Role::where('guard_name', 'web')->where('name', '!=', 'Client Member')->with('permissions')->withCount('users')->orderBy('name')->get();
        $permissionGroups = Permission::where('guard_name', 'web')->orderBy('name')->get()->groupBy(fn ($permission) => Str::before($permission->name, '.'));

        return response()->view('staff.roles', compact('roles', 'permissionGroups'))->header('Cache-Control', 'private, no-store');
    }

    private function data(Request $request, ?Role $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80', 'regex:/\A[A-Za-z][A-Za-z0-9 _-]*\z/', Rule::notIn(['Client Member', 'Super Administrator']), Rule::unique('roles', 'name')->where('guard_name', 'web')->ignore($role?->id)],
            'permissions' => 'sometimes|array',
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ]);
    }

    public function store(Request $request, Foundation $foundation)
    {
        $this->authorizeManager($request);
        $data = $this->data($request);
        DB::transaction(function () use ($request, $foundation, $data) {
            $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
            $role->syncPermissions($data['permissions'] ?? []);
            $foundation->audit($request->user(), 'staff.role_created', $role, ['name' => $role->name, 'permissions' => $data['permissions'] ?? []]);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('roles.index')->with('status', 'Staff role created. Assign it from Internal staff.');
    }

    public function update(Request $request, Role $role, Foundation $foundation)
    {
        $this->authorizeManager($request);
        abort_if($role->guard_name !== 'web' || in_array($role->name, ['Client Member', 'Super Administrator']), 403);
        $data = $this->data($request, $role);
        // Keep seeded role identities stable; their permission sets remain editable.
        if (array_key_exists($role->name, StaffRoles::DESCRIPTIONS) && $role->name !== $data['name']) {
            throw ValidationException::withMessages(['name' => 'Built-in role names cannot be changed.']);
        }
        DB::transaction(function () use ($request, $role, $foundation, $data) {
            $role = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            $before = ['name' => $role->name, 'permissions' => $role->permissions->pluck('name')->all()];
            $role->update(['name' => $data['name']]);
            $role->syncPermissions($data['permissions'] ?? []);
            User::role($role)->orderBy('id')->lockForUpdate()->get()->each(function ($user) {
                $user->forceFill(['auth_version' => $user->auth_version + 1, 'remember_token' => Str::random(60)])->save();
            });
            $foundation->audit($request->user(), 'staff.role_permissions_changed', $role, ['before' => $before, 'name' => $role->name, 'permissions' => $data['permissions'] ?? []]);
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('roles.index')->with('status', 'Role updated. Affected staff must sign in again.');
    }

    public function destroy(Request $request, Role $role, Foundation $foundation)
    {
        $this->authorizeManager($request);
        abort_if($role->guard_name !== 'web' || $role->name === 'Client Member' || array_key_exists($role->name, StaffRoles::DESCRIPTIONS), 403);
        DB::transaction(function () use ($request, $role, $foundation) {
            $role = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            if ($role->users()->exists()) {
                throw ValidationException::withMessages(['role' => 'Reassign all users before deleting this role.']);
            }
            $foundation->audit($request->user(), 'staff.role_deleted', $role, ['name' => $role->name]);
            $role->delete();
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('roles.index')->with('status', 'Unused custom role deleted.');
    }
}
