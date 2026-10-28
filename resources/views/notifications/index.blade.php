@extends('dashboard.layout')
@section('title','Account alerts')
@section('content')
<h1>Account alerts</h1>
<p>Personal review, campaign, payment and calling-credit alerts for {{ auth()->user()->name }}. Email and SMS copies are queued independently of requests.</p>
@if($summary['unread'] && $summary['latest_id'])
<form method="post" action="{{ route('notifications.read') }}">@csrf<input type="hidden" name="through_id" value="{{ $summary['latest_id'] }}"><button>Mark all alerts as read</button></form>
@endif
<nav aria-label="Alert categories">
<a href="{{ route('notifications.index') }}" @if(!$category) aria-current="page" @endif>All</a>
@foreach(\App\Services\Notifications\Center::CATEGORIES as $key)
<a href="{{ route('notifications.index', ['category' => $key]) }}" @if($category === $key) aria-current="page" @endif>{{ ucfirst($key) }}</a>
@endforeach
</nav>
<section>
<h2>Alerts</h2>
@forelse($notificationPage as $alert)
<article class="notice {{ $alert['severity'] }}">
<h3>@if($alert['url'])<a href="{{ $alert['url'] }}">{{ $alert['title'] }}</a>@else{{ $alert['title'] }}@endif</h3>
<p>{{ $alert['created_label'] }} · {{ $alert['unread'] ? 'Unread' : 'Read' }} · {{ ucfirst($alert['category']) }}</p>
@if($alert['body'])<p>{!! nl2br(e($alert['body'])) !!}</p>@endif
@if($alert['deliveries'])
<p><strong>Delivery</strong>
@foreach($alert['deliveries'] as $delivery)
<span>{{ ucfirst($delivery['channel']) }}: {{ $delivery['status'] }}@if($delivery['attempts']) · {{ $delivery['attempts'] }} attempt(s)@endif@if($delivery['last_error']) · {{ $delivery['last_error'] }}@endif</span>
@endforeach
</p>
@endif
</article>
@empty
<p>No account alerts in this category.</p>
@endforelse
{{ $notificationPage->links() }}
</section>
@if($deliveries->isNotEmpty())
<section>
<h2>Delivery-only alerts</h2>
<p>These events already appear in operational notices, so only their email and SMS delivery is repeated here.</p>
<table>
<thead><tr><th>Alert</th><th>Created (UTC)</th><th>Channels</th></tr></thead>
<tbody>@foreach($deliveries as $alert)<tr><td>{{ $alert['title'] }}@if($alert['body'])<br><small>{!! nl2br(e($alert['body'])) !!}</small>@endif</td><td>{{ $alert['created_label'] }}</td><td>@foreach($alert['deliveries'] as $delivery){{ ucfirst($delivery['channel']) }}: {{ $delivery['status'] }}@if($delivery['last_error']) — {{ $delivery['last_error'] }}@endif<br>@endforeach</td></tr>@endforeach</tbody>
</table>
{{ $deliveries->links() }}
</section>
@endif
@endsection
