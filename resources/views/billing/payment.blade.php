@extends('foundation.layout')
@section('title','Payment '.$intent->receiptNumber())
@section('content')
@if($intent)@include('partials.client-identity',['record'=>$intent])@endif
@php($fmt = fn ($minor) => \App\Services\Billing\Wallet::amount((int) $minor, $intent->minor_unit_scale))
<h1>Payment {{ $intent->receiptNumber() }}</h1>
<p><a href="{{ route('billing.payments') }}">← All payments</a> · <a href="{{ route('clients.show', $intent->client_id) }}">{{ $intent->client?->name }}</a> · <a href="{{ route('billing.wallets.show', $intent->client_id) }}">Wallet</a></p>
@if(session('status'))<p class="notice">{{ session('status') }}</p>@endif
@if(! $inspection['ok'])
<p class="notice danger">Integrity review required: {{ implode(' ', $inspection['issues']) }} Do not refund or adjust manually before investigating the ledger link.</p>
@else
<p>Integrity check: the intent, provider reference, wallet credit and refund state agree.</p>
@endif
<div class="kpi-grid">
<div class="kpi"><div>Amount</div><strong>{{ $fmt($intent->amount_minor) }} {{ $intent->currency }}</strong></div>
<div class="kpi"><div>Status</div><strong>{{ $intent->statusLabel() }}</strong></div>
<div class="kpi"><div>Provider</div><strong>{{ $intent->provider }}</strong></div>
<div class="kpi"><div>Callbacks</div><strong>{{ number_format($events->total()) }}</strong></div>
</div>
<div class="contact-panels">
<section><h2>Intent</h2>
<div class="table-scroll"><table><tbody>
<tr><th scope="row">Receipt</th><td>{{ $intent->receiptNumber() }}</td></tr>
<tr><th scope="row">Payment reference</th><td>{{ $intent->public_id }}</td></tr>
<tr><th scope="row">Provider reference</th><td>{{ $intent->provider_reference ?? '—' }}</td></tr>
<tr><th scope="row">Initiated by</th><td>{{ $intent->initiator?->name ?? 'System' }}</td></tr>
<tr><th scope="row">Request key</th><td><small>{{ $intent->request_key }}</small></td></tr>
<tr><th scope="row">Created</th><td>{{ $intent->created_at?->format('Y-m-d H:i:s') }} UTC</td></tr>
<tr><th scope="row">Pending review at</th><td>{{ $intent->expires_at?->format('Y-m-d H:i:s') ?? '—' }} UTC</td></tr>
<tr><th scope="row">Verified</th><td>{{ $intent->verified_at?->format('Y-m-d H:i:s') ?? '—' }} UTC</td></tr>
<tr><th scope="row">Credited</th><td>{{ $intent->credited_at?->format('Y-m-d H:i:s') ?? '—' }} UTC</td></tr>
<tr><th scope="row">Failed / cancelled</th><td>{{ ($intent->failed_at ?? $intent->cancelled_at)?->format('Y-m-d H:i:s') ?? '—' }} UTC</td></tr>
@if($intent->failure_message)<tr><th scope="row">Failure</th><td>{{ $intent->failure_code }} · {{ $intent->failure_message }}</td></tr>@endif
</tbody></table></div>
</section>
<section><h2>Ledger link</h2>
@if($intent->ledgerEntry)
<p>Wallet credit: <a href="{{ route('billing.wallets.show', $intent->client_id) }}">entry #{{ $intent->ledgerEntry->id }}</a> · {{ $fmt($intent->ledgerEntry->available_delta_minor) }} {{ $intent->ledgerEntry->currency }} posted {{ $intent->ledgerEntry->created_at?->format('Y-m-d H:i:s') }} UTC.</p>
@else
<p>No wallet credit is linked. A pending or failed payment must never have one.</p>
@endif
@if($intent->creditReversal)
<p>Refund reversal: entry #{{ $intent->creditReversal->id }} · {{ $fmt($intent->creditReversal->available_delta_minor) }} {{ $intent->creditReversal->currency }} posted {{ $intent->creditReversal->created_at?->format('Y-m-d H:i:s') }} UTC.</p>
@endif
@if($intent->status === 'succeeded' && ! $intent->creditReversal)
<h3>Record verified refund</h3>
<form method="post" action="{{ route('billing.payments.refund', $intent->public_id) }}">@csrf
<label>Provider refund reference<input name="refund_reference" required minlength="3" maxlength="64" value="{{ old('refund_reference') }}"></label>
<label>Reason<input name="reason" required minlength="3" maxlength="255" value="{{ old('reason') }}"></label>
<label>Approval reference<input name="approval" required minlength="3" maxlength="255" value="{{ old('approval') }}"></label>
<button>Post refund and reverse wallet credit</button>
</form>
<p>Only record a refund after the provider confirms it. This posts a separate reversing ledger entry; the original funding entry and receipt are never edited.</p>
@elseif($intent->status === 'refunded')
<p>Refund recorded {{ $intent->refunded_at?->format('Y-m-d H:i:s') }} UTC
@if($intent->refund_reference) · reference {{ $intent->refund_reference }}@endif.</p>
@endif
</section>
</div>

<h2>Verified callbacks</h2>
<section><div class="table-scroll"><table><thead><tr><th>Received (UTC)</th><th>Event ID</th><th>Type</th><th>Status</th><th>Amount</th><th>Result</th><th>Payload hash</th><th>Processed (UTC)</th></tr></thead><tbody>
@forelse($events as $event)
@php($efmt = fn ($minor) => \App\Services\Billing\Wallet::amount((int) $minor, $event->minor_unit_scale))
<tr>
<td>{{ $event->created_at?->format('Y-m-d H:i:s') }}</td>
<td><small>{{ $event->provider_event_id }}</small></td>
<td>{{ $event->event_type }}</td>
<td>{{ $event->status }}</td>
<td>{{ $efmt($event->amount_minor) }}</td>
<td>{{ str_replace('_', ' ', $event->result) }}</td>
<td><small title="{{ $event->payload_hash }}">{{ substr($event->payload_hash, 0, 12) }}…</small></td>
<td>{{ $event->processed_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
</tr>
@empty
<tr><td colspan="8">No verified callbacks have been received.</td></tr>
@endforelse
</tbody></table></div>{{ $events->links() }}</section>
<p>Callback payloads are encrypted at rest and are not displayed here. A callback with a mismatched amount, currency or provider reference is recorded but never credits a wallet.</p>
@endsection
