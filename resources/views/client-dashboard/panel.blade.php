<div class="client-welcome">
<div class="client-welcome-copy"><span class="client-eyebrow">YOUR VOICE. MORE CONNECTIONS.</span><h1>{{ $client->name }}</h1><p>Your account dashboard · {{ ucfirst($client->status) }} · {{ ucfirst($membership->role) }}</p><p class="client-welcome-message">Bring your audience closer, one call at a time.</p>@can('campaigns.manage')<a class="button" href="{{ route('campaigns.create') }}">Create campaign <span aria-hidden="true">↗</span></a>@endcan</div>
<img class="client-welcome-art" src="{{ asset('images/client-broadcast.svg') }}" alt="" width="480" height="280" fetchpriority="high">
</div>
<div class="kpi-grid client-summary-cards">
@foreach(['campaigns'=>'Campaigns','contacts'=>'Contacts','groups'=>'Contact groups','recordings'=>'Recordings'] as $key=>$label)
<div class="kpi"><div>{{ $label }}</div><strong>{{ number_format($totals[$key]) }}</strong></div>
@endforeach
<div class="kpi"><div>Total call time left</div>
@if($wallet && $wallet_affordability['remaining_minutes'] !== null)
<strong>{{ number_format($wallet_affordability['remaining_minutes']) }} minutes</strong>
<small>At {{ \App\Services\Billing\Wallet::amount($wallet_tariff['rate_minor_per_minute'], $wallet_tariff['minor_unit_scale']) }} {{ $wallet_tariff['currency'] }} / billed minute</small>
<details><summary>How time is calculated</summary><p>Based on available credit. Excludes reserved credit. Each answered call is rounded up to a whole minute; actual talk time may be lower. New calls also require enough credit for a full call hold.</p></details>
@elseif($wallet && $wallet_affordability['reason'] === null)
<strong>Unlimited</strong><small>Unlimited at your current zero rate</small>
@else
<strong>Unavailable</strong><small>Call time unavailable until a wallet and a matching call rate are configured.</small>
@endif
</div></div>
@if(!$totals['contacts'] || !$totals['recordings'] || !$totals['campaigns'])<section class="client-get-started"><h2>Get started</h2><p>Prepare your audience and recording, then create your first campaign.</p><div class="client-setup-grid">
@foreach([
['contacts.groups','Create a contact group','Organize your audience.','audience','contacts.view'],
['contacts.imports','Import contacts','Bring your contact list in.','clients','contacts.manage'],
['audio.index','Upload a recording','Give your message a voice.','voice','audio.manage'],
['campaigns.create','Create campaign','Put your message to work.','campaigns','campaigns.manage'],
] as $step=>[$destination,$label,$description,$icon,$permission])
@can($permission)
<a class="client-setup-card" href="{{ route($destination) }}">
<span class="client-setup-top"><span class="client-setup-icon">@include('dashboard.navigation-icon',['icon'=>$icon])</span><span class="client-setup-number">STEP {{ $step+1 }}</span></span>
<strong>{{ $label }}</strong><span class="client-setup-description">{{ $description }}</span><span class="client-setup-arrow" aria-hidden="true">↗</span>
</a>
@endcan
@endforeach
</div></section>@endif
<section><h2>Call results</h2><form method="get" action="{{ route('dashboard.overview') }}" class="campaign-filters"><label>Date<input type="date" name="date" required value="{{ $filters['date'] }}"></label><label>Timezone<input name="timezone" required value="{{ $filters['timezone'] }}"></label><label>Campaign<select name="campaign_id"><option value="">All campaigns</option>@foreach($campaign_options as $option)<option value="{{ $option->id }}" @selected(($filters['campaign_id']??'')==$option->id)>{{ $option->name }}</option>@endforeach</select></label><button>Refresh results</button></form><p>Results for {{ $filters['date'] }} in {{ $filters['timezone'] }}. Updated {{ $generated_at }}.</p>
<div class="kpi-grid">@foreach(['calls_today'=>'Calls sent','answered'=>'Answered completed calls','completed'=>'Playback completed','active_calls'=>'Calls in progress','uncertain'=>'Calls awaiting confirmation'] as $key=>$label)<div class="kpi"><div>{{ $label }}</div><strong>{{ number_format($$key) }}</strong></div>@endforeach<div class="kpi"><div>Answer rate</div><strong>{{ $answer_rate===null?'—':$answer_rate.'%' }}</strong></div></div><p>Answer rate uses completed eligible attempts sent on the selected date. In-progress and awaiting-confirmation counts show current calls. An answered call does not confirm completed playback.</p>
@if($uncertain)<p class="notice">Some call outcomes await confirmation. Review the campaign report before retrying.</p>@endif
<details><summary>Calls sent by hour</summary><div class="table-scroll"><table><thead><tr><th>Hour ({{ $filters['timezone'] }})</th><th>Calls sent</th></tr></thead><tbody>@foreach($hours as $hour)<tr><td>{{ $hour['hour'] }}</td><td>{{ $hour['count'] }}</td></tr>@endforeach</tbody></table></div></details></section>
<div class="client-dashboard-panels">
<section><h2>Campaign status</h2>@forelse($campaign_statuses as $state=>$total)<p>{{ ucfirst(str_replace('_',' ',$state)) }}: {{ number_format($total) }}</p>@empty<p>No campaigns yet.</p>@endforelse<a href="{{ route('campaigns.index') }}">View campaigns →</a></section>
<section><h2>Recording status</h2>@forelse($recording_statuses as $state=>$total)<p>{{ ucfirst($state) }}: {{ number_format($total) }}</p>@empty<p>No recordings yet.</p>@endforelse<a href="{{ route('audio.index') }}">Manage recordings →</a></section>
<section><h2>Recent campaigns</h2><div class="table-scroll"><table><thead><tr><th>Campaign</th><th>Status</th><th>Scheduled</th><th>Report</th></tr></thead><tbody>@forelse($recent_campaigns as $campaign)<tr><td><a href="{{ route('campaigns.show',$campaign->id) }}">{{ $campaign->name }}</a></td><td>{{ str_replace('_',' ',$campaign->status) }}</td><td>{{ $campaign->scheduled_at?->timezone($filters['timezone'])->format('d M Y H:i')??'—' }}</td><td><a href="{{ route('broadcast.report',$campaign->id) }}">View report</a></td></tr>@empty<tr><td colspan="4">No campaigns yet. Create a campaign to start.</td></tr>@endforelse</tbody></table></div></section>
<section><h2>Recent imports</h2>@forelse($recent_imports as $import)<p><a href="{{ route('contacts.import',$import->id) }}">Import #{{ $import->id }}</a> · {{ $import->status }}</p>@empty<p>No imports yet.</p>@endforelse</section>
<section><h2>Account & security</h2><p>Manage your verified sign-in contacts and account security.</p><a href="{{ route('account.security') }}">Open profile & security →</a></section>
<section><h2>Recent call outcomes</h2><p>Latest completed attempts across your account. Detailed answer and playback evidence is available in each campaign report.</p><div class="table-scroll"><table><thead><tr><th>Campaign</th><th>Outcome</th><th>Ended ({{ $filters['timezone'] }})</th></tr></thead><tbody>@forelse($outcomes as $outcome)<tr><td><a href="{{ route('broadcast.report',$outcome->campaign_id) }}">{{ $outcome->campaign_name }}</a></td><td>{{ str_replace('_',' ',$outcome->outcome??'Awaiting confirmation') }}</td><td>{{ \Carbon\CarbonImmutable::parse($outcome->ended_at,'UTC')->timezone($filters['timezone'])->format('d M Y H:i') }}</td></tr>@empty<tr><td colspan="3">No completed call outcomes yet.</td></tr>@endforelse</tbody></table></div></section>
<section><h2>Calling credit</h2>@if($wallet)<p>Available credit: {{ \App\Services\Billing\Wallet::amount($wallet->available_minor,$wallet->minor_unit_scale) }} {{ $wallet->currency }} · Reserved credit: {{ \App\Services\Billing\Wallet::amount($wallet->reserved_minor,$wallet->minor_unit_scale) }} {{ $wallet->currency }} · Calls in flight: {{ $wallet_summary['held_count'] }}</p>@if($wallet_affordability && $wallet_affordability['low'])<p class="notice">{{ \App\Services\Billing\Wallet::clientMessage($wallet_affordability['reason']) }}</p>@endif<p>Charges are posted once per answered call; unanswered calls release their hold. Add credit online from the calling-credit page when payments are enabled, or contact Finance for an approved top-up.</p><p><a href="{{ route('wallet.index') }}">View balance, holds and transactions →</a></p>@if($wallet_entries->isNotEmpty())<div class="table-scroll"><table><thead><tr><th>Date (UTC)</th><th>Activity</th><th>Available</th><th>Reserved</th></tr></thead><tbody>@foreach($wallet_entries as $entry)<tr><td>{{ $entry->created_at?->format('d M Y H:i') }}</td><td>{{ ucfirst(str_replace('_',' ',$entry->type)) }}</td><td>{{ \App\Services\Billing\Wallet::amount($entry->available_after_minor,$entry->minor_unit_scale) }}</td><td>{{ \App\Services\Billing\Wallet::amount($entry->reserved_after_minor,$entry->minor_unit_scale) }}</td></tr>@endforeach</tbody></table></div>@endif@else<p>Available credit: Not configured · Reserved credit: Not configured</p><p>Wallet balances and top-ups will be available when billing is configured.</p>@endif</section>
<section><h2>Your recent activity</h2>@forelse($activity as $event)<p>{{ ucfirst(str_replace(['.','_'],' ',$event->action)) }} · {{ $event->created_at?->timezone($filters['timezone'])->format('d M Y H:i') }}</p>@empty<p>No recent activity.</p>@endforelse</section>
</div>
