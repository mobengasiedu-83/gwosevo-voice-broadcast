@extends('foundation.layout')
@section('content')
<h2>Broadcast operations</h2>@if(auth()->user()->hasAnyPermission(['campaigns.manage','campaigns.approve','telephony.manage']))<p><a href="{{ route('interactive.index') }}">Interactive flow builder & caller ID settings</a></p>@endif<p><a href="{{ route('workspace') }}">Contacts and approvals</a></p>
@if(!config('voice_broadcast.enabled'))<p class="notice">Live dispatch is disabled. Verified capacity settings and PBX installation are required before enabling it.</p>@endif
@if($gateway)<section><h2>Gateway health</h2><p>AMI: {{ $gateway->healthy && $gateway->heartbeat_at && strtotime($gateway->heartbeat_at)>time()-15 ? 'Connected' : 'Offline or stale' }} · Last heartbeat (UTC): {{ $gateway->heartbeat_at ?? 'Never' }} · Channel snapshot: {{ $gateway->snapshot_at ?? 'Never' }}</p><p>Emergency stop: {{ $gateway->emergency_stop ? 'Active' : 'Clear' }}</p></section>@endif
@can('broadcast.emergency')<section><h2>Emergency broadcast controls</h2><p>Stop cancels broadcast campaigns and requests hangup only on their identified channels. Ordinary PBX calls continue.</p><form method="post" action="{{ route('broadcast.emergency') }}">@csrf<button name="action" value="stop">Stop all broadcasts</button><button name="action" value="reset">Reset global stop</button></form></section>@endcan
<section><h2>Campaign setup and controls</h2>
@foreach($campaigns as $campaign)<article><h3>{{ $campaign->name }} <small>{{ $campaign->status }}</small></h3>
<a href="{{ route('broadcast.report',$campaign) }}">View call report</a>
@if($campaign->status==='draft' && ($campaign->owner_id===auth()->id() || auth()->user()->isSuperAdministrator()) && auth()->user()->can('campaigns.manage'))
<details><summary>Configure broadcast</summary><form method="post" action="{{ route('broadcast.configure',$campaign) }}">@csrf
<label>Audio <select name="audio_id" required>@foreach($audio->where('status','approved')->whereNotNull('deployed_at') as $asset)<option value="{{ $asset->id }}">{{ $asset->name }} (#{{ $asset->id }})</option>@endforeach</select></label>
<label>Trunk <select name="trunk_id" required>@foreach($trunks->where('active',true) as $trunk)<option value="{{ $trunk->id }}">{{ $trunk->name }}</option>@endforeach</select></label>
<label>Caller ID <input name="caller_id" value="{{ $campaign->configuration['caller_id'] ?? config('broadcasting_discovery.caller_id') }}" placeholder="Use trunk default if empty" list="caller-ids-{{ $campaign->id }}"></label><datalist id="caller-ids-{{ $campaign->id }}">@foreach($trunks as $trunk)@foreach(array_unique(array_merge([$trunk->caller_id],$trunk->allowed_caller_ids ?? [])) as $callerId)<option value="{{ $callerId }}">{{ $trunk->name }}</option>@endforeach@endforeach</datalist><small>The selected caller ID must be approved for the selected trunk.</small>
@if($campaign->type==='interactive')<label>Approved IVR version <select name="ivr_flow_id" required>@foreach($flows as $flow)<option value="{{ $flow->id }}">{{ $flow->name }} · v{{ $flow->version }}</option>@endforeach</select></label>@endif
<label>Timezone <input name="timezone" value="{{ $campaign->configuration['timezone'] ?? 'Africa/Accra' }}" required></label>
<label>Schedule (selected timezone; optional) <input type="datetime-local" name="scheduled_at" value="{{ $campaign->configuration['scheduled_at'] ?? '' }}"></label>
<label>Maximum attempts <input type="number" name="max_attempts" value="{{ $campaign->configuration['max_attempts'] ?? 1 }}" min="1" max="5" required></label>
<label>Initial retry delay (seconds) <input type="number" name="retry_delay_seconds" value="{{ $campaign->configuration['retry_delay_seconds'] ?? 300 }}" min="60" max="86400" required></label>
<label>Priority weight <input type="number" name="priority" value="{{ $campaign->configuration['priority'] ?? 1 }}" min="1" max="10" required></label>
<label>Concurrent calls <input type="number" name="concurrency" min="1" max="100" value="1" required></label>
<label>Calls per second <input type="number" name="cps" min="0.001" max="100" step="0.001" value="1" required></label>
<label>Window start (selected timezone) <input type="time" name="window_start" value="09:00" required></label>
<label>Window end <input type="time" name="window_end" value="17:00" required></label>
<label>Contact IDs, separated by commas <input name="contact_ids" placeholder="1,2,3" required></label><button>Save configuration</button></form><p>Review the configuration, audio, audience and limits before submitting in the approval workspace. Contact IDs appear next to names there.</p></details>@endif
@if($campaign->configuration)<details><summary>Configuration for review</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere">{{ json_encode($campaign->configuration,JSON_PRETTY_PRINT) }}</pre></details>@endif
@can('broadcast.operate')<form method="post" action="{{ route('broadcast.control',$campaign) }}">@csrf
@if($campaign->status==='approved')<button name="action" value="start">Start approved campaign</button>@endif
@if($campaign->status==='running')<button name="action" value="pause">Pause new calls</button>@endif
@if($campaign->status==='paused')<button name="action" value="resume">Resume new calls</button>@endif
</form>@endcan
@can('broadcast.emergency')@if(in_array($campaign->status,['running','paused','scheduled']))<form method="post" action="{{ route('broadcast.control',$campaign) }}">@csrf<button name="action" value="emergency">Stop this broadcast</button></form>@endif@endcan
</article>@endforeach{{ $campaigns->links() }}</section>
@if(auth()->user()->hasAnyPermission(['audio.manage','audio.approve','telephony.view']))<section id="voice-library"><h2>Voice library</h2>
@can('audio.manage')<form method="post" enctype="multipart/form-data" action="{{ route('broadcast.audio.upload') }}">@csrf<label>Name <input name="name" required maxlength="255"></label><label>8 kHz mono 16-bit PCM WAV <input type="file" name="audio" accept=".wav" required></label><button>Validate and upload</button></form>@endcan
@foreach($audio as $asset)<article><h3>{{ $asset->name }} <small>{{ $asset->status }} · {{ $asset->duration }} seconds · {{ $asset->deployed_at ? 'Deployment recorded' : 'Not deployed' }}</small></h3><p><a href="{{ route('broadcast.audio.download',$asset) }}">Download private WAV</a></p><p style="overflow-wrap:anywhere">ID: {{ $asset->uuid }}<br>SHA-256: {{ $asset->checksum }}</p>
@can('audio.approve')@if($asset->status==='pending' && ($asset->uploaded_by!==auth()->id() || auth()->user()->isSuperAdministrator()))<form method="post" action="{{ route('broadcast.audio.approve',$asset) }}">@csrf<button>Approve reviewed audio</button></form>@endif@endcan
@can('telephony.manage')@if($asset->status==='approved')<details><summary>Record verified PBX deployment</summary><p>Install the WAV and AstDB allowlist entry using the deployment runbook, then record the actual PBX checksum and evidence.</p><form method="post" action="{{ route('broadcast.audio.deploy',$asset) }}">@csrf<label>PBX SHA-256 <input name="checksum" required pattern="[a-f0-9]{64}"></label><label>Evidence reference <input name="evidence" required maxlength="2000"></label><button>Record deployment</button></form></details>@endif@endcan
</article>@endforeach</section>@endif
@can('telephony.view')<section><h2>Trunk inventory</h2>@foreach($trunks as $trunk)<p>{{ $trunk->name }} · {{ $trunk->active ? 'Active' : 'Disabled' }} · Carrier {{ $trunk->max_channels }} / reserved {{ $trunk->reserved_channels }} / measured safe {{ $trunk->safe_channels }} channels · {{ $trunk->max_cps }} CPS</p>
@can('telephony.manage')@if($trunk->active)<form method="post" action="{{ route('broadcast.trunk.disable',$trunk) }}">@csrf<button>Disable new broadcasts on trunk</button></form>@endif@endcan
@endforeach
@can('telephony.manage')<details><summary>Add verified PJSIP trunk</summary><form method="post" action="{{ route('broadcast.trunk') }}">@csrf
@foreach(['name'=>'Exact PJSIP trunk name','caller_id'=>'Approved caller ID','max_channels'=>'Carrier channels','reserved_channels'=>'Reserved normal channels','safe_channels'=>'Measured safe broadcast channels','max_cps'=>'Carrier CPS','evidence'=>'Carrier and PBX evidence reference'] as $field=>$label)<label>{{ $label }} <input name="{{ $field }}" value="{{ old($field, $field === 'name' ? config('broadcasting_discovery.trunk') : ($field === 'caller_id' ? config('broadcasting_discovery.caller_id') : '')) }}" required></label>@endforeach
<label>Number format <select name="number_format">@foreach(['national'=>'National (0531005130)', 'international'=>'International (233531005130)', 'e164'=>'E.164 (+233531005130)'] as $format=>$label)<option value="{{ $format }}" @selected(old('number_format', config('broadcasting_discovery.number_format')) === $format)>{{ $label }}</option>@endforeach</select></label><button>Record verified trunk</button></form></details>@endcan
</section>@endcan
@endsection
