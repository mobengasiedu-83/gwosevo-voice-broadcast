@php
$scale = $estimate['minor_unit_scale'] ?? 0;
$money = function (?int $minor) use ($scale): string {
return $minor === null ? '—' : \App\Services\Billing\CampaignEstimate::money($minor, $scale);
};
$walletCurrency = $estimate['wallet_currency'] ?? $estimate['currency'];
@endphp
<section class="campaign-estimate" aria-labelledby="campaign-estimate-title">
    <h2 id="campaign-estimate-title">Credit forecast</h2>
    <p>One attempt for every eligible contact. Unanswered calls are not charged; retries only re-dial busy, failed or unanswered attempts.</p>
    <div class="kpi-grid campaign-estimate-grid">
        <div class="kpi">
            <div>Contacts in group</div>
            <strong>{{ number_format($estimate['audience_total']) }}</strong>
            <small>
                @if($estimate['group_total'] > 0)
                {{ number_format($estimate['group_total']) }} in the selected group @if($estimate['audience_total'] > $estimate['group_total'])· {{ number_format($estimate['audience_total'] - $estimate['group_total']) }} added by ID @endif
                @elseif($estimate['audience_total'] > 0)
                {{ number_format($estimate['audience_total']) }} added by contact ID
                @else
                Choose a group or add contact IDs
                @endif
            </small>
        </div>
        <div class="kpi">
            <div>Eligible contacts after consent/suppression checks</div>
            <strong>{{ number_format($estimate['eligible']) }}</strong>
            <small>{{ number_format($estimate['suppressed']) }} on DND/suppression · {{ number_format($estimate['unavailable']) }} removed · {{ number_format($estimate['consent_on_record']) }} with consent on record</small>
        </div>
        <div class="kpi">
            <div>Recording duration</div>
            <strong>{{ $estimate['duration_label'] ?? '—' }}</strong>
            <small>
                @if($estimate['minutes_per_call'] !== null)
                {{ number_format($estimate['minutes_per_call']) }} billable {{ $estimate['minutes_per_call'] === 1 ? 'minute' : 'minutes' }} per answered call (rounded up)
                @else
                Select a recording to price calls
                @endif
            </small>
        </div>
        <div class="kpi">
            <div>Estimated credit for one attempt each</div>
            <strong>{{ $estimate['total_minor'] === null ? '—' : $money($estimate['total_minor']).' '.$estimate['currency'] }}</strong>
            <small>
                @if($estimate['per_call_minor'] !== null)
                {{ number_format($estimate['eligible']) }} × {{ $money($estimate['per_call_minor']) }} {{ $estimate['currency'] }} per answered call
                @if(($estimate['campaign_hold_minor'] ?? null) !== null)
                · held in full when the campaign starts
                @endif
                @else
                Not priced yet
                @endif
            </small>
        </div>
        <div class="kpi">
            <div>Available credit</div>
            <strong>{{ $estimate['available_minor'] === null ? '—' : $money($estimate['available_minor']).' '.$walletCurrency }}</strong>
            <small>
                @if($estimate['available_minor'] === null)
                {{ $estimate['billing_applicable'] ? 'No wallet balance available' : 'Internal campaign · not wallet-billed' }}
                @else
                Open holds are already excluded
                @endif
            </small>
        </div>
        <div class="kpi">
            <div>Estimated additional credit needed</div>
            <strong>{{ $estimate['additional_minor'] === null ? '—' : $money($estimate['additional_minor']).' '.$estimate['currency'] }}</strong>
            <small>
                @if($estimate['additional_minor'] === null)
                Add a tariff and wallet to compare
                @elseif($estimate['additional_minor'] === 0)
                Balance covers all {{ number_format($estimate['eligible']) }} calls
                @else
                Covers {{ number_format((int) $estimate['covered_calls']) }} of {{ number_format($estimate['eligible']) }} calls
                @endif
            </small>
        </div>
    </div>
    @foreach($estimate['messages'] as $message)
    <p class="notice {{ $message['tone'] === 'warning' ? 'warning' : '' }}">{{ $message['text'] }}</p>
    @endforeach
    <p>Consent evidence is recorded for awareness and is not a dialing gate. DND/suppression is rechecked for every recipient immediately before sending.</p>
</section>