@extends('foundation.layout')
@section('title','Rate plans')
@section('content')
<h1>Rate plans and client assignments</h1>
<p>Approved call tariffs are immutable versions. Rates are stored in integer minor units per billed minute; clients are priced only through their assigned version. Module 12 ratings freeze the assigned snapshot on each attempt. <a href="{{ route('billing.finance') }}">Open finance reports →</a></p>
@if(session('status'))<p class="notice">{{ session('status') }}</p>@endif
<div class="kpi-grid">
<div class="kpi"><div>Tariff versions</div><strong>{{ number_format($stats['versions']) }}</strong></div>
<div class="kpi"><div>Clients assigned</div><strong>{{ number_format($stats['assigned']) }}</strong></div>
<div class="kpi"><div>Clients without a tariff</div><strong>{{ number_format($stats['unassigned']) }}</strong></div>
<div class="kpi"><div>Wallet / tariff mismatches</div><strong>{{ number_format($stats['mismatches']) }}</strong></div>
</div>
@if($stats['mismatches'])
<p class="notice">{{ number_format($stats['mismatches']) }} client wallet(s) do not match their assigned tariff currency or minor-unit scale. Wallet admission fails closed for those accounts. Correct the assignment or open the wallet in the matching denomination.</p>
@endif
<div class="contact-panels">
<section><h2>Create an approved rate version</h2>
<form method="post" action="{{ route('billing.rates.store') }}" class="stack-form">@csrf
<label>Tariff code<input name="code" required maxlength="80" value="{{ old('code') }}" placeholder="ghs-standard"></label>
<label>Version<input name="version" type="number" required min="1" max="1000000" value="{{ old('version',1) }}"></label>
<label>Currency<input name="currency" required maxlength="3" value="{{ old('currency','GHS') }}" placeholder="GHS"></label>
<label>Minor-unit scale<input name="minor_unit_scale" type="number" required min="0" max="6" value="{{ old('minor_unit_scale',2) }}"></label>
<label>Rate per billed minute (minor units)<input name="rate_minor_per_minute" type="number" required min="0" max="1000000000" step="1" inputmode="numeric" value="{{ old('rate_minor_per_minute') }}" placeholder="30"></label>
<p><small>Enter whole minor units, not the decimal amount. For GHS with scale 2, enter <strong>30</strong> for GHS 0.30 per minute (1 cedi = 100 pesewas).</small></p>
<label>Playback policy<select name="playback_policy"><option value="charge_answered">Charge answered calls</option><option value="hold_on_failure">Hold on playback failure</option></select></label>
<label>Approval evidence<input name="evidence" required maxlength="1000" value="{{ old('evidence') }}" placeholder="Finance approval, effective date or ticket"></label>
<button>Create immutable version</button>
</form>
<p>Existing versions cannot be edited or deleted. Create a new version and assign it when commercial pricing changes.</p>
</section>
<section><h2>Assign a tariff</h2>
<form method="post" action="{{ route('billing.rates.assign') }}" class="stack-form">@csrf
<label>Client<select name="client_id" required><option value="">Select client</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected((string) old('client_id') === (string) $client->id)>{{ $client->name }} · {{ $client->status }}</option>@endforeach</select></label>
<label>Tariff version<select name="tariff_id" required><option value="">Select tariff</option>@foreach($tariffOptions as $tariff)<option value="{{ $tariff->id }}" @selected((string) old('tariff_id') === (string) $tariff->id)>{{ $tariff->code }} v{{ $tariff->version }} · {{ $tariff->currency }} {{ $tariff->rate_minor_per_minute }} minor/min</option>@endforeach</select></label>
<label>Reason<input name="reason" required minlength="3" maxlength="1000" value="{{ old('reason') }}" placeholder="Approved commercial assignment"></label>
<button>Assign tariff</button>
</form>
<p>A funded wallet cannot change currency or scale. If the selected tariff differs from the wallet denomination, the assignment is rejected and audited.</p>
</section>
</div>
<h2>Client assignments</h2>
<form method="get" class="campaign-filters">
<label>Search<input name="q" maxlength="80" value="{{ $filters['q'] ?? '' }}" placeholder="Client or tariff code"></label>
<label>Currency<select name="currency"><option value="">All currencies</option>@foreach(['GHS','USD','EUR','GBP'] as $currency)<option value="{{ $currency }}" @selected(($filters['currency'] ?? '') === $currency)>{{ $currency }}</option>@endforeach</select></label>
<button>Filter</button>
</form>
<section><div class="table-scroll"><table><thead><tr><th>Client</th><th>Status</th><th>Assigned tariff</th><th>Rate</th><th>Wallet</th><th>Denomination</th><th>Updated (UTC)</th></tr></thead><tbody>
@forelse($assignments as $assignment)
<tr>
<td>{{ $assignment->client_name }}</td>
<td>{{ $assignment->status }}</td>
<td>{{ $assignment->code }} v{{ $assignment->version }} · {{ $assignment->playback_policy }}</td>
<td>{{ \App\Services\Billing\Wallet::amount((int) $assignment->rate_minor_per_minute, (int) $assignment->minor_unit_scale) }} {{ $assignment->currency }} / min</td>
<td>{{ $assignment->wallet_currency ? \App\Services\Billing\Wallet::amount((int) $assignment->available_minor, (int) $assignment->wallet_scale).' '.$assignment->wallet_currency : 'No wallet' }}</td>
<td>
@if($assignment->wallet_currency && ($assignment->wallet_currency !== $assignment->currency || (int) $assignment->wallet_scale !== (int) $assignment->minor_unit_scale))
<strong>Mismatch</strong>
@else
Matches
@endif
</td>
<td>{{ $assignment->updated_at ? \Carbon\CarbonImmutable::parse($assignment->updated_at)->utc()->format('Y-m-d H:i') : '—' }}</td>
</tr>
@empty
<tr><td colspan="7">No client tariffs are assigned.</td></tr>
@endforelse
</tbody></table></div>{{ $assignments->links() }}</section>
<h2>Tariff versions</h2>
<section><div class="table-scroll"><table><thead><tr><th>Code</th><th>Version</th><th>Currency</th><th>Scale</th><th>Rate / minute</th><th>Policy</th><th>Evidence</th><th>Created (UTC)</th></tr></thead><tbody>
@forelse($tariffs as $tariff)
<tr><td>{{ $tariff->code }}</td><td>{{ $tariff->version }}</td><td>{{ $tariff->currency }}</td><td>{{ $tariff->minor_unit_scale }}</td><td>{{ \App\Services\Billing\Wallet::amount((int) $tariff->rate_minor_per_minute, (int) $tariff->minor_unit_scale) }}</td><td>{{ $tariff->playback_policy }}</td><td>{{ $tariff->evidence }}</td><td>{{ $tariff->created_at ? \Carbon\CarbonImmutable::parse($tariff->created_at)->utc()->format('Y-m-d H:i') : '—' }}</td></tr>
@empty
<tr><td colspan="8">No tariff versions yet.</td></tr>
@endforelse
</tbody></table></div>{{ $tariffs->links() }}</section>
@endsection
