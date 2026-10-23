@extends('foundation.layout')
@section('content')
<h2>{{ $campaign->name }} — call report</h2><p><a href="{{ route('broadcast.index') }}">Broadcast operations</a> · Campaign: {{ $campaign->status }} · Refresh to update</p>
<section aria-label="Execution and playback summary">
<h2>Execution and playback summary</h2>
<p>Execution status: <strong>{{ ucfirst($campaign->status) }}</strong>. This describes processing, not successful delivery.</p>
@if($campaign->status==='completed')
@if($playbackRecipients===0)<p class="notice warning"><strong>Execution ended — no playback completion recorded.</strong> Review the attempt outcomes below. Completed does not mean recipients heard the recording.</p>
@elseif($audienceTotal>0 && $playbackRecipients===$audienceTotal)<p class="notice"><strong>Execution ended — playback completion recorded for all recipients.</strong></p>
@else<p class="notice warning"><strong>Execution ended — partial playback completion.</strong> Some recipients have no recorded playback completion.</p>@endif
@else<p>Playback results so far are shown below; the execution status is separate.</p>@endif
<p><strong>Recipients with completed playback: {{ $playbackRecipients }} of {{ $audienceTotal }}</strong></p>
<p>Recipients without playback confirmation: {{ max(0, $audienceTotal-$playbackRecipients) }}. These may include pending, excluded, failed or uncertain calls. Retries count once per recipient in this summary. Asterisk playback completion does not independently prove audible receipt.</p>
</section>
<section><h2>Outcomes</h2><p>Terminal attempts: {{ $terminal }} · Active or uncertain: {{ $active }}</p><p>Answer rate: {{ $terminal ? round(100*$answered/$terminal,1).'%' : 'No terminal attempts' }} · Playback completion rate: {{ $started ? round(100*$completed/$started,1).'%' : 'No playback starts' }}</p><p>Playback started: {{ $started }} · Playback completed: {{ $completed }}</p><p>Uncertain outcomes remain incomplete until reconciled. An answered call does not prove the recording was fully played.</p><table><thead><tr><th>Outcome</th><th>Attempts</th></tr></thead><tbody>@foreach($counts as $outcome=>$total)<tr><td>{{ $outcome ?: 'In progress' }}</td><td>{{ $total }}</td></tr>@endforeach</tbody></table></section>
<section><h2>Interactive responses</h2><p>Opt-outs: {{ $optouts }} · Transfer attempts: {{ $transfers }} · Confirmed transfers: {{ $transfer_success }}</p><table><thead><tr><th>Node</th><th>Digit</th><th>Responses</th></tr></thead><tbody>@foreach($responses as $response)<tr><td>{{ $response->node_id }}</td><td>{{ $response->digit }}</td><td>{{ $response->total }}</td></tr>@endforeach</tbody></table></section>
<section><h2>Audience progress</h2>@foreach($recipients as $state=>$total)<p>{{ $state }}: {{ $total }}</p>@endforeach</section>
<section><h2>Call attempts</h2><table><thead><tr><th>Attempt ID</th><th>State / outcome</th><th>Answer (UTC)</th><th>Playback complete (UTC)</th><th>End (UTC)</th></tr></thead><tbody>@foreach($attempts as $attempt)<tr><td style="overflow-wrap:anywhere">{{ $attempt->uuid }}</td><td>{{ $attempt->state }} / {{ $attempt->outcome ?? 'Pending' }}</td><td>{{ $attempt->answered_at }}</td><td>{{ $attempt->playback_completed_at }}</td><td>{{ $attempt->ended_at }}</td></tr>@endforeach</tbody></table>{{ $attempts->links() }}</section>
@endsection
