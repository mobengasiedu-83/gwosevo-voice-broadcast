<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Foundation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class StaffUserController extends Controller
{
    private function authorizeStaff(Request $request): void
    {
        Gate::authorize('users.manage');
        abort_if($request->user()->clientMembership()->exists(), 403);
    }

    public function index(Request $request)
    {
        $this->authorizeStaff($request);
        $filters = $request->validate(['q' => 'nullable|string|max:80', 'page' => 'nullable|integer|min:1']);
        $users = User::whereDoesntHave('clientMembership')->with('roles')
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%')))
            ->orderBy('name')->paginate(20)->withQueryString();
        $roles = Role::where('guard_name', 'web')->where('name', '!=', 'Client Member')->with('permissions')->get()->keyBy('name');

        return response()->view('staff.index', compact('users', 'roles', 'filters'))
            ->header('Cache-Control', 'private, no-store');
    }

    private function role(Request $request): string
    {
        $data = $request->validate(['role' => ['required', Rule::notIn(['Client Member']), Rule::exists('roles', 'name')->where('guard_name', 'web')]]);
        abort_if($data['role'] === 'Super Administrator' && ! $request->user()->isSuperAdministrator(), 403);

        return $data['role'];
    }

    private function lockedRole(string $name): Role
    {
        // Role mutation paths acquire this lock before acquiring user locks.
        // Recheck after validation: deletion or renaming may have won the race.
        $role = Role::where('guard_name', 'web')->where('name', $name)->lockForUpdate()->first();
        if (! $role || $role->name === 'Client Member') {
            throw ValidationException::withMessages(['role' => 'This role is no longer available. Refresh and select another role.']);
        }

        return $role;
    }

    public function store(Request $request, Foundation $foundation)
    {
        $this->authorizeStaff($request);
        $role = $this->role($request);
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => 'required|string|max:255', 'email' => 'required|email|max:254|unique:users,email',
            'password' => ['required', 'confirmed', 'max:128', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        DB::transaction(function () use ($request, $foundation, $role, $data) {
            $lockedRole = $this->lockedRole($role);
            $user = User::create($data);
            $user->assignRole($lockedRole);
            $foundation->audit($request->user(), 'staff.created', $user, ['role' => $role]);
        });

        return redirect()->route('staff.index')->with('status', 'Internal staff user created. Share the sign-in details securely.');
    }

    public function updateRole(Request $request, User $user, Foundation $foundation)
    {
        $this->authorizeStaff($request);
        abort_if($user->clientMembership()->exists(), 404);
        $role = $this->role($request);
        DB::transaction(function () use ($request, $user, $role, $foundation) {
            $lockedRole = $this->lockedRole($role);
            // Serialize staff role changes so the final super administrator cannot be removed.
            $staff = User::whereDoesntHave('clientMembership')->orderBy('id')->lockForUpdate()->get();
            $target = $staff->firstWhere('id', $user->id);
            abort_unless($target, 404);
            $previous = $target->getRoleNames()->all();
            abort_if($target->isSuperAdministrator() && ! $request->user()->isSuperAdministrator(), 403);
            if ($target->isSuperAdministrator() && $role !== 'Super Administrator'
                && $staff->filter(fn ($member) => $member->isSuperAdministrator())->count() <= 1) {
                throw ValidationException::withMessages(['role' => 'Keep at least one Super Administrator. Create another before changing this role.']);
            }
            $target->syncRoles([$lockedRole]);
            // Revoke stale sessions after an access change. The current user will sign in again too.
            $target->forceFill(['auth_version' => $target->auth_version + 1, 'remember_token' => Str::random(60)])->save();
            $foundation->audit($request->user(), 'staff.role_changed', $target, ['previous_roles' => $previous, 'role' => $role]);
        });

        return redirect()->route('staff.index')->with('status', 'Staff role updated. The user must sign in again.');
    }
}
