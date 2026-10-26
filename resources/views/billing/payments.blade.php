@extends('foundation.layout')
@section('title','Payments')
@section('content')
<h1>Online payments</h1>
<p>Verified top-up intents, provider callbacks and reconciliation. <a href="{{ route('billing.wallets') }}">Wallets</a></p>
@if(session('status'))<p class="notice">{{ session('status') }}</p>@endif
@if(! $providerEnabled)
<p class="notice">The payment provider is disabled or not fully configured. Clients cannot start new top-ups. Callbacks are still verified and recorded when a provider is named and its secret is configured.</p>
@endif
<div class="kpi-grid">
<div class="kpi"><div>Pending</div><strong>{{ number_format($counts['pending'] ?? 0) }}</strong></div>
<div class="kpi"><div>Paid</div><strong>{{ number_format($counts['succeeded'] ?? 0) }}</strong></div>
<div class="kpi"><div>Failed / cancelled</div><strong>{{ number_format(($counts['failed'] ?? 0) + ($counts['cancelled'] ?? 0)) }}</strong></div>
<div class="kpi"><div>Refunded</div><strong>{{ number_format($counts['refunded'] ?? 0) }}</strong></div>
</div>
<form method="post" action="{{ route('billing.payments.reconcile') }}">@csrf
<button>Run payment reconciliation</button>
<p>Read-only check of intents, wallet credits, callbacks and stale pending requests. Reconciliation never changes balances; all issues require manual review.</p>
</form>

<h2>Payment intents</h2>
<form method="get" class="campaign-filters">
<label>Status<select name="status"><option value="">All statuses</option>@foreach(\App\Models\PaymentIntent::STATUSES as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
<label>Provider<input name="provider" maxlength="32" value="{{ $filters['provider'] ?? '' }}" placeholder="e.g. xcel"></label>
<label>Search<input name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Receipt, reference or client"></label>
<button>Filter payments</button>
</form>
<section><div class="table-scroll"><table><thead><tr><th>Started (UTC)</th><th>Receipt / reference</th><th>Client</th><th>Provider reference</th><th>Amount</th><th>Status</th><th>Credited (UTC)</th></tr></thead><tbody>
@forelse($intents as $intent)
@php($fmt = fn ($minor) => \App\Services\Billing\Wallet::amount((int) $minor, $intent->minor_unit_scale))
<tr>
<td>{{ $intent->created_at?->format('d M Y H:i') }}</td>
<td><a href="{{ route('billing.payments.show', $intent->public_id) }}">{{ $intent->receiptNumber() }}</a><br><small>{{ $intent->public_id }}</small></td>
<td><a href="{{ route('clients.show', $intent->client_id) }}">{{ $intent->client?->name ?? 'Unknown' }}</a></td>
<td>{{ $intent->provider_reference ?? '—' }}</td>
<td>{{ $fmt($intent->amount_minor) }} {{ $intent->currency }}</td>
<td>{{ $intent->statusLabel() }}</td>
<td>{{ $intent->credited_at?->format('d M Y H:i') ?? '—' }}</td>
</tr>
@empty
<tr><td colspan="7">No payment intents match.</td></tr>
@endforelse
</tbody></table></div>{{ $intents->links() }}</section>

<h2>Recent reconciliation runs</h2>
<section><div class="table-scroll"><table><thead><tr><th>Run</th><th>Started (UTC)</th><th>Actor</th><th>Provider</th><th>Checked</th><th>Paid</th><th>Pending</th><th>Failed</th><th>Refunded</th><th>Issues</th><th>Status</th></tr></thead><tbody>
@forelse($reconciliations as $run)
<tr>
<td>{{ $run->id }}</td>
<td>{{ $run->created_at?->format('d M Y H:i') }}</td>
<td>{{ $run->actor?->name ?? 'System' }}</td>
<td>{{ $run->provider ?? '—' }}</td>
<td>{{ number_format($run->intents_checked) }}</td>
<td>{{ number_format($run->credited_count) }}</td>
<td>{{ number_format($run->pending_count) }}</td>
<td>{{ number_format($run->failed_count) }}</td>
<td>{{ number_format($run->refunded_count) }}</td>
<td>{{ number_format($run->issue_count) }}</td>
<td>{{ $run->status === 'ok' ? 'OK' : 'Attention' }}</td>
</tr>
@empty
<tr><td colspan="11">No reconciliation runs yet.</td></tr>
@endforelse
</tbody></table></div></section>
<p>Final states are immutable: a newer callback cannot reverse a paid or refunded payment. Duplicate provider events are stored once. Amounts, currencies and provider references must match the original intent before credit is posted.</p>
@endsection
