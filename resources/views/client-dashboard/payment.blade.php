@extends('dashboard.layout')
@section('title','Payment '.$intent->receiptNumber())
@section('content')
@php($fmt = fn ($minor) => \App\Services\Billing\Wallet::amount((int) $minor, $intent->minor_unit_scale))
<div class="contact-heading"><div><h1>Payment {{ $intent->receiptNumber() }}</h1><p>{{ $client->name }} · Started {{ $intent->created_at?->format('d M Y H:i') }} UTC</p></div><a class="button secondary" href="{{ route('wallet.payments.index') }}">← All top-ups</a></div>
@if(session('status'))<p class="notice">{{ session('status') }}</p>@endif
<div class="kpi-grid">
<div class="kpi"><div>Amount</div><strong>{{ $fmt($intent->amount_minor) }} {{ $intent->currency }}</strong></div>
<div class="kpi"><div>Status</div><strong>{{ $intent->statusLabel() }}</strong></div>
<div class="kpi"><div>Provider</div><strong>{{ $intent->provider }}</strong></div>
<div class="kpi"><div>Confirmed</div><strong>{{ $intent->credited_at?->format('d M Y H:i') ?? 'Not yet' }}</strong></div>
</div>
<p class="notice">{{ $intent->clientMessage() }}@if($intent->failure_message) {{ $intent->failure_message }}@endif</p>
@if($intent->isOpen())
@if($checkoutUrl)<p><a class="button" href="{{ $checkoutUrl }}" rel="noopener noreferrer">Resume secure payment</a></p>@endif
<p>This payment is waiting for the provider's verified confirmation. If you closed the provider page without paying, no credit will be applied. Payments still pending after {{ config('payments.pending_ttl_minutes', 30) }} minutes
@if($intent->expires_at)({{ $intent->expires_at->format('d M Y H:i') }} UTC)@endif
are flagged for Finance review, but a late verified confirmation can still credit the wallet. Reload this page for the latest status.</p>
@endif
<section><h2>Payment details</h2>
<div class="table-scroll"><table><tbody>
<tr><th scope="row">Receipt</th><td>{{ $intent->receiptNumber() }}</td></tr>
<tr><th scope="row">Payment reference</th><td>{{ $intent->public_id }}</td></tr>
<tr><th scope="row">Provider reference</th><td>{{ $intent->provider_reference ?? '—' }}</td></tr>
<tr><th scope="row">Wallet</th><td>#{{ $intent->wallet_id }} · {{ $intent->currency }} with {{ $intent->minor_unit_scale }} decimal place(s)</td></tr>
<tr><th scope="row">Started</th><td>{{ $intent->created_at?->format('d M Y H:i:s') }} UTC</td></tr>
<tr><th scope="row">Verified</th><td>{{ $intent->verified_at?->format('d M Y H:i:s') ?? '—' }} UTC</td></tr>
<tr><th scope="row">Credited</th><td>{{ $intent->credited_at?->format('d M Y H:i:s') ?? '—' }} UTC</td></tr>
@if($intent->refunded_at)<tr><th scope="row">Refunded</th><td>{{ $intent->refunded_at->format('d M Y H:i:s') }} UTC
@if($intent->refund_reference) · {{ $intent->refund_reference }}@endif</td></tr>@endif
</tbody></table></div>
@if($intent->isPaid())<p><a class="button" href="{{ route('wallet.payments.receipt', $intent->public_id) }}">View receipt</a></p>@endif
</section>

<h2>Provider updates</h2>
<section><div class="table-scroll"><table><thead><tr><th>Received (UTC)</th><th>Type</th><th>Provider status</th><th>Outcome</th></tr></thead><tbody>
@forelse($events as $event)
<tr>
<td>{{ $event->created_at?->format('d M Y H:i:s') }}</td>
<td>{{ str_replace('_',' ', $event->event_type) }}</td>
<td>{{ $event->status }}</td>
<td>
@switch($event->result)
@case('processed')Applied to your payment@break
@case('pending')Still processing@break
@case('duplicate')Already applied@break
@defaultNeeds review by Finance
@endswitch
</td>
</tr>
@empty
<tr><td colspan="4">No provider confirmation received yet.</td></tr>
@endforelse
</tbody></table></div></section>
<p>Only verified provider messages change a payment. Callbacks with the wrong amount, currency or reference are recorded for Finance review and never credit the wallet.</p>
@endsection
