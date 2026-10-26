@extends('foundation.layout')
@section('title','Wallet · '.$client->name)
@section('content')
<h1>Wallet · {{ $client->name }}</h1>
<p><a href="{{ route('billing.wallets') }}">← All wallets</a> · Account status: {{ $client->status }} · <a href="{{ route('clients.show',$client) }}">Client record</a></p>
@if(session('status'))<p class="notice">{{ session('status') }}</p>@endif
@if($account)
@php($fmt = fn ($minor) => \App\Services\Billing\Wallet::amount($minor, $account->minor_unit_scale))
<div class="kpi-grid">
<div class="kpi"><div>Available credit</div><strong>{{ $fmt($account->available_minor) }} {{ $account->currency }}</strong></div>
<div class="kpi"><div>Reserved credit</div><strong>{{ $fmt($account->reserved_minor) }} {{ $account->currency }}</strong></div>
<div class="kpi"><div>Minor unit scale</div><strong>{{ $account->minor_unit_scale }}</strong></div>
</div>
@if(! $verification['available_match'] || ! $verification['reserved_match'] || ! $verification['held_match'])
<p class="notice">Ledger reconciliation mismatch: stored balances do not match the append-only ledger or held reservations. Stop manual adjustments and escalate to Finance before further launches.</p>
@else
<p>Ledger reconciliation: stored balances match the append-only ledger and all held reservations.</p>
@endif
<div class="contact-panels">
<section><h2>Post approved entry</h2>
<form method="post" action="{{ route('billing.wallets.post',$client) }}">@csrf
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
<section><div class="table-scroll"><table><thead><tr><th>#</th><th>Attempt</th><th>Held</th><th>State</th><th>Settled</th><th>Shortfall</th><th>Reserved (UTC)</th><th>Closed (UTC)</th></tr></thead><tbody>
@forelse($reservations as $reservation)
<tr>
<td>{{ $reservation->id }}</td>
<td><a href="{{ route('dashboard.call',$reservation->call_attempt_id) }}">Attempt {{ $reservation->call_attempt_id }}</a></td>
<td>{{ $fmt($reservation->amount_minor) }}</td>
<td>{{ $reservation->state }}</td>
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
