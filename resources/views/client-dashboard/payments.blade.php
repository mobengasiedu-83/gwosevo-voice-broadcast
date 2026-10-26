@extends('dashboard.layout')
@section('title','Top-ups and receipts')
@section('content')
@php($fmt = fn ($minor) => \App\Services\Billing\Wallet::amount((int) $minor, $wallet?->minor_unit_scale ?? 2))
<div class="contact-heading"><div><h1>Top-ups &amp; receipts</h1><p>{{ $client->name }} · Online calling-credit payments</p></div><a class="button secondary" href="{{ route('wallet.index') }}">← Calling credit</a></div>
@if(session('status'))<p class="notice">{{ session('status') }}</p>@endif
@if(! $wallet)
<section><h2>Wallet not configured</h2><p>Finance must open a calling wallet before online top-ups can be accepted. No payment can be started from this account yet.</p></section>
@else
<div class="kpi-grid">
<div class="kpi"><div>Wallet currency</div><strong>{{ $wallet->currency }}</strong></div>
<div class="kpi"><div>Minimum top-up</div><strong>{{ $fmt($limits['min_minor']) }} {{ $wallet->currency }}</strong></div>
<div class="kpi"><div>Maximum top-up</div><strong>{{ $fmt($limits['max_minor']) }} {{ $wallet->currency }}</strong></div>
<div class="kpi"><div>Review window</div><strong>{{ $limits['ttl_minutes'] }} min</strong></div>
</div>

<section><h2>Add calling credit</h2>
@if($onlineEnabled)
<form method="post" action="{{ route('wallet.payments.store') }}">@csrf
<input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
<label>Amount ({{ $wallet->currency }})<input name="amount" value="{{ old('amount') }}" required maxlength="32" inputmode="decimal" placeholder="50.00" autocomplete="off"></label>
<button>Continue to secure payment</button>
</form>
<p>You will be redirected to the payment provider. Credit is added once only after the provider sends a verified confirmation. Closing the provider page before paying leaves your wallet unchanged. Card details are never stored by this platform.</p>
@else
<p class="notice">Online payments are not enabled in this environment. Contact Finance to post an approved top-up. Existing receipts remain available below.</p>
@endif
</section>

<h2>Payment history</h2>
<form method="get" class="campaign-filters">
<label>Payment status<select name="status"><option value="">All statuses</option>@foreach(\App\Models\PaymentIntent::STATUSES as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
<button>Filter payments</button>
</form>
<section><div class="table-scroll"><table><thead><tr><th>Started (UTC)</th><th>Reference</th><th>Amount</th><th>Status</th><th>Credited (UTC)</th><th>Receipt</th></tr></thead><tbody>
@forelse($payments as $payment)
<tr>
<td>{{ $payment->created_at?->format('d M Y H:i') }}</td>
<td><a href="{{ route('wallet.payments.show', $payment->public_id) }}">{{ $payment->receiptNumber() }}</a></td>
<td>{{ $fmt($payment->amount_minor) }} {{ $payment->currency }}</td>
<td>{{ $payment->statusLabel() }}@if($payment->failure_message)<br><small>{{ $payment->failure_message }}</small>@endif</td>
<td>{{ $payment->credited_at?->format('d M Y H:i') ?? '—' }}</td>
<td>@if($payment->isPaid())<a href="{{ route('wallet.payments.receipt', $payment->public_id) }}">Receipt</a>@else—@endif</td>
</tr>
@empty
<tr><td colspan="6">No online top-ups yet.</td></tr>
@endforelse
</tbody></table></div>{{ $payments->links() }}</section>
<p>Need a correction, refund or an explanation of a charge? Contact Finance with the receipt reference. Posted ledger entries are never edited; corrections appear as separate entries.</p>
@endif
@endsection
