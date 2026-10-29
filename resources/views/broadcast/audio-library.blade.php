@extends('foundation.layout')
@section('title','Audio library')
@section('content')
@php($isClient=auth()->user()->clientMembership()->exists())
<div class="contact-heading">
    <div>
        <h1>Audio library</h1>
        <p>@if($isClient)Upload and preview your account's recordings. Internal reviewers approve or reject submissions. Only approved recordings can be used to launch campaigns.@else
            Upload a recording, listen to it, and approve or reject it. Approval makes linked draft campaigns Ready. {{ app(\App\Services\Broadcast\MediaDelivery::class)->remote() ? 'VitalPBX fetches approved recordings from this application through protected call URLs.' : 'Playback also requires the WAV to be installed on the PBX.' }}@endif</p>
    </div>@can('audio.manage')<a class="button" href="#upload-audio">＋ Upload new audio</a>@endcan
</div>
<div class="kpi-grid" id="audio-counts">@foreach(['processing'=>'Processing','pending'=>'Awaiting review','approved'=>'Approved','rejected'=>'Rejected','conversion_failed'=>'Conversion failed'] as $state=>$label)<div class="kpi">
        <div>{{ $label }}</div><strong>{{ number_format($counts[$state]??0) }}</strong>
    </div>@endforeach</div>
<form method="get" class="campaign-filters"><label>Search recordings<input name="q" maxlength="100" value="{{ $filters['q']??'' }}"></label><label>Status<select name="status">
            <option value="">All statuses</option>@foreach(['processing','pending','approved','rejected','conversion_failed'] as $state)<option value="{{ $state }}" @selected(($filters['status']??'')===$state)>{{ ucfirst(str_replace('_',' ',$state)) }}</option>@endforeach
        </select></label><button>Search</button><a class="button secondary" href="{{ route('audio.index') }}" aria-label="Reset filters">Reset</a></form>
<nav class="module-tabs" aria-label="Audio status">@foreach([''=>'All audio','processing'=>'Processing','conversion_failed'=>'Conversion failed','pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected'] as $value=>$label)<a href="{{ route('audio.index',array_filter(['status'=>$value,'q'=>$filters['q']??null],fn($v)=>$v!==null&&$v!=='')) }}" @if(request('status','')===$value) aria-current="page" @endif>{{ $label }}</a>@endforeach</nav>
<section>
    <div class="table-scroll audio-library-table">
        <table>
            <thead>
                <tr>
                    <th>Recording</th>
                    <th>Status</th>
                    <th>Preview</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>@forelse($audio as $asset)<tr id="audio-{{ $asset->id }}" @if($asset->status==='processing') data-audio-processing @endif><td><strong>{{ $asset->name }}</strong>@include('partials.client-identity',['record'=>$asset])<small class="block">@if($asset->duration!==null){{ number_format($asset->duration,1) }} seconds · @endif {{ $asset->created_at?->format('d M Y') }}</small>@if($asset->original_name)<small class="block">{{ $asset->original_name }}</small>@endif @if($asset->processing_error)<small class="block danger-text">{{ $asset->processing_error }}</small>@endif @if($asset->rejection_reason)<small class="block danger-text">{{ $asset->rejection_reason }}</small>@endif</td>
                    <td><span class="pill {{ $asset->status==='approved'?'good':(in_array($asset->status,['rejected','conversion_failed'])?'warn':'neutral') }}">{{ ucfirst(str_replace('_',' ',$asset->status)) }}</span><small class="block">{{ app(\App\Services\Broadcast\MediaDelivery::class)->remote() ? 'Application-hosted playback' : ($asset->deployed_at?'PBX installed':'PBX installation not recorded') }}</small></td>
                    <td>@if($asset->storage_key && in_array($asset->status,['pending','approved','rejected']))<audio controls preload="none" aria-label="Preview {{ $asset->name }}" src="{{ route('audio.preview',$asset) }}">Download the recording to listen.</audio>@elseif($asset->status==='processing')<span role="status">Converting recording…</span>@else<span>Preview unavailable</span>@endif</td>
                    <td>
                        <div class="audio-actions">@if($asset->storage_key && in_array($asset->status,['pending','approved','rejected']))<a class="button secondary" href="{{ route('broadcast.audio.download',$asset) }}">Download</a>@endif
                            @if($asset->status==='conversion_failed')@can('audio.manage')<a href="#upload-audio">Upload a replacement</a>@endcan
                            @endif
                            @can('audio.approve')@if($asset->status==='pending'&&($asset->uploaded_by!==auth()->id()||auth()->user()->isSuperAdministrator()))<form method="post" action="{{ route('broadcast.audio.approve',$asset) }}">@csrf<button>Approve audio</button></form>
                            <details>
                                <summary>Reject</summary>
                                <form method="post" action="{{ route('broadcast.audio.reject',$asset) }}">@csrf<label>Reason<input name="reason" required maxlength="2000"></label><button class="destructive">Reject audio</button></form>
                            </details>@endif
                            @endcan
                            @can('audio.manage')@if($asset->status==='rejected'&&($asset->uploaded_by===auth()->id()||auth()->user()->isSuperAdministrator()||app(\App\Services\Clients\Tenancy::class)->sameAccount(auth()->user(),$asset)))<form method="post" action="{{ route('broadcast.audio.resubmit',$asset) }}">@csrf<button>Resubmit for review</button></form>@endif
                            @endcan
                            @can('telephony.manage')@if($asset->status==='approved'&&!$asset->deployed_at&&!app(\App\Services\Broadcast\MediaDelivery::class)->remote())<details>
                                <summary>PBX installation</summary>
                                <p>Download this WAV, then copy it to the PBX Asterisk sounds directory as <code>gwosevo/{{ $asset->uuid }}.wav</code>. The usual directory is <code>/var/lib/asterisk/sounds</code>; confirm the PBX’s configured ASTDATADIR. Ensure Asterisk can read the file. On the PBX, run <code>sha256sum &lt;sounds-directory&gt;/gwosevo/{{ $asset->uuid }}.wav</code>, compare it with the expected checksum below, then record the result and installation path. AMI access alone cannot upload the file.</p>
                                <p><small class="mono">{{ $asset->uuid }}<br>{{ $asset->checksum }}</small></p>
                                <form method="post" action="{{ route('broadcast.audio.deploy',$asset) }}">@csrf<label>Installed file SHA-256<input name="checksum" required pattern="[a-f0-9]{64}"></label><label>Installation evidence<input name="evidence" required maxlength="2000"></label><button>Record installation</button></form>
                            </details>@endif
                            @endcan
                        </div>
                    </td>
                </tr>@empty<tr>
                    <td colspan="4">No audio matches this status.</td>
                </tr>@endforelse</tbody>
        </table>
    </div>{{ $audio->links() }}
</section>
@can('audio.manage')<section id="upload-audio">
    <h2>Create / upload audio</h2>
    <form method="post" enctype="multipart/form-data" action="{{ route('broadcast.audio.upload') }}">@csrf<label>Recording name<input name="name" value="{{ old('name') }}" required maxlength="255"></label><label>Audio file<input type="file" name="audio" accept=".wav,.mp3,.m4a,.aac,.ogg,.oga,.opus,.flac,.aif,.aiff,.wma,.webm" required></label><button>Upload audio</button></form>
    <p>Upload WAV, MP3, M4A, AAC, OGG, Opus, FLAC, AIFF, WMA or audio-only WebM, up to {{ number_format(config('voice_broadcast.max_audio_bytes')/1000000,1) }} MB / {{ number_format(config('voice_broadcast.max_audio_seconds')/60,1) }} minutes. Other formats and stereo recordings are automatically converted to telephone-quality 8 kHz mono, 16-bit PCM WAV. Processing recordings become Pending after conversion and must be reviewed before use. Conversion cannot restore detail missing from the original recording. <a href="{{ request()->fullUrl() }}">Refresh status</a>. @if($isClient)Rejected recordings show the review reason. Resubmit an unchanged recording for reconsideration, or upload a corrected recording as a new submission.@else Super Administrators may approve their own recordings.@endif</p>
</section>@endcan
<script>
    (() => {
        let attempts = 0;
        const refresh = async () => {
            const rows = [...document.querySelectorAll('[data-audio-processing]')];
            if (!rows.length || ++attempts > 60) return;
            try {
                const response = await fetch(window.location.href, {
                    headers: {
                        'Accept': 'text/html'
                    },
                    cache: 'no-store'
                });
                if (!response.ok || response.redirected) return;
                const updated = new DOMParser().parseFromString(await response.text(), 'text/html');
                for (const row of rows) {
                    const next = updated.getElementById(row.id);
                    if (next) row.replaceWith(next);
                    else row.remove();
                }
                const counts = updated.getElementById('audio-counts');
                if (counts) document.getElementById('audio-counts').replaceWith(counts);
            } catch (error) {
                /* The refresh link remains available after network errors. */ }
            window.setTimeout(refresh, 5000);
        };
        window.setTimeout(refresh, 5000);
    })();
</script>
@endsection