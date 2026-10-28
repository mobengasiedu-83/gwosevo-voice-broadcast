@if($pricingSnapshot)
<section><h2>Saved campaign pricing</h2><p>This approved pricing is fixed for every contact in this campaign. Future rate-plan changes do not alter it. Actual call time is recorded separately.</p>
@php($p = $pricingSnapshot)
@php($fmt = fn ($v) => $v === null ? '—' : ($p['currency'] ?? '').' '.\App\Services\Billing\CampaignEstimate::money($v, $p['minor_unit_scale']))
<dl class="identity">
<dt>Audio ID</dt><dd>{{ $p['audio_id'] }}</dd>
<dt>Audio Duration</dt><dd>{{ $p['audio_duration_seconds'] }} seconds</dd>
<dt>Billable Duration</dt><dd>{{ $p['billable_minutes'] }} minutes</dd>
<dt>Rate Per Minute</dt><dd>{{ $fmt($p['rate_minor_per_minute']) }}</dd>
<dt>Calculated Rate Per Contact / MSISDN</dt><dd>{{ $fmt($p['rate_per_contact_minor']) }}</dd>
<dt>Total Contacts</dt><dd>{{ number_format($p['total_contact_count'] ?? $p['contact_count']) }}</dd>
<dt>Eligible Contacts</dt><dd>{{ number_format($p['contact_count']) }}</dd>
<dt>Estimated Campaign Cost</dt><dd>{{ $fmt($p['estimated_campaign_amount_minor']) }}</dd>
<dt>Rate Plan</dt><dd>{{ $p['rate_plan_id'] ?? 'Unassigned' }} · version {{ $p['rate_plan_version'] ?? '—' }}</dd>
</dl></section>
@endif
