@extends('foundation.layout')
@section('title','Wallet · '.$client->name)
@section('content')
<h1>Wallet · {{ $client->name }}</h1>
<p><a href="{{ route('billing.wallets') }}">← All wallets</a> · Account status: {{ $client->status }} · <a href="{{ route('clients.show',$client) }}">Client record</a> · <a href="{{ route('billing.payments',['q'=>$client->name]) }}">Online payments</a> · <a href="{{ route('billing.wallets.statement',$client) }}">Download statement CSV</a> · <a href="{{ route('billing.disputes',['q'=>$client->name]) }}">Disputes</a></p>
@if(session('status'))<p class="notice">{{ session('status') }}</p>@endif
@if($account)
@php($fmt = fn ($minor) => \App\Services\Billing\Wallet::amount($minor, $account->minor_unit_scale))
<div class="kpi-grid">
<div class="kpi"><div>Available credit</div><strong>{{ $fmt($account->available_minor) }} {{ $account->currency }}</strong></div>
<div class="kpi"><div>Reserved credit</div><strong>{{ $fmt($account->reserved_minor) }} {{ $account->currency }}</strong></div>
<div class="kpi"><div>Calls holding credit</div><strong>{{ number_format($summary['held_count']) }}</strong></div>
<div class="kpi"><div>Uncovered shortfall</div><strong>{{ $fmt($summary['shortfall_minor']) }} {{ $account->currency }}</strong></div>
</div>
<section><h2>Tariff and one-call coverage</h2>
@if($tariff)
<p>Assigned tariff <strong>{{ $tariff['code'] }}</strong> version {{ $tariff['version'] }} · {{ $fmt($tariff['rate_minor_per_minute']) }} {{ $tariff['currency'] }} per billed minute. A hold covers {{ config('billing.max_call_seconds') }} seconds rounded up to whole minutes.</p>
@else
<p>No approved call tariff is assigned. Wallet admission stays blocked until Finance <a href="{{ route('billing.rates') }}">assigns a reviewed tariff on the rate-plan screen</a>.</p>
@endif
@if($affordability)
<div class="kpi-grid">
<div class="kpi"><div>One-call exposure</div><strong>{{ $fmt($affordability['exposure_minor']) }} {{ $account->currency }}</strong></div>
<div class="kpi"><div>Full holds covered</div><strong>{{ $affordability['covered_calls'] === null ? 'No charge' : number_format($affordability['covered_calls']) }}</strong></div>
<div class="kpi"><div>Oldest open hold</div><strong>{{ $summary['oldest_hold_at']?->diffForHumans() ?? 'None' }}</strong></div>
<div class="kpi"><div>Holds older than 24h</div><strong>{{ number_format($summary['stale_hold_count']) }}</strong></div>
</div>
@if($affordability['low'])
<p class="notice">{{ \App\Services\Billing\Wallet::message($affordability['reason']) }}{{ $summary['stale_hold_count'] ? ' '.$summary['stale_hold_count'].' hold(s) are older than 24 hours.' : '' }}</p>
@endif
@endif
</section>
@if(! $verification['ok'])
<p class="notice">Ledger reconciliation failed: {{ implode(' ', $verification['issues']) }} Stop manual adjustments and escalate to Finance before further launches.</p>
@else
<p>Ledger reconciliation: stored balances, the entry chain, open holds and the settlement lifecycle match the append-only ledger.</p>
@endif
<div class="contact-panels">
<section><h2>Manual credit</h2>
<p>Administrator and Super Administrator accounts with <code>billing.manage</code> can add calling credit immediately. The authenticated administrator is the authorization; an approval reference is optional but recommended.</p>
<form method="post" action="{{ route('billing.wallets.credit',$client) }}">@csrf
<input type="hidden" name="credit_key" value="{{ \Illuminate\Support\Str::uuid() }}">
<label>Amount ({{ $account->currency }})<input name="amount" required maxlength="32" inputmode="decimal" placeholder="50.00"></label>
<label>Reason<input name="reason" required minlength="3" maxlength="255" placeholder="Administrator manual credit"></label>
<label>Approval reference (optional)<input name="approval" maxlength="255" placeholder="Ticket, approval record or leave blank"></label>
<button>Credit account</button>
</form>
<p>This appends one funding entry and an immutable audit record. It cannot create a negative balance.</p>
</section>
<section><h2>Post approved entry</h2>
<form method="post" action="{{ route('billing.wallets.post',$client) }}">@csrf
<input type="hidden" name="key" value="{{ \Illuminate\Support\Str::uuid() }}">
<label>Type<select name="kind"><option value="funding">Funding (credit)</option><option value="adjustment">Adjustment (credit or debit)</option></select></label>
<label>Amount ({{ $account->currency }})<input name="amount" required maxlength="32" inputmode="decimal" placeholder="0.00"></label>
<label>Reason<input name="reason" required maxlength="255" placeholder="Reviewed commercial decision"></label>
<label>Approval reference<input name="approval" required maxlength="255" placeholder="Ticket or approval record"></label>
<button>Post entry</button>
</form>
<p>Debits cannot take available credit below zero. Corrections are new reversing entries; posted entries are never edited or deleted.</p>
</section>
<section><h2>Controls</h2>
<p>Reservations hold the maximum possible charge for one call using <code>BILLING_MAX_CALL_SECONDS</code> (currently {{ config('billing.max_call_seconds') }} seconds). Settlement uses the final rated amount exactly once.</p>
<p>Campaigns without a held reservation cannot originate. If credit runs out mid-campaign, the campaign is paused and remains resumable after a top-up.</p>
<p>Online top-ups are credited only from a verified provider callback for the exact intent amount and currency. Duplicate callbacks are stored once; refunds post a separate reversing entry and never edit the original funding row. <a href="{{ route('billing.payments',['q'=>$client->name]) }}">Review this client's payments →</a></p>
</section>
</div>
@endif
@unless($account)
<p>This client has no calling wallet. Without a wallet, credit is not enforced for this account.</p>
<div class="contact-panels"><section><h2>Open wallet</h2>
<form method="post" action="{{ route('billing.wallets.open',$client) }}">@csrf
<label>Currency<input name="currency" required maxlength="3" value="GHS"></label>
<label>Minor unit scale<input name="minor_unit_scale" type="number" min="0" max="6" required value="2"></label>
<label>Reason<input name="reason" required maxlength="255" placeholder="Approved commercial onboarding"></label>
<button>Open wallet</button>
</form>
<p>The currency and scale must match the client's assigned call tariff. Finance must post approved funding before the first paid launch.</p>
</section></div>
@endunless
@if($account)
<h2>Ledger</h2>
<p>Append-only. Amounts are shown in {{ $account->currency }} with {{ $account->minor_unit_scale }} decimal place(s).</p>
<form method="get" class="campaign-filters">
<label>Entry type<select name="type"><option value="">All entries</option>@foreach(\App\Services\Billing\Wallet::TYPES as $type)<option value="{{ $type }}" @selected(($filters['type']??'')===$type)>{{ ucfirst(str_replace('_',' ',$type)) }}</option>@endforeach</select></label>
@if($filters['state']??null)<input type="hidden" name="state" value="{{ $filters['state'] }}">@endif
<button>Filter ledger</button>
</form>
<section><div class="table-scroll"><table><thead><tr><th>#</th><th>Type</th><th>Available Δ</th><th>Reserved Δ</th><th>Balances after (available / reserved)</th><th>Reason</th><th>Approval</th><th>Posted (UTC)</th><th>Correction</th></tr></thead><tbody>
@forelse($entries as $entry)
<tr>
<td>{{ $entry->id }}</td>
<td>{{ str_replace('_',' ',$entry->type) }}</td>
<td>{{ $entry->available_delta_minor > 0 ? '+' : '' }}{{ $fmt($entry->available_delta_minor) }}</td>
<td>{{ $entry->reserved_delta_minor > 0 ? '+' : '' }}{{ $fmt($entry->reserved_delta_minor) }}</td>
<td>{{ $fmt($entry->available_after_minor) }} / {{ $fmt($entry->reserved_after_minor) }}</td>
<td>{{ $entry->reason }}@if($entry->wallet_reservation_id)<br>Reservation #{{ $entry->wallet_reservation_id }}@endif@if($entry->reversal_of_id)<br>Reverses entry #{{ $entry->reversal_of_id }}@endif</td>
<td>{{ $entry->approval_reference ?? '—' }}</td>
<td>{{ $entry->created_at?->format('Y-m-d H:i:s') }}</td>
<td>
@if(in_array($entry->type, ['funding','adjustment'], true) && ! in_array($entry->id, $reversed, true))
<details><summary>Reverse</summary><form method="post" action="{{ route('billing.wallets.reverse',[$client,$entry]) }}">@csrf
<label>Reason<input name="reason" required maxlength="255"></label>
<label>Approval reference<input name="approval" required maxlength="255"></label>
<button>Post reversal</button></form></details>
@else
—
@endif
</td>
</tr>
@empty
<tr><td colspan="9">No ledger entries yet. Post approved funding to enable paid campaigns.</td></tr>
@endforelse
</tbody></table></div>{{ $entries->links() }}</section>
<h2>Reservations</h2>
<form method="get" class="campaign-filters">
<label>Reservation state<select name="state"><option value="">All states</option>@foreach(['held'=>'Held (call in flight)','settled'=>'Settled','released'=>'Released','shortfall'=>'Settled with shortfall'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['state']??'')===$value)>{{ $label }}</option>@endforeach</select></label>
@if($filters['type']??null)<input type="hidden" name="type" value="{{ $filters['type'] }}">@endif
<button>Filter reservations</button>
</form>
<section><div class="table-scroll"><table><thead><tr><th>#</th><th>Attempt</th><th>Held</th><th>State</th><th>Settled</th><th>Shortfall</th><th>Reserved (UTC)</th><th>Closed (UTC)</th></tr></thead><tbody>
@forelse($reservations as $reservation)
<tr>
<td>{{ $reservation->id }}</td>
<td><a href="{{ route('dashboard.call',$reservation->call_attempt_id) }}">Attempt {{ $reservation->call_attempt_id }}</a></td>
<td>{{ $fmt($reservation->amount_minor) }}</td>
<td>{{ $reservation->state }}@if($reservation->state === 'held' && $reservation->reserved_at?->lte(now()->subDay()))<br><strong>older than 24h</strong>@endif@if($reservation->release_reason)<br><small>{{ $reservation->release_reason }}</small>@endif</td>
<td>{{ $reservation->settled_minor === null ? '—' : $fmt($reservation->settled_minor) }}</td>
<td>{{ $fmt($reservation->shortfall_minor) }}</td>
<td>{{ $reservation->reserved_at?->format('Y-m-d H:i:s') }}</td>
<td>{{ ($reservation->settled_at ?? $reservation->released_at)?->format('Y-m-d H:i:s') ?? '—' }}</td>
</tr>
@empty
<tr><td colspan="8">No reservations yet.</td></tr>
@endforelse
</tbody></table></div>{{ $reservations->links() }}</section>
<p>Settlements debit the rated charge once per attempt. Held reservations belong to calls still in flight; unanswered calls release their hold and post zero usage.</p>
@endif
@endsection
