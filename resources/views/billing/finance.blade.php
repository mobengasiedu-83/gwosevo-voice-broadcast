@extends('foundation.layout')
@section('title','Finance reports')
@section('content')
<h1>Finance reports and reconciliation</h1>
<p>Read-only view of per-attempt durations, rated charges, wallet settlements, payments, refunds and disputes. Nothing on this page changes a balance. <a href="{{ route('billing.rates') }}">Rate plans →</a> · <a href="{{ route('billing.wallets') }}">Wallets →</a> · <a href="{{ route('billing.payments') }}">Payments →</a> · <a href="{{ route('billing.disputes') }}">Disputes →</a></p>
@if(session('status'))<p class="notice">{{ session('status') }}</p>@endif
@php($fmt = fn ($minor, $scale) => \App\Services\Billing\Wallet::amount((int) $minor, (int) $scale))
<div class="kpi-grid">
<div class="kpi"><div>Attempts in period</div><strong>{{ number_format($summary['attempts']) }}</strong></div>
<div class="kpi"><div>Answered</div><strong>{{ number_format($summary['answered']) }}</strong></div>
<div class="kpi"><div>Billable minutes</div><strong>{{ number_format($summary['billable_minutes']) }}</strong></div>
<div class="kpi"><div>Wallets checked</div><strong>{{ number_format($summary['wallets_checked']) }}</strong></div>
<div class="kpi"><div>Unresolved billing exceptions</div><strong>{{ number_format($summary['unresolved_exceptions']) }}</strong></div>
<div class="kpi"><div>Open disputes</div><strong>{{ number_format($summary['open_disputes']) }}</strong></div>
</div>
@php($paymentOk = ($summary['payment_reconciliation']['status'] ?? 'ok') === 'ok')
@if(empty($summary['wallet_issues']) && $paymentOk)
<p>Reconciliation: every wallet balance, ledger chain, hold lifecycle and settlement in scope matches the append-only ledger; payment intents and verified callbacks agree with wallet credits. No balances were changed automatically.</p>
@else
<p class="notice">Reconciliation needs attention. {{ count($summary['wallet_issues']) }} wallet(s) failed ledger verification and {{ $summary['payment_reconciliation']['issues_found'] ?? 0 }} payment issue(s) were reported. No balances were changed automatically. Escalate to Finance before posting further credits.</p>
@endif
@if(! empty($summary['wallet_issues']))
<section><h2>Wallet reconciliation failures</h2><div class="table-scroll"><table><thead><tr><th>Client</th><th>Issues</th></tr></thead><tbody>
@foreach($summary['wallet_issues'] as $issue)
<tr><td>{{ $issue['client'] ?? 'Client #'.$issue['client_id'] }}</td><td>{{ implode(' ', $issue['issues']) }}</td></tr>
@endforeach
</tbody></table></div></section>
@endif
<h2>Period</h2>
<form method="get" class="campaign-filters">
<label>From<input type="date" name="from" value="{{ $input['from'] ?? $summary['period']['from'] }}"></label>
<label>To<input type="date" name="to" value="{{ $input['to'] ?? $summary['period']['to'] }}"></label>
<label>Client<select name="client_id"><option value="">All clients</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected((string) ($input['client_id'] ?? '') === (string) $client->id)>{{ $client->name }}</option>@endforeach</select></label>
<label>Currency<select name="currency"><option value="">All currencies</option>@foreach(['GHS','USD','EUR','GBP'] as $currency)<option value="{{ $currency }}" @selected(($input['currency'] ?? '') === $currency)>{{ $currency }}</option>@endforeach</select></label>
<button>Run report</button>
<a class="button secondary" href="{{ route('billing.finance.export', $input) }}">Export attempts CSV</a>
</form>
<h2>Currency reconciliation</h2>
<section><div class="table-scroll"><table><thead><tr><th>Currency</th><th>Attempts / answered</th><th>Rated charges</th><th>Settled from wallet</th><th>Shortfall</th><th>Payments</th><th>Refunds</th><th>Funding</th><th>Adjustments</th><th>Available / reserved</th></tr></thead><tbody>
@forelse($summary['currencies'] as $currency)
<tr>
<td>{{ $currency['currency'] }} · {{ $currency['minor_unit_scale'] }} dp</td>
<td>{{ number_format($currency['attempts']) }} / {{ number_format($currency['answered']) }}</td>
<td>{{ $fmt($currency['charge_minor'], $currency['minor_unit_scale']) }}</td>
<td>{{ $fmt((int) $currency['settled_minor'] - (int) $currency['shortfall_minor'], $currency['minor_unit_scale']) }}</td>
<td>{{ $fmt($currency['shortfall_minor'], $currency['minor_unit_scale']) }}</td>
<td>{{ $fmt($currency['payments_minor'], $currency['minor_unit_scale']) }}</td>
<td>{{ $fmt($currency['refunds_minor'], $currency['minor_unit_scale']) }}</td>
<td>{{ $fmt($currency['funding_minor'], $currency['minor_unit_scale']) }}</td>
<td>{{ $fmt($currency['adjustment_minor'], $currency['minor_unit_scale']) }}</td>
<td>{{ $fmt($currency['available_minor'], $currency['minor_unit_scale']) }} / {{ $fmt($currency['reserved_minor'], $currency['minor_unit_scale']) }}</td>
</tr>
@empty
<tr><td colspan="12">No wallet or attempt activity in this period.</td></tr>
@endforelse
</tbody></table></div></section>
<p>Rated charges are Module 12 amounts. Settled from wallet is the amount actually debited for the attempts in the period (rated charge less any uncovered shortfall). Funding and adjustments are ledger movement in the same period; funding includes both manual and verified payment credits, so Payments is a provider view of the same money, not an additional total. Current balances are shown separately and are verified against the append-only ledger above.</p>
<h2>Client usage and balances</h2>
<section><div class="table-scroll"><table><thead><tr><th>Client</th><th>Wallet</th><th>Attempts / answered</th><th>Minutes</th><th>Charges</th><th>Settled</th><th>Shortfall</th><th>Open disputes</th></tr></thead><tbody>
@forelse($clientRows as $clientRow)
<tr>
<td>{{ $clientRow->client_name }}</td>
<td>{{ $clientRow->currency ? $fmt($clientRow->available_minor, $clientRow->minor_unit_scale).' '.$clientRow->currency : 'No wallet' }}</td>
<td>{{ number_format((int) $clientRow->attempts) }} / {{ number_format((int) $clientRow->answered) }}</td>
<td>{{ number_format((int) $clientRow->billable_minutes) }}</td>
<td>{{ $clientRow->currency ? $fmt($clientRow->charge_minor, $clientRow->minor_unit_scale).' '.$clientRow->currency : '—' }}</td>
<td>{{ $clientRow->currency ? $fmt((int) $clientRow->settled_minor - (int) $clientRow->shortfall_minor, $clientRow->minor_unit_scale).' '.$clientRow->currency : '—' }}</td>
<td>{{ $clientRow->currency ? $fmt($clientRow->shortfall_minor, $clientRow->minor_unit_scale).' '.$clientRow->currency : '—' }}</td>
<td>{{ number_format($clientRow->open_disputes) }}</td>
</tr>
@empty
<tr><td colspan="8">No client activity in this period.</td></tr>
@endforelse
</tbody></table></div></section>
<section><h2>Separate carrier expenses</h2><p>Calculated from actual answered call time and each attempt's saved carrier rate. These expenses do not debit the client wallet.</p>
@forelse($summary['carrier_expenses'] as $carrier)<p>{{ $carrier->currency }} {{ $fmt($carrier->cost_minor, $carrier->minor_unit_scale) }} · {{ number_format($carrier->billed_minutes) }} carrier billed minutes</p>@empty<p>No priced carrier usage in this period. Historical costs without a saved carrier rate remain unavailable.</p>@endforelse</section>
<h2>Per-attempt durations and charges</h2>
<section><div class="table-scroll"><table><thead><tr><th>Ended (UTC)</th><th>Client</th><th>Campaign</th><th>Attempt</th><th>Answered</th><th>Duration</th><th>Billed minutes</th><th>Charge</th><th>Reservation</th><th>Settlement entry</th><th>Carrier billed minutes</th><th>Carrier cost (calculated)</th></tr></thead><tbody>
@forelse($rows as $row)
<tr>
<td>{{ $row->ended_at ? \Carbon\CarbonImmutable::parse($row->ended_at)->utc()->format('Y-m-d H:i:s') : '—' }}</td>
<td>{{ $row->client_name }}</td>
<td>{{ $row->campaign_name }}</td>
<td><small class="mono">{{ $row->attempt_uuid }}</small><br>{{ $row->outcome ?? $row->state }}</td>
<td>{{ $row->answered_at ? 'Yes' : 'No' }}</td>
<td>{{ $row->duration_ms === null ? '—' : number_format($row->duration_ms).' ms' }}</td>
<td>{{ $row->rated_minutes === null ? '—' : number_format($row->rated_minutes) }}</td>
<td>{{ $row->amount_minor === null ? '—' : $fmt($row->amount_minor, $row->rating_scale ?? $row->wallet_scale) .' '.($row->rating_currency ?? $row->wallet_currency) }}</td>
<td>{{ $row->reservation_state ?? '—' }}@if($row->shortfall_minor > 0)<br><small>Shortfall {{ $fmt($row->shortfall_minor, $row->rating_scale ?? $row->wallet_scale) }}</small>@endif</td>
<td>{{ $row->settlement_entry_id ? '#'.$row->settlement_entry_id : '—' }}</td><td>{{ $row->telecom_billed_minutes ?? '—' }}</td><td>{{ $row->telecom_cost_minor === null ? '—' : $row->telecom_currency.' '.$fmt($row->telecom_cost_minor, $row->telecom_scale) }}</td>
</tr>
@empty
<tr><td colspan="12">No attempts ended in this period. Confirm the date range and that calls reached a terminal state.</td></tr>
@endforelse
</tbody></table></div>{{ $rows->links() }}</section>
<p>Actual call duration is recorded separately from client billed minutes, which use the frozen recording length. Carrier billed minutes use actual answered time. Client charges are the immutable rating amount. Reports include unanswered attempts at zero charge. CSV export contains the same authoritative fields and is permission-scoped.</p>
@endsection
