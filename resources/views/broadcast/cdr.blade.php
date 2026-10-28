@extends('foundation.layout')
@section('content')
<h2>Call detail records (CDR)</h2>
<p>Every call attempt is recorded. Answered calls are charged the full recording length immediately, rounded up to minutes. Call duration and playback completion do not change the charge. Carrier expense uses actual answered time at its own saved rate and does not debit the client wallet. An unavailable historical carrier price is shown as —. Times are UTC.</p>
<form method="get" action="{{ route('cdr.index') }}">
    <label>MSISDN <input name="msisdn" value="{{ $filters['msisdn'] ?? '' }}" placeholder="+233…"></label>
    <label>From <input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
    <label>To <input type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
    <label>Campaign ID <input type="number" min="1" name="campaign_id" value="{{ $filters['campaign_id'] ?? '' }}"></label>
    <label>Answer <select name="answered"><option value="">All</option><option value="1" @selected(($filters['answered'] ?? '') === '1')>Answered</option><option value="0" @selected(($filters['answered'] ?? '') === '0')>Unanswered</option></select></label>
    <button type="submit">Filter</button> <a href="{{ route('cdr.index') }}">Reset</a>
</form>
<p><a href="{{ route('cdr.export', $filters) }}">Download CDR CSV</a> · {{ $attempts->total() }} call attempts</p>
<div style="overflow-x:auto"><table><thead><tr><th>Attempt / Campaign</th><th>MSISDN</th><th>State / Outcome</th><th>Sent (UTC)</th><th>Answered (UTC)</th><th>Ended (UTC)</th><th>Time spent</th><th>Recording length</th><th>Billing basis</th><th>Billed minutes</th><th>Charge</th><th>Deducted</th><th>Billing</th><th>Carrier billed minutes</th><th>Carrier cost</th></tr></thead><tbody>
@forelse($attempts as $a)
@php
    $r = $a->reservation;
    $scale = $a->rating?->minor_unit_scale ?? $a->tariff_snapshot['minor_unit_scale'] ?? 2;
    $currency = $a->rating?->currency ?? $a->tariff_snapshot['currency'] ?? '';
    $charge = $a->rating?->amount_minor ?? ($a->answered_at ? ($a->recordingMinutes() ?? 0) * ($a->tariff_snapshot['rate_minor_per_minute'] ?? 0) : 0);
    $paid = $r?->state === 'settled' ? $r->settled_minor - $r->shortfall_minor : ($r?->answer_paid_minor ?? 0);
@endphp
<tr><td>{{ $a->id }} / {{ $a->recipient?->campaign_id }}<br><small>{{ $a->uuid }}</small></td><td>{{ $a->recipient?->phone_e164 ?? '—' }}</td><td>{{ $a->state }} / {{ $a->outcome ?? 'In progress' }}</td><td>{{ $a->sent_at }}</td><td>{{ $a->answered_at ?? '—' }}</td><td>{{ $a->ended_at ?? '—' }}</td><td>{{ number_format($a->timeSpentMs() / 1000, 3) }} s</td><td>{{ $a->recordingDurationMs() === null ? 'Unavailable' : number_format($a->recordingDurationMs() / 1000, 3).' s' }}</td><td>{{ ($a->rating?->policy_version ?? 'recording_minute_v1') === 'recording_minute_v1' ? 'Recording length' : 'Historical call duration' }}</td><td>{{ $a->rating?->billable_minutes ?? ($a->answered_at ? $a->recordingMinutes() : 0) }}</td><td>{{ $currency }} {{ \App\Services\Billing\Wallet::amount($charge, $scale) }}@if(!$a->rating && $a->answered_at) (recording cost)@endif</td><td>{{ $currency }} {{ \App\Services\Billing\Wallet::amount($paid, $scale) }}</td><td>{{ $r?->state ?? 'No reservation' }}@if($charge > $paid) · Unpaid {{ \App\Services\Billing\Wallet::amount($charge - $paid, $scale) }}@endif</td><td>{{ $a->telecomUsage?->billed_minutes ?? '—' }}</td><td>{{ $a->telecomUsage?->cost_minor === null ? '—' : $a->telecomUsage->currency.' '.\App\Services\Billing\Wallet::amount($a->telecomUsage->cost_minor, $a->telecomUsage->minor_unit_scale) }}</td></tr>
@empty<tr><td colspan="15">No calls match these filters.</td></tr>@endforelse
</tbody></table></div>
{{ $attempts->links() }}
@endsection
