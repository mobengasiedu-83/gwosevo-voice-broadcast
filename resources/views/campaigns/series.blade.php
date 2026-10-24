@extends(auth()->user()->isClient() ? 'clients.layout' : 'foundation.layout')
@section('heading', 'Recurring campaigns')
@section('description', 'Create bounded daily, weekly or monthly campaign schedules.')
@section(auth()->user()->isClient() ? 'portal-content' : 'content')
<h2>Recurring campaigns</h2><p>Each occurrence uses a frozen copy of the template audience and settings, with fresh launch checks and pricing. No overlapping occurrences or catch-up calls. Stopping a schedule does not cancel an existing run.</p>
<form method="post" action="{{ route(auth()->user()->isClient() ? 'client.campaigns.series.store' : 'campaign-series.store') }}">@csrf
<label>Template campaign <select name="campaign_id" required>@foreach($campaigns as $campaign)<option value="{{ $campaign->id }}">#{{ $campaign->id }} {{ $campaign->name }} · {{ $campaign->timezone }}</option>@endforeach</select></label>
<label>Repeat <select name="frequency"><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly (same day, clamped at month end)</option></select></label>
<label>First date <input type="date" name="starts_on" required></label><label>Last date <input type="date" name="ends_on" required></label><label>Local start time in template timezone <input type="time" name="local_time" required></label>
<label><input type="checkbox" name="auto_start" value="1"> Start occurrences automatically through normal launch checks</label><p>Leave unchecked to generate a campaign for manual review and launch. A blocked automatic launch pauses the series for review. Schedules expire within one year.</p><button class="button">Create recurring schedule</button></form>
@foreach($series as $item)<section class="panel"><h3>{{ json_decode($item->template, true)['name'] ?? 'Campaign series' }} · Series #{{ $item->id }} · Template #{{ $item->campaign_id }}</h3><p>{{ $item->frequency }} · {{ $item->local_time }} {{ $item->timezone }} · ends {{ $item->ends_on }} · {{ $item->active ? 'Active' : 'Stopped' }} · {{ $item->auto_start ? 'Automatic launch' : 'Manual review' }}</p><p>Next slot (UTC): {{ $item->next_run_at }}</p>
@if($item->active)<form method="post" action="{{ route(auth()->user()->isClient() ? 'client.campaigns.series.stop' : 'campaign-series.stop', $item->id) }}">@csrf<button class="button">Stop future occurrences</button></form>@endif
@foreach($occurrences->get($item->id, collect()) as $run)<p>{{ $run->scheduled_for }} UTC · {{ $run->status }} @if($run->campaign_id) · <a href="{{ route(auth()->user()->isClient() ? 'client.campaigns.show' : 'campaigns.show', $run->campaign_id) }}">Campaign #{{ $run->campaign_id }}</a>@endif {{ $run->reason }}</p>@endforeach</section>@endforeach
{{ $series->links() }}
@endsection
