@extends('clients.layout')
@section('heading', $campaign->name)
@section('description', 'Review your campaign and monitor call outcomes. Completed means execution ended; check playback results for delivery evidence.')
@section('portal-content')
<div class="campaign-review">
<section class="panel"><h2>Campaign review</h2><p><span class="pill">{{ ucfirst($campaign->status) }}</span> · {{ $campaign->purpose }}</p>
<p>Recording: {{ $selectedAudio?->name ?? 'Unavailable' }} · {{ ucfirst($selectedAudio?->status ?? 'unavailable') }}</p>
@if($selectedAudio)<audio controls preload="none" src="{{ route('client.audio.preview', $selectedAudio) }}">Your browser does not support audio preview.</audio>@endif
<p>Group: {{ $group?->name ?? 'Test recipient' }} · Selected audience: {{ count($campaign->configuration['contact_ids'] ?? []) }}</p>
<p>Eligible: {{ $eligibility['eligible'] }} · DND excluded: {{ $eligibility['suppressed'] }} · Changed or unavailable: {{ $eligibility['contact_changed'] }}</p>
<p>Schedule: {{ $campaign->configuration['scheduled_at'] ?? 'Start on confirmation' }} · {{ $campaign->timezone }}</p><p>Calling window: {{ $campaign->configuration['window_start'] ?? '—' }}–{{ $campaign->configuration['window_end'] ?? '—' }}</p>
@if($billingPreview)
<p>{{ ($billingPreview['duration_basis'] ?? 'recording') === 'answered' ? 'Credit reserved per attempt' : 'Price per answered call' }}: {{ $billingPreview['currency'] }} {{ \App\Services\Billing\Ledger::format($billingPreview['call_charge_minor'], $billingPreview['minor_digits']) }}.</p>
<p>{{ \App\Services\Billing\Policy::description($billingPreview) }} Each execution keeps its launch policy.</p>
<p>Audience {{ ($billingPreview['duration_basis'] ?? 'recording') === 'answered' ? 'reservation estimate' : 'estimate if all recipients answer once' }}: {{ $billingPreview['currency'] }} {{ \App\Services\Billing\Ledger::format($billingPreview['call_charge_minor'] * count($campaign->configuration['contact_ids'] ?? []), $billingPreview['minor_digits']) }}. Actual charges depend on the policy and call outcomes; retries may increase the total.</p>
@endif
@if($billingIssue)<div class="notice">{{ $billingIssue }} <a href="{{ route('client.wallet') }}">Open wallet</a></div>@endif
<p>Technical launch checks: {{ $technicalReady ? 'Passed current configuration checks. Dispatch checks still apply.' : 'Action required. Contact support to resolve recording, connection or capacity requirements.' }}</p>
@if(!$executionEnabled)<div class="notice">Client execution is disabled pending controlled acceptance. Contact support.</div>@endif
@if($mayManage && auth()->user()->clientCan('campaigns.write') && in_array($campaign->status, ['draft','ready']))<a class="button" href="{{ route('client.campaigns.edit', $campaign) }}">Edit campaign</a>@endif
</section>
@if($mayManage)
<section class="panel"><h2>Campaign controls</h2><form method="post" action="{{ route('client.campaigns.control', $campaign) }}">@csrf
<div class="form-field"><label for="reason">Reason for action</label><input id="reason" name="reason" required maxlength="1000"></div>
@if(in_array($campaign->status, ['ready','approved']))<button class="button" name="action" value="start" @disabled(!$executionEnabled || !$technicalReady || $billingIssue || !$eligibility['eligible'])>Start / arm schedule</button>@endif
@if(in_array($campaign->status, ['running','scheduled']))<button class="button" name="action" value="pause">Pause</button>@endif
@if($campaign->status === 'paused')<button class="button" name="action" value="resume" @disabled(!$executionEnabled)>Resume</button>@endif
@if(in_array($campaign->status, ['draft','ready','approved','scheduled','running','paused']))<button class="button" name="action" value="cancel">Cancel campaign</button>@endif
</form><p>Pause stops new dialing. Cancellation and call termination require gateway processing.</p></section>
@if(in_array($campaign->status, ['draft','ready']) && empty($campaign->configuration['client_test']))
<section class="panel"><h2>Test approved audio</h2><p>A test creates a separate run for one administrator-authorized recipient using the same launch, DND and capacity checks. Confirm full audible playback with the recipient.</p>
@if($testContacts->isNotEmpty())<form method="post" action="{{ route('client.campaigns.test', $campaign) }}">@csrf<div class="form-field"><label for="contact_id">Authorized test recipient</label><select id="contact_id" name="contact_id" required>@foreach($testContacts as $contact)<option value="{{ $contact->id }}">{{ $contact->name }} · {{ $contact->phone_e164 }}</option>@endforeach</select></div><button class="button" @disabled(!$executionEnabled || !$technicalReady)>Queue one test call</button></form>@else<p>No test recipient has been authorized. Contact support.</p>@endif
@foreach($tests as $test)<p><a href="{{ route('client.campaigns.show', $test) }}">{{ $test->name }}</a> · {{ ucfirst($test->status) }}</p>@endforeach
</section>
@endif
@endif
<section class="panel"><h2>Call outcomes</h2><p>Attempts: {{ $totals['attempts'] }} · Answered: {{ $totals['answered'] }} · Playback completed: {{ $totals['playback'] }}</p><p>An answer alone does not confirm audio playback. Playback completion reports the PBX operation; confirm audibility during testing.</p>
<div class="table-scroll"><table><thead><tr><th>Attempt</th><th>State / outcome</th><th>Answered (UTC)</th><th>Playback completed (UTC)</th></tr></thead><tbody>@forelse($attempts as $attempt)<tr><td>{{ $attempt->id }} · Try {{ $attempt->attempt_no }}</td><td>{{ $attempt->state }} / {{ $attempt->outcome ?? 'Pending' }}</td><td>{{ $attempt->answered_at?->format('d M Y H:i:s') ?? '—' }}</td><td>{{ $attempt->playback_completed_at?->format('d M Y H:i:s') ?? '—' }}</td></tr>@empty<tr><td colspan="4">No call attempts yet.</td></tr>@endforelse</tbody></table></div>{{ $attempts->links('clients.pagination') }}</section>
<a href="{{ route('client.campaigns') }}">Back to campaigns</a>
</div>
@endsection
