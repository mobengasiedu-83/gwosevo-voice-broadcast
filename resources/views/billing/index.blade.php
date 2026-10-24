@extends('foundation.layout')
@section('title', 'Wallets and billing policy')
@section('content')
<h1>Wallets and pricing</h1>
<p>Configure billing policies here, then assign a version to each client account. Existing executions keep their launch policy. Unanswered calls are always free; refunds remain audited.</p>
@can('billing.manage')
<section>
    <h2>Configure billing policy</h2>
    <p>Saving creates a new version. Choose an existing version below to use its settings as a starting point.</p>
    <form method="post" action="{{ route('billing.price') }}">
        @csrf
        <label>Plan code<input name="plan_code" required maxlength="80" value="{{ old('plan_code', $selectedRate?->plan_code ?? 'ghs-recording') }}"></label>
        <label>Currency code<input name="currency" required pattern="[A-Z]{3}" maxlength="3" value="{{ old('currency', $selectedRate?->currency ?? 'GHS') }}"></label>
        <label>Decimal places for accounting precision<input type="number" name="minor_digits" required min="0" max="6" value="{{ old('minor_digits', $selectedRate?->minor_digits ?? 3) }}"></label>
        <label>Price per minute in the selected currency<input name="amount" required inputmode="decimal" value="{{ old('amount', $selectedRate ? \App\Services\Billing\Ledger::format($selectedRate->rate_minor_per_minute, $selectedRate->minor_digits) : '0.003') }}"></label>
        <p>For GHS, 0.003 cedis equals 0.30 pesewas; 0.300 cedis equals 30 pesewas. Existing wallet currency and precision cannot be changed.</p>
        <label>Billable duration<select name="duration_basis" required>
            @foreach(['recording' => 'Recording length', 'answered' => 'Verified actual answered duration'] as $value => $label)
                <option value="{{ $value }}" @selected(old('duration_basis', $selectedRate?->duration_basis ?? 'recording') === $value)>{{ $label }}</option>
            @endforeach
        </select></label>
        <label>Round duration up in<select name="increment_seconds" required>
            @foreach([1, 6, 30, 60] as $seconds)
                <option value="{{ $seconds }}" @selected((int) old('increment_seconds', $selectedRate?->increment_seconds ?? 60) === $seconds)>{{ $seconds }} second(s)</option>
            @endforeach
        </select></label>
        <p>Answered calls have a minimum of one increment. The final monetary amount rounds up to the selected accounting precision.</p>
        <label>Confirmed playback failures<select name="playback_failure_policy" required>
            @foreach(['charge' => 'Charge using the selected duration policy', 'waive' => 'Do not charge', 'review' => 'Hold credit for administrator review'] as $value => $label)
                <option value="{{ $value }}" @selected(old('playback_failure_policy', $selectedRate?->playback_failure_policy ?? 'charge') === $value)>{{ $label }}</option>
            @endforeach
        </select></label>
        <label>Credit reservation duration for actual-duration billing (seconds)<input type="number" name="reservation_seconds" required min="1" max="86400" value="{{ old('reservation_seconds', $selectedRate?->reservation_seconds ?? 620) }}"></label>
        <p>Used for actual-duration pricing on one-way campaigns. This reserves credit and does not limit the call length. Missing verified duration or a charge exceeding the hold requires reconciliation; additional credit may be needed. Recording-length policies reserve the recording's calculated charge.</p>
        <label>Reason and approval/evidence reference<input name="reason" required maxlength="2000" value="{{ old('reason') }}"></label>
        <button>Save new policy version</button>
    </form>
</section>
@endcan
<section>
    <h2>Policy versions</h2>
    <div class="table-scroll"><table><thead><tr><th>Plan / version</th><th>Price per minute</th><th>Billing policy</th><th>Configure</th></tr></thead><tbody>
    @foreach($rates as $rate)
        <tr><td>{{ $rate->plan_code }} v{{ $rate->version }}</td><td>{{ $rate->currency }} {{ \App\Services\Billing\Ledger::format($rate->rate_minor_per_minute, $rate->minor_digits) }}</td><td>{{ \App\Services\Billing\Policy::description($rate->toArray()) }}</td><td>@can('billing.manage')<a href="{{ route('billing.index', ['version' => $rate->id]) }}">Use as starting point</a>@endcan</td></tr>
    @endforeach
    </tbody></table></div>
</section>
<section>
    <h2>Accounts and prices</h2>
    <div class="table-scroll"><table><thead><tr><th>Account</th><th>Wallet / assigned version</th><th>Assign policy for future runs</th></tr></thead><tbody>
    @foreach($accounts as $account)
        @php($wallet = $wallets[$account->id] ?? null)
        <tr><td>{{ $account->name }}</td><td>@if($wallet)<a href="{{ route('billing.wallet', $wallet) }}">{{ $wallet->currency }} {{ \App\Services\Billing\Ledger::format($wallet->balance_minor, $wallet->minor_digits) }}</a><small>Policy ID {{ $wallet->rate_version_id }}</small>@else Not configured @endif</td><td>
        @can('billing.manage')
            <form method="post" action="{{ route('billing.assign', $account) }}">@csrf
                <label>Policy<select name="rate_version_id" required>
                @foreach($rates as $rate)
                    <option value="{{ $rate->id }}" @selected($wallet?->rate_version_id == $rate->id)>{{ $rate->plan_code }} v{{ $rate->version }} · {{ $rate->currency }} {{ \App\Services\Billing\Ledger::format($rate->rate_minor_per_minute, $rate->minor_digits) }}/min · {{ $rate->duration_basis }}</option>
                @endforeach
                </select></label>
                <label>Reason<input name="reason" required maxlength="2000"></label><button>Assign</button>
            </form>
        @endcan
        </td></tr>
    @endforeach
    </tbody></table></div>{{ $accounts->links() }}
</section>
@endsection
