@extends('foundation.layout')
@section('title','Client accounts')
@section('content')
<h1>Client accounts</h1><p>Manage client organizations and access status. Suspending an account revokes its sessions and pauses new campaign dialing. Existing calls may finish.</p>
<section><h2>Create client account</h2><form method="post" action="{{ route('clients.accounts.store') }}">@csrf<label>Account name<input name="name" required maxlength="255" value="{{ old('name') }}"></label><button>Create account</button></form></section>
<section><h2>Accounts</h2><div class="table-scroll"><table><thead><tr><th>ID</th><th>Account</th><th>Status</th><th>Users</th><th>Access control</th></tr></thead><tbody>
@forelse($accounts as $account)<tr><td>{{ $account->id }}</td><td>{{ $account->name }}<small class="block">{{ $account->uuid }}</small></td><td>{{ ucfirst($account->status) }}@if($account->suspension_reason)<small class="block">{{ $account->suspension_reason }}</small>@endif</td><td>{{ $account->users_count }}</td><td><form method="post" action="{{ route('clients.accounts.status',$account) }}">@csrf<input type="hidden" name="status" value="{{ $account->status==='active'?'suspended':'active' }}"><label>Reason<input name="reason" required maxlength="2000"></label><button>{{ $account->status==='active'?'Suspend account':'Reactivate account' }}</button></form></td></tr>
@empty<tr><td colspan="5">No client accounts yet.</td></tr>@endforelse
</tbody></table></div>{{ $accounts->links() }}</section>
@endsection
