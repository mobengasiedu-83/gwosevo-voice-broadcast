@extends('foundation.layout')
@section('title','Campaign runs')
@section('content')
<h2>Campaign runs</h2><p>Each launch records its immutable approval and audience. Retries belong to that execution; repeating a campaign creates a fresh draft and approval.</p>@include('campaigns.nav')
<section><table><thead><tr><th>Execution</th><th>Status</th><th>Submitted</th><th>Answered</th><th>Playback completed</th><th>Uncertain</th><th>Results</th></tr></thead><tbody>@forelse($runs as $run)@php($m=$metrics[$run->campaign_id]??null)<tr><td><a href="{{ route('campaigns.show',$run->campaign_id) }}">{{ $run->name }}</a><small class="block mono">{{ $run->uuid }}</small><small class="block">{{ $run->created_at }} UTC · user #{{ $run->started_by }} · approval #{{ $run->campaign_version_id }}</small></td><td>{{ $run->status }}</td><td>{{ $m?->sent??0 }}</td><td>{{ $m?->answered??0 }}</td><td>{{ $m?->played??0 }}</td><td>{{ $m?->uncertain??0 }}</td><td><a href="{{ route('broadcast.report',$run->campaign_id) }}">Call outcomes →</a></td></tr>@empty<tr><td colspan="7">No executions have been launched.</td></tr>@endforelse</tbody></table>{{ $runs->links() }}</section>
@endsection
