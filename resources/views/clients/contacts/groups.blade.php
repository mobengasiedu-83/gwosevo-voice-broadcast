@extends('clients.layout')
@section('heading', 'Contact groups')
@section('description', 'Organize contacts for your campaigns. Inactive groups cannot receive new imports.')
@section('portal-content')
<form method="get" class="panel filters"><div class="form-field"><label for="q">Group name</label><input id="q" name="q" value="{{ request('q') }}"></div><div class="form-field"><label for="filter_active">Status</label><select id="filter_active" name="active"><option value="">All groups</option><option value="1" @selected(request('active')==='1')>Active</option><option value="0" @selected(request('active')==='0')>Inactive</option></select></div><button class="button">Search</button><a href="{{ route('client.groups') }}">Reset</a></form>
<section class="panel"><div class="table-scroll"><table><thead><tr><th>Group</th><th>Category</th><th>Contacts</th><th>Status</th></tr></thead><tbody>@forelse($groups as $g)<tr><td><a href="{{ route('client.groups.show', $g) }}">{{ $g->name }}</a><small>{{ $g->description }}</small></td><td>{{ $g->category }}</td><td>{{ number_format($g->contacts_count) }}</td><td>{{ $g->active ? 'Active' : 'Inactive' }}</td></tr>@empty<tr><td colspan="4">No groups yet. Create a group below to get started.</td></tr>@endforelse</tbody></table></div>{{ $groups->links('clients.pagination') }}</section>
<section class="panel" style="margin-top:24px"><h2>Create a group</h2><form method="post" action="{{ route('client.groups.store') }}">@csrf @include('clients.contacts.group-fields')<button class="button">Create group</button></form></section>
@endsection
