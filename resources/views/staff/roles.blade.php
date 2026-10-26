@extends('foundation.layout')
@section('title','Role management')
@section('content')
<div class="contact-heading"><div><h1>Role management</h1><p>Configure internal staff access. Client permissions are managed separately.</p></div><a class="button secondary" href="{{ route('staff.index') }}">Internal staff</a></div>
<section><h2>Create a staff role</h2>
<form method="post" action="{{ route('roles.store') }}" class="stack-form">@csrf
<label>Role name<input name="name" maxlength="80" required value="{{ old('name') }}" placeholder="e.g. Finance officer"></label>
<p>Select the permissions this role needs. No permissions means no workspace access. Staff management permission allows creating users and assigning staff roles; grant it only to trusted administrators.</p>
@include('staff.permission-fields', ['selected'=>old('permissions', [])])
<div class="form-actions"><button>Create role</button></div>
</form></section>
<section><h2>Existing staff roles</h2><p>Permission updates take effect immediately and revoke affected sessions. Built-in role names are fixed. Only unused custom roles can be deleted.</p>
@foreach($roles as $role)
<article><h3>{{ $role->name }} <small>· {{ $role->users_count }} assigned users</small></h3>
@if($role->name==='Super Administrator')<p>Protected role with full platform access. Its permissions cannot be restricted here.</p>
@else
<details @if((string) old('_role_id')===(string) $role->id) open @endif><summary>Edit permissions</summary>
<form method="post" action="{{ route('roles.update',$role) }}" class="stack-form">@csrf @method('put')
<input type="hidden" name="_role_id" value="{{ $role->id }}">
<label>Role name<input name="name" value="{{ (string) old('_role_id')===(string) $role->id ? old('name',$role->name) : $role->name }}" maxlength="80" required @readonly(array_key_exists($role->name,\App\Services\StaffRoles::DESCRIPTIONS))></label>
@include('staff.permission-fields', ['selected'=>(string) old('_role_id')===(string) $role->id ? old('permissions', []) : $role->permissions->pluck('name')->all()])
<div class="form-actions"><button>Save permissions</button></div>
</form></details>
@if(!array_key_exists($role->name,\App\Services\StaffRoles::DESCRIPTIONS))
@if(!$role->users_count)<form method="post" action="{{ route('roles.destroy',$role) }}">@csrf @method('delete')<button class="destructive">Delete unused role</button></form>@else<p>Reassign users to another role before deleting.</p>@endif
@endif
@endif
</article>
@endforeach</section>
@endsection
