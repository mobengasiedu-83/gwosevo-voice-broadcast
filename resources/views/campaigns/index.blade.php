@extends('foundation.layout')
@section('title','Campaigns')
@section('content')
<h2>Campaigns</h2>
<p>Create a campaign with your group, caller ID and audio. Approved audio makes it Ready to start.</p>
@include('campaigns.nav')
<form method="get" class="campaign-filters"><input type="hidden" name="view" value="{{ $view }}"><label>Search <input name="q" value="{{ $f['q']??'' }}" maxlength="100" placeholder="Campaign name"></label><label>Status <select name="status">
            <option value="">All statuses</option>@foreach(['draft','ready','scheduled','running','paused','completed','cancelled','failed'] as $status)<option value="{{ $status }}" @selected(($f['status']??'')===$status)>{{ str_replace('_',' ',$status) }}</option>@endforeach
        </select></label><label>Type <select name="type">
            <option value="">All types</option>@foreach(['one_way'=>'One-way','interactive'=>'Interactive'] as $type=>$label)<option value="{{ $type }}" @selected(($f['type']??'')===$type)>{{ $label }}</option>@endforeach
        </select></label><label>Archive <select name="archived">
            <option value="">Current</option>
            <option value="1" @selected(!empty($f['archived']))>Archived</option>
        </select></label>@if($view==='scheduled')<label>Calendar month (Africa/Accra)<input type="month" name="month" value="{{ $f['month']??now('Africa/Accra')->format('Y-m') }}"></label>@endif<button>Apply filters</button><a class="button secondary" href="{{ route('campaigns.index') }}" aria-label="Reset filters">Reset</a></form>
@if($view==='scheduled')<section>
    <h2>Schedule calendar · Africa/Accra</h2>
    <div class="schedule-calendar">@forelse($calendar as $date=>$items)<article>
            <h3>{{ $date }}</h3>@foreach($items as $item)<p><a href="{{ route('campaigns.show',$item) }}">{{ $item->scheduled_at->timezone('Africa/Accra')->format('H:i') }} · {{ $item->name }}</a>@include('partials.client-identity',['record'=>$item])</p>@endforeach
        </article>@empty<p>No scheduled campaigns in this month.</p>@endforelse</div>
    <p>Shows up to 500 launches. The list below includes every matching campaign.</p>
</section>@endif
@if($view==='allocation')<p class="notice">Weighted admission uses approved priority (1–10), campaign CPS and channel caps. Actual capacity also depends on global/trunk limits and reserved normal traffic. Weights are relative scheduling shares, not guaranteed channels. Edit limits before starting; repeat an active campaign as a new draft.</p>@endif
<section>
    <h2>{{ ucfirst($view) }} campaigns</h2>
    <table>
        <thead>
            <tr>
                <th>Campaign</th>
                <th>Status</th>
                <th>Schedule</th>
                <th>Configured limits</th>
                <th>Reserved now</th>
                <th>Open</th>
            </tr>
        </thead>
        <tbody>@forelse($campaigns as $c)<tr>
                <td>{{ $c->name }}@include('partials.client-identity',['record'=>$c])<small class="block">{{ $c->type==='one_way'?'One-way':'Interactive' }} · {{ $c->purpose }}</small></td>
                <td><span class="pill">{{ str_replace('_',' ',$c->status) }}</span><small class="block">{{ $c->versions_count }} launch snapshots{{ $c->archived_at?' · Archived':'' }}</small></td>
                <td>{{ $c->scheduled_at?->timezone($c->timezone)->format('d M Y H:i')??'Unscheduled' }}<small class="block">{{ $c->timezone }}</small></td>
                <td>{{ $c->configuration['concurrency']??'—' }} channels · {{ $c->configuration['cps']??'—' }} CPS<small class="block">Weight {{ $c->active_version_id ? $c->priority : ($c->configuration['priority']??1) }}{{ $c->status==='draft'?' · draft values':'' }}</small></td>
                <td>{{ $allocated[$c->id]??0 }}</td>
                <td><a href="{{ route('campaigns.show',$c) }}">Open campaign →</a></td>
            </tr>@empty<tr>
                <td colspan="6">No campaigns match these filters.</td>
            </tr>@endforelse</tbody>
    </table>{{ $campaigns->links() }}
</section>
@endsection