@extends('foundation.layout')
@section('title','Internal staff')
@section('content')
<div class="contact-heading"><div><h1>Internal staff</h1><p>Create staff accounts and assign a configured role. Client users are managed under Clients.</p></div></div>
<section><h2>Create internal staff user</h2>
<form method="post" action="{{ route('staff.store') }}" class="stack-form">@csrf
<div class="field-grid">
<label>Full name<input name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="name"></label>
<label>Email address<input name="email" type="email" value="{{ old('email') }}" required maxlength="254" autocomplete="off"></label>
<label>Staff role<select name="role" required><option value="">Choose a role</option>@foreach($roles as $name=>$configuredRole)@if($roles->has($name) && ($name !== 'Super Administrator' || auth()->user()->isSuperAdministrator()))<option value="{{ $name }}" @selected(old('role')===$name)>{{ $name }}</option>@endif@endforeach</select></label>
<label>Initial password<input name="password" type="password" required minlength="12" maxlength="128" autocomplete="new-password"></label>
<label>Confirm password<input name="password_confirmation" type="password" required minlength="12" maxlength="128" autocomplete="new-password"></label>
</div><p>Use at least 12 characters with upper/lowercase letters, a number and a symbol. Share credentials securely. Staff can change their password under Account → Security.</p>
<div class="form-actions"><button>Create staff user</button></div>
</form></section>
<section><h2>Staff directory</h2>
<form method="get" class="campaign-filters"><label>Search staff<input name="q" value="{{ $filters['q'] ?? '' }}" maxlength="80" placeholder="Name or email"></label><button>Search</button><a class="button secondary" href="{{ route('staff.index') }}">Reset</a></form>
<div class="table-scroll"><table><thead><tr><th>Name</th><th>Email</th><th>Current role</th><th>Change role</th></tr></thead><tbody>
@forelse($users as $staff)
<tr><td>{{ $staff->name }}@if($staff->id===auth()->id()) <small>(you)</small>@endif</td><td>{{ $staff->email }}</td><td>{{ $staff->getRoleNames()->join(', ') ?: 'Unassigned' }}</td><td>
@if(!$staff->isSuperAdministrator() || auth()->user()->isSuperAdministrator())
<form method="post" action="{{ route('staff.role',$staff) }}">@csrf<label><span class="sr-only">Role for {{ $staff->name }}</span><select name="role" required>@foreach($roles as $name=>$configuredRole)@if($roles->has($name) && ($name !== 'Super Administrator' || auth()->user()->isSuperAdministrator()))<option value="{{ $name }}" @selected($staff->hasRole($name))>{{ $name }}</option>@endif@endforeach</select></label><button>Save role</button></form>
@else<span>Super Administrator access required</span>@endif
</td></tr>
@empty<tr><td colspan="4">No internal staff match your search.</td></tr>@endforelse
</tbody></table></div>{{ $users->links() }}
<p>Role changes revoke existing sessions. The final Super Administrator cannot be demoted.</p></section>
<section><h2>Configured roles &amp; permissions</h2><p>Each account receives one staff role here. Only Super Administrators have staff management by default. Permissions below reflect the currently configured roles.</p>
<div class="client-dashboard-panels staff-role-cards">
@foreach($roles as $name=>$configuredRole)
<article><h3>{{ $name }}</h3><p>{{ \App\Services\StaffRoles::DESCRIPTIONS[$name] ?? 'Custom staff role. Review its configured permissions below.' }}</p><details><summary>View permissions</summary>@if($roles->has($name))<ul>@foreach($roles[$name]->permissions->sortBy('name') as $permission)<li>{{ $permission->name }}</li>@endforeach</ul>@else<p>This role is not configured. Run the roles seeder during deployment.</p>@endif</details></article>
@endforeach
</div></section>
@endsection
