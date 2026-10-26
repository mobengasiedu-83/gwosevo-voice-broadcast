@extends('dashboard.layout')
@section('title','Calling credit')
@section('content')
<div class="contact-heading"><div><h1>Calling credit</h1><p>{{ $client->name }} · {{ ucfirst($membership->role) }} · Balance, holds and top-ups</p></div><a class="button secondary" href="{{ route('dashboard.overview') }}">← Dashboard</a></div>
@if(! $wallet)
<section><h2>Wallet not configured</h2><p>No calling wallet is configured for this account. Finance must open a wallet, assign an approved tariff and post funding before wallet-backed campaigns can launch.</p><p>Campaigns without a wallet keep the existing manual billing process.</p></section>
@else
@php($fmt = fn ($minor) => \App\Services\Billing\Wallet::amount((int) $minor, $wallet->minor_unit_scale))
<div class="kpi-grid">
<div class="kpi"><div>Available credit</div><strong>{{ $fmt($wallet->available_minor) }} {{ $wallet->currency }}</strong></div>
<div class="kpi"><div>Reserved credit</div><strong>{{ $fmt($wallet->reserved_minor) }} {{ $wallet->currency }}</strong></div>
<div class="kpi"><div>Calls in flight</div><strong>{{ number_format($summary['held_count']) }}</strong></div>
<div class="kpi"><div>Paid from wallet</div><strong>{{ $fmt($summary['settled_minor'] - $summary['shortfall_minor']) }} {{ $wallet->currency }}</strong></div>
</div>
@if($affordability && $affordability['low'])
<p class="notice">{{ \App\Services\Billing\Wallet::clientMessage($affordability['reason']) }}@if($affordability['reason'] === 'insufficient_credit') The maximum cost of one call is {{ $fmt($affordability['exposure_minor']) }} {{ $wallet->currency }}.@endif</p>
@elseif($affordability)
<p>Available credit covers up to {{ $affordability['covered_calls'] === null ? 'unlimited' : number_format($affordability['covered_calls']) }} full reserved call{{ $affordability['covered_calls'] === 1 ? '' : 's' }} at the maximum call duration. Actual charges are usually lower.</p>
@endif
@if($summary['shortfall_count'])
<p class="notice">{{ number_format($summary['shortfall_count']) }} rated charge(s) exceeded the credit available at settlement and are under Finance review. No negative balance has been applied.</p>
@endif
<section><h2>How charging works</h2><p>Each call holds the maximum possible charge before it starts. An unanswered, busy or failed call releases its hold and is not charged. An answered call is settled once at its final rated amount. Online top-ups (when enabled) and Finance-approved corrections appear below as new entries; history is never edited.</p></section>
<section><h2>Add calling credit</h2>
@if($onlineEnabled)
<form method="post" action="{{ route('wallet.payments.store') }}">@csrf
<input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
<label>Amount ({{ $wallet->currency }})<input name="amount" value="{{ old('amount') }}" required maxlength="32" inputmode="decimal" placeholder="50.00" autocomplete="off" aria-describedby="topup-limits"></label>
<button>Continue to secure payment</button>
</form>
<p id="topup-limits">Top-ups from {{ $fmt($limits['min_minor']) }} to {{ $fmt($limits['max_minor']) }} {{ $wallet->currency }}. You will be redirected to the payment provider; credit is applied only after a verified confirmation. <a href="{{ route('wallet.payments.index') }}">View top-ups and receipts →</a></p>
@else
<p>Online payment is not enabled in this environment. Contact Finance to post an approved top-up and quote your account name. <a href="{{ route('wallet.payments.index') }}">View payment history →</a></p>
@endif
</section>

<h2>Transactions</h2>
<p>Append-only ledger in {{ $wallet->currency }} with {{ $wallet->minor_unit_scale }} decimal place(s). Times are UTC.</p>
<form method="get" class="campaign-filters">
<label>Entry type<select name="type"><option value="">All entries</option>@foreach(\App\Services\Billing\Wallet::TYPES as $type)<option value="{{ $type }}" @selected(($filters['type']??'')===$type)>{{ ucfirst(str_replace('_',' ',$type)) }}</option>@endforeach</select></label>
@if($filters['state']??null)<input type="hidden" name="state" value="{{ $filters['state'] }}">@endif
<button>Filter transactions</button>
</form>
<section><div class="table-scroll"><table><thead><tr><th>Date (UTC)</th><th>Activity</th><th>Available Δ</th><th>Reserved Δ</th><th>Balance after</th><th>Reference</th></tr></thead><tbody>
@forelse($entries as $entry)
<tr>
<td>{{ $entry->created_at?->format('d M Y H:i') }}</td>
<td>{{ ucfirst(str_replace('_',' ',$entry->type)) }}</td>
<td>{{ $entry->available_delta_minor > 0 ? '+' : '' }}{{ $fmt($entry->available_delta_minor) }}</td>
<td>{{ $entry->reserved_delta_minor > 0 ? '+' : '' }}{{ $fmt($entry->reserved_delta_minor) }}</td>
<td>{{ $fmt($entry->available_after_minor) }} / {{ $fmt($entry->reserved_after_minor) }}</td>
<td>{{ $entry->reason }}@if($entry->wallet_reservation_id)<br>Hold #{{ $entry->wallet_reservation_id }}@endif@if($entry->reversal_of_id)<br>Corrects entry #{{ $entry->reversal_of_id }}@endif</td>
</tr>
@empty
<tr><td colspan="6">No transactions yet.</td></tr>
@endforelse
</tbody></table></div>{{ $entries->links() }}</section>

<h2>Call holds</h2>
<p>Hold values are the maximum possible charge. Charged values are the final rated amount after a call ends.</p>
<form method="get" class="campaign-filters">
<label>Hold state<select name="state"><option value="">All states</option>@foreach(['held'=>'In flight','settled'=>'Settled','released'=>'Released (not charged)'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['state']??'')===$value)>{{ $label }}</option>@endforeach</select></label>
@if($filters['type']??null)<input type="hidden" name="type" value="{{ $filters['type'] }}">@endif
<button>Filter holds</button>
</form>
<section><div class="table-scroll"><table><thead><tr><th>Call</th><th>Held</th><th>State</th><th>Charged</th><th>Reserved (UTC)</th><th>Closed (UTC)</th></tr></thead><tbody>
@forelse($reservations as $reservation)
<tr>
<td>Attempt {{ $reservation->call_attempt_id }}</td>
<td>{{ $fmt($reservation->amount_minor) }} {{ $reservation->currency }}</td>
<td>{{ $reservation->state }}@if($reservation->state === 'settled' && $reservation->shortfall_minor > 0)<br><small>Under Finance review</small>@endif</td>
<td>{{ $reservation->settled_minor === null ? '—' : $fmt($reservation->settled_minor).' '.$reservation->currency }}</td>
<td>{{ $reservation->reserved_at?->format('d M Y H:i') }}</td>
<td>{{ ($reservation->settled_at ?? $reservation->released_at)?->format('d M Y H:i') ?? '—' }}</td>
</tr>
@empty
<tr><td colspan="6">No call holds yet.</td></tr>
@endforelse
</tbody></table></div>{{ $reservations->links() }}</section>
<p>Need a top-up, refund or an explanation of a charge? Use the online top-up form above when enabled, contact Finance with the attempt reference above, or <a href="{{ route('wallet.disputes.index') }}">submit a billing dispute</a>. You can also <a href="{{ route('wallet.statement') }}">download your full statement (CSV)</a>.</p>
@endif
@endsection