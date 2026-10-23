@extends('foundation.layout')
@section('title', 'Audio library')
@section('content')
@php($statusLabels = ['approved' => 'Approved', 'pending' => 'Pending review', 'rejected' => 'Rejected'])
<div class="page-heading">
    <div><div class="eyebrow">MEDIA ASSETS</div><h1>Audio library</h1><p>Upload, preview, review, and deploy approved broadcast audio.</p></div>
</div>
@can('audio.manage')
<section class="panel audio-upload-panel" aria-labelledby="new-audio-heading">
    <div class="panel-heading"><div><h2 id="new-audio-heading">Upload audio</h2><p>Only 8 kHz mono, 16-bit PCM WAV files are accepted.</p></div></div>
    <form class="audio-upload-form" method="post" enctype="multipart/form-data" action="{{ route('broadcast.audio.upload') }}">@csrf
        <label>Audio name<input name="name" value="{{ old('name') }}" required maxlength="255" placeholder="e.g. Appointment reminder"></label>
        <label>WAV file<input type="file" name="audio" accept="audio/wav,.wav" required></label>
        <button class="button" type="submit">Validate and upload</button>
    </form>
</section>
@endcan
<section class="panel" aria-labelledby="library-heading">
    <div class="panel-heading"><div><h2 id="library-heading">Audio files</h2><p>{{ $audio->total() }} {{ Str::plural('audio file', $audio->total()) }} available to your role.</p></div></div>
    @if($audio->isEmpty())
        <div class="empty-state"><strong>No audio files yet.</strong><p>Upload a validated WAV file to begin the review process.</p></div>
    @else
        <div class="audio-table-wrap"><table class="audio-table"><thead><tr><th scope="col">Audio</th><th scope="col">Status</th><th scope="col">Duration</th><th scope="col">Uploaded</th><th scope="col">Preview</th><th scope="col">Actions</th></tr></thead><tbody>
        @foreach($audio as $asset)
            @php($status = strtolower($asset->status ?? 'pending'))
            <tr>
                <td><strong>{{ $asset->name }}</strong><small class="audio-meta">{{ $asset->deployed_at ? 'PBX deployment recorded' : 'Not deployed' }}</small></td>
                <td><span class="pill {{ $status === 'approved' ? 'good' : ($status === 'rejected' ? 'danger' : 'warn') }}">{{ $statusLabels[$status] ?? ucfirst($status) }}</span></td>
                <td>{{ number_format((float) $asset->duration, 1) }}s</td>
                <td>{{ optional($asset->created_at)->format('M j, Y') ?? '—' }}</td>
                <td><audio controls preload="none" aria-label="Preview {{ $asset->name }}"><source src="{{ route('audio.preview', $asset) }}" type="audio/wav">Your browser does not support audio preview.</audio></td>
                <td><div class="audio-actions"><a class="button secondary" href="{{ route('broadcast.audio.download', $asset) }}">Download</a>
                @can('audio.approve')@if($status === 'pending' && ($asset->uploaded_by !== auth()->id() || auth()->user()->isSuperAdministrator()))<form method="post" action="{{ route('broadcast.audio.approve', $asset) }}">@csrf<button class="button" type="submit">Approve</button></form>@endif@endcan
                @can('telephony.manage')@if($status === 'approved' && ! $asset->deployed_at)<details class="audio-deploy"><summary>Record deployment</summary><form method="post" action="{{ route('broadcast.audio.deploy', $asset) }}">@csrf<label>PBX SHA-256<input name="checksum" required pattern="[a-f0-9]{64}" aria-label="PBX SHA-256 for {{ $asset->name }}"></label><label>Evidence reference<input name="evidence" required maxlength="2000" aria-label="Deployment evidence for {{ $asset->name }}"></label><button class="button" type="submit">Save deployment</button></form></details>@endif@endcan
                </div></td>
            </tr>
        @endforeach
        </tbody></table></div>
        <div class="pagination-wrap">{{ $audio->links() }}</div>
    @endif
</section>
@endsection