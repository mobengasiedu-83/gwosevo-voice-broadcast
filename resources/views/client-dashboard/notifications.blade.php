@extends('dashboard.layout')
@section('title','Notifications')
@section('content')
<h1>Notifications</h1>
<p>Personal account alerts and recording, campaign and import notices. Read status is personal to your login.</p>
<section>
<h2>Event notifications</h2>
<p>Email and SMS copies are optional and switched off by default. Alerts always stay in your account inbox below.</p>
<form method="post" action="{{ route('account.notifications.preferences') }}">@csrf
@foreach($eventPreferences as $eventPreference)
<input type="hidden" name="{{ $eventPreference['key'] }}" value="0">
<label><input type="checkbox" name="{{ $eventPreference['key'] }}" value="1" @checked($eventPreference['enabled'])> {{ $eventPreference['label'] }}</label>
@endforeach
<button>Save event notifications</button>
</form>
@foreach(array_unique(array_column($eventPreferences, 'hint')) as $hint)
<p>{{ $hint }}</p>
@endforeach
</section>
<section>
<h2>Account alerts</h2>
@if($alertSummary['unread'] && $alertSummary['latest_id'])
<form method="post" action="{{ route('account.notifications.alerts.read') }}">@csrf<input type="hidden" name="through_id" value="{{ $alertSummary['latest_id'] }}"><button>Mark alerts as read</button></form>
@endif
<nav aria-label="Alert categories">
<a href="{{ route('account.notifications') }}" @if(!$category) aria-current="page" @endif>All</a>
@foreach(\App\Services\Notifications\Center::CATEGORIES as $key)
<a href="{{ route('account.notifications', ['category' => $key]) }}" @if($category === $key) aria-current="page" @endif>{{ ucfirst($key) }}</a>
@endforeach
</nav>
@forelse($alerts as $alert)
<article class="notice {{ $alert['severity'] }}">
<h3>@if($alert['url'])<a href="{{ $alert['url'] }}">{{ $alert['title'] }}</a>@else{{ $alert['title'] }}@endif</h3>
<p>{{ $alert['created_label'] }} · {{ $alert['unread'] ? 'Unread' : 'Read' }} · {{ ucfirst($alert['category']) }}</p>
@if($alert['body'])<p>{!! nl2br(e($alert['body'])) !!}</p>@endif
@if($alert['deliveries'])
<p><strong>Email and SMS</strong>
@foreach($alert['deliveries'] as $delivery)
<span>{{ ucfirst($delivery['channel']) }}: {{ $delivery['status'] }}</span>
@endforeach
</p>
@endif
</article>
@empty
<p>No account alerts in this category.</p>
@endforelse
{{ $alerts->links() }}
</section>
@if($deliveries->isNotEmpty())
<section>
<h2>Delivery-only alerts</h2>
<p>Recording review decisions also appear in account notices below. This section shows their email and SMS delivery.</p>
@foreach($deliveries as $alert)
<article>
<h3>{{ $alert['title'] }}</h3>
<p>{{ $alert['created_label'] }}</p>
@foreach($alert['deliveries'] as $delivery)<small>{{ ucfirst($delivery['channel']) }}: {{ $delivery['status'] }}</small> @endforeach
</article>
@endforeach
{{ $deliveries->links() }}
</section>
@endif
<section>
<h2>Account notices</h2>
@if($summary['unread'] && $summary['latest_id'])<form method="post" action="{{ route('account.notifications.read') }}">@csrf<input type="hidden" name="through_id" value="{{ $summary['latest_id'] }}"><button>Mark notices as read</button></form>@endif
@forelse($notices as $notice)
<article>
<h3>@if($notice['url'])<a href="{{ $notice['url'] }}">{{ $notice['title'] }}</a>@else{{ $notice['title'] }}@endif</h3>
<p>{{ $notice['created_at']?->format('d M Y H:i') }} UTC · {{ $notice['unread']?'Unread':'Read' }}</p>
</article>
@empty
<p>No account notices yet.</p>
@endforelse
{{ $notices->links() }}
</section>
@endsection

