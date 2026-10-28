@extends('foundation.layout')
@section('title','Calling wallets')
@section('content')
<h1>Calling wallets</h1>
<p>Prepaid balances, holds and the append-only ledger. Opening a wallet enables credit enforcement for that client; campaigns without a wallet keep the existing manual process. Charges are posted only after a final rating.</p>
<div class="kpi-grid">
    <div class="kpi">
        <div>Wallets configured</div><strong>{{ number_format($summary['configured']) }}</strong>
    </div>
    <div class="kpi">
        <div>Clients without a wallet</div><strong>{{ number_format($summary['no_wallet']) }}</strong>
    </div>
    <div class="kpi">
        <div>Calls holding credit</div><strong>{{ number_format($summary['held_count']) }}</strong>
    </div>
    <div class="kpi">
        <div>Holds older than 24h</div><strong>{{ number_format($summary['stale_hold_count']) }}</strong>
    </div>
</div>
@if($summary['held_by_currency']->isNotEmpty())
<p>Held credit: @foreach($summary['held_by_currency'] as $held){{ \App\Services\Billing\Wallet::amount((int) $held->held_minor, (int) $held->minor_unit_scale) }} {{ $held->currency }}@if(! $loop->last) · @endif @endforeach. Holds older than 24 hours are a settlement backlog; review the attempt before using <code>billing:wallet-expire</code>.</p>
@endif
<form method="get" class="campaign-filters">
    <label>Client search<input name="q" maxlength="80" value="{{ $filters['q']??'' }}"></label>
    <label>Wallet state<select name="state">@foreach(['all'=>'All accounts','funded'=>'Wallet configured','unfunded'=>'No wallet','held'=>'Holding credit'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['state']??'all')===$value)>{{ $label }}</option>@endforeach</select></label>
    <button>Filter</button>
</form>
<section>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Status</th>
                    <th>Currency</th>
                    <th>Available</th>
                    <th>Reserved</th>
                    <th>Calls holding credit</th>
                    <th>Wallet</th>
                </tr>
            </thead>
            <tbody>
                @forelse($clients as $client)
                <tr>
                    <td>{{ $client->name }}</td>
                    <td>{{ $client->status }}</td>
                    @if($client->wallet)
                    <td>{{ $client->wallet->currency }}</td>
                    <td>{{ \App\Services\Billing\Wallet::amount($client->wallet->available_minor, $client->wallet->minor_unit_scale) }}</td>
                    <td>{{ \App\Services\Billing\Wallet::amount($client->wallet->reserved_minor, $client->wallet->minor_unit_scale) }}</td>
                    @else
                    <td>—</td>
                    <td>Not configured</td>
                    <td>—</td>
                    @endif
                    <td>{{ number_format((int) $client->held_reservations_count) }}</td>
                    <td><a href="{{ route('billing.wallets.show',$client) }}">{{ $client->wallet ? 'Manage wallet' : 'Open wallet' }}</a></td>
                </tr>
                @empty
                <tr>
                    <td colspan="7">No client accounts match.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>{{ $clients->links() }}
</section>
<p>Reserved credit is the sum of holds for calls currently in flight and for campaign recipients still awaiting their call. Holds are released automatically when a call ends without an answer and settled once when a final rating exists.</p>
@endsection