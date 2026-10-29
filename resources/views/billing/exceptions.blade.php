@extends('foundation.layout')
@section('title','Call rating exceptions')
@section('content')
<h1>Call rating exceptions</h1><p>Review correlation, timing precision, tariff and playback-policy holds. Ratings are independent of wallet settlement.</p>
<form method="get"><label>Issue<input name="issue" maxlength="64" value="{{ $filters['issue']??'' }}"></label><label>State<select name="resolved"><option value="">Open</option><option value="1" @selected(($filters['resolved']??'')==='1')>Resolved</option></select></label><button>Filter</button></form>
<section><div class="table-scroll"><table><thead><tr><th>Attempt</th><th>Campaign</th><th>Issue</th><th>Recorded (UTC)</th><th>Resolved (UTC)</th></tr></thead><tbody>@forelse($issues as $issue)<tr><td><a href="{{ route('dashboard.call',$issue->attempt_id) }}">{{ $issue->uuid }}</a></td><td>{{ $issue->campaign_name }}@include('partials.client-identity',['record'=>$issue])</td><td>{{ str_replace('_',' ',$issue->issue) }}</td><td>{{ $issue->created_at }}</td><td>{{ $issue->resolved_at??'Open' }}</td></tr>@empty<tr><td colspan="5">No matching exceptions.</td></tr>@endforelse</tbody></table></div>{{ $issues->links() }}</section>
<p>Import validated CDR/CEL evidence with <code>billing:rate</code>. Integer billsec and AMI receipt times cannot establish precise billed duration. Conflicting evidence and historical ratings require reviewed correction procedures; this screen cannot discard or overwrite them.</p>
@endsection
