@extends('dashboard.layout')
@section('title','Notifications')
@section('content')
<h1>Notifications</h1><p>Account recording decisions, campaign changes and import results. Read status is personal to your login.</p>
@if($summary['unread'] && $summary['latest_id'])<form method="post" action="{{ route('account.notifications.read') }}">@csrf<input type="hidden" name="through_id" value="{{ $summary['latest_id'] }}"><button>Mark current notices as read</button></form>@endif
<section>@forelse($notices as $notice)<article><h2>@if($notice['url'])<a href="{{ $notice['url'] }}">{{ $notice['title'] }}</a>@else{{ $notice['title'] }}@endif</h2><p>{{ $notice['created_at']?->format('d M Y H:i') }} UTC · {{ $notice['unread']?'Unread':'Read' }}</p></article>@empty<p>No account notices yet.</p>@endforelse{{ $notices->links() }}</section>
@endsection
