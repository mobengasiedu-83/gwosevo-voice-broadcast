@extends('foundation.layout')
@section('title', $telephonyPage ? 'Telephony' : 'Voice & IVR')
@section('content')
<h2>{{ $telephonyPage ? 'Telephony' : 'Voice & IVR' }}</h2>
<nav class="workspace-actions" aria-label="Voice and telephony shortcuts">
@can('broadcast.operate')<a class="button secondary" href="{{ route('broadcast.index') }}">Campaign operations</a>@endcan
@if($telephonyPage)
<a class="button secondary" href="{{ route('interactive.index') }}">Voice & IVR</a>
@else
@can('telephony.manage')<a class="button secondary" href="{{ route('telephony.index') }}">Telephony & caller IDs</a>@endcan
@endif
</nav>
@if($telephonyPage)
@can('telephony.manage')<section><h2>Outbound caller IDs</h2><p>Set the default caller ID and additional carrier-approved numbers for each trunk. Existing campaign approvals keep their selected number; removing that number blocks new calls.</p>
@foreach($trunks as $trunk)<h3>{{ $trunk->name }}</h3><form method="post" action="{{ route('interactive.caller_ids',$trunk) }}">@csrf<label>Default caller ID <input name="caller_id" value="{{ $trunk->caller_id }}" required></label><label>Additional approved numbers, comma-separated <input name="allowed_caller_ids" value="{{ implode(', ', $trunk->allowed_caller_ids ?? []) }}"></label><label>Carrier approval evidence <input name="evidence" required maxlength="2000"></label><button>Save caller IDs</button></form><details><summary>Verify or update capacity for {{ $trunk->name }}</summary><form method="post" action="{{ route('interactive.capacity',$trunk) }}">@csrf
@foreach(['max_channels'=>'Carrier channel limit','reserved_channels'=>'Reserved normal channels','safe_channels'=>'Measured safe broadcast channels','max_cps'=>'Carrier calls per second'] as $field=>$label)<label>{{ $label }} <input name="{{ $field }}" value="{{ $trunk->$field }}" required></label>@endforeach
<label>Dial format <select name="number_format">@foreach(['national','international','e164'] as $format)<option @selected($trunk->number_format===$format)>{{ $format }}</option>@endforeach</select></label><label>Verification evidence <input name="evidence" required maxlength="2000"></label><button>Save verified capacity</button></form></details>@endforeach</section>
<section><h2>Allowlisted agent destinations</h2><p>Only existing internal numeric queue IDs or PJSIP extensions are supported. The generated dialplan checks availability before attempting transfer.</p>
@foreach($routes as $route)<p>{{ $route->name }} — {{ $route->type }} {{ $route->destination }} · {{ $route->active ? 'Active' : 'Disabled' }}</p>@if($route->active)<form method="post" action="{{ route('interactive.routes.disable',$route) }}">@csrf<button>Disable route</button></form>@endif@endforeach
<form method="post" action="{{ route('interactive.routes') }}">@csrf<label>Name <input name="name" required></label><label>Type <select name="type"><option value="queue">Queue</option><option value="extension">Extension</option></select></label><label>Internal number <input name="destination" pattern="[0-9]{1,12}" required></label><label>Verified routing evidence <input name="evidence" required maxlength="2000"></label><button>Add route</button></form></section>@endcan
@endif
@if(! $telephonyPage)
@can('campaigns.manage')@include('broadcast.visual-builder')@endcan
<section><h2>IVR versions</h2>@foreach($flows as $flow)<article><h3>#{{ $flow->id }} {{ $flow->name }} · Version {{ $flow->version }}</h3>@include('partials.client-identity',['record'=>$flow])<p>{{ $flow->status }} · {{ $flow->deployed_at ? 'Deployment recorded' : 'Not deployed' }}</p>@unless(auth()->user()->clientMembership()->exists())<details><summary>Review graph</summary><pre style="white-space:pre-wrap;overflow-wrap:anywhere">{{ json_encode($flow->graph,JSON_PRETTY_PRINT) }}</pre></details>@endunless
@can('campaigns.manage')@if(($flow->owner_id===auth()->id()||auth()->user()->isSuperAdministrator()||app(\App\Services\Clients\Tenancy::class)->sameAccount(auth()->user(),$flow)))<button type="button" class="clone-flow" data-flow="{{ json_encode($flow->graph) }}" data-source="{{ $flow->id }}" data-name="{{ $flow->name }}">Clone into builder</button>@endif@endcan
@can('campaigns.approve')@if($flow->status==='draft' && ($flow->owner_id!==auth()->id()||auth()->user()->isSuperAdministrator()))<form method="post" action="{{ route('interactive.approve',$flow) }}">@csrf<button>Approve reviewed flow</button></form>@endif@endcan
@can('telephony.manage')@if($flow->status==='approved')<p><a href="{{ route('interactive.download',$flow) }}">Download generated PBX dialplan</a></p><form method="post" action="{{ route('interactive.deploy',$flow) }}">@csrf<label>Installed dialplan SHA-256 <input name="checksum" pattern="[a-f0-9]{64}" required></label><label>Deployment and test evidence <input name="evidence" required maxlength="2000"></label><button>Record deployment</button></form>@endif@endcan
</article>@endforeach{{ $flows->links() }}</section>
<script src="{{ asset('js/ivr-builder.js') }}" defer></script>
@endif
@endsection
