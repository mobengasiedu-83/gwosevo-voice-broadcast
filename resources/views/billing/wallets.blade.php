@extends('foundation.layout')
@section('title','Calling wallets')
@section('content')
<h1>Calling wallets</h1>
<p>Prepaid balances, holds and the append-only ledger. Opening a wallet enables credit enforcement for that client; campaigns without a wallet keep the existing manual process. Charges are posted only after a final rating.</p>
<form method="get"><label>Client search<input name="q" maxlength="80" value="{{ $filters['q']??'' }}"></label><button>Search</button></form>
<section><div class="table-scroll"><table><thead><tr><th>Client</th><th>Status</th><th>Currency</th><th>Available</th><th>Reserved</th><th>Wallet</th></tr></thead><tbody>
@forelse($clients as $client)
<tr>
<td>{{ $client->name }}</td>
<td>{{ $client->status }}</td>
@if($client->wallet)
<td>{{ $client->wallet->currency }}</td>
<td>{{ \App\Services\Billing\Wallet::amount($client->wallet->available_minor, $client->wallet->minor_unit_scale) }}</td>
<td>{{ \App\Services\Billing\Wallet::amount($client->wallet->reserved_minor, $client->wallet->minor_unit_scale) }}</td>
@else
<td>—</td><td>Not configured</td><td>—</td>
@endif
<td><a href="{{ route('billing.wallets.show',$client) }}">{{ $client->wallet ? 'Manage wallet' : 'Open wallet' }}</a></td>
</tr>
@empty
<tr><td colspan="6">No client accounts match.</td></tr>
@endforelse
</tbody></table></div>{{ $clients->links() }}</section>
<p>Reserved credit is the maximum possible charge for calls currently in flight. Holds are released automatically when a call ends without an answer and settled once when a final rating exists.</p>
@endsection
