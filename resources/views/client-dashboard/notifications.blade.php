@extends('dashboard.layout')
@section('title','Notifications')
@section('content')
<div class="contact-heading">
    <div>
        <h1>Notifications</h1>
        <p>Account alerts, recording decisions and campaign notices for {{ auth()->user()->name }}. Read status is personal to your login.</p>
    </div>
</div>
<section>
<h2>Account alerts</h2>
<div class="alert-toolbar">
<nav class="module-tabs" aria-label="Alert categories">
<a href="{{ route('account.notifications') }}" @if(!$category) aria-current="page" @endif>All</a>
@foreach(\App\Services\Notifications\Center::CATEGORIES as $key)
<a href="{{ route('account.notifications', ['category' => $key]) }}" @if($category === $key) aria-current="page" @endif>{{ ucfirst($key) }}</a>
@endforeach
</nav>
@if($alertSummary['unread'] && $alertSummary['latest_id'])
<form method="post" action="{{ route('account.notifications.alerts.read') }}" class="alert-toolbar-action">@csrf
<input type="hidden" name="through_id" value="{{ $alertSummary['latest_id'] }}">
<button class="button secondary">Mark alerts as read</button>
</form>
@endif
</div>
@include('notifications.partials.alerts', ['alerts' => $alerts])
{{ $alerts->links() }}
</section>
@if($deliveries->isNotEmpty())
<section>
<h2>Delivery-only alerts</h2>
<p>Recording review decisions also appear in account notices below. This section lists only their email and SMS delivery.</p>
@include('notifications.partials.deliveries', ['deliveries' => $deliveries])
{{ $deliveries->links() }}
</section>
@endif
<section>
<h2>Account notices</h2>
<p>Recording, campaign and import activity from your account history. Times are UTC.</p>
@if($summary['unread'] && $summary['latest_id'])
<div class="alert-toolbar">
<form method="post" action="{{ route('account.notifications.read') }}" class="alert-toolbar-action">@csrf
<input type="hidden" name="through_id" value="{{ $summary['latest_id'] }}">
<button class="button secondary">Mark notices as read</button>
</form>
</div>
@endif
<ul class="alert-list">
@forelse($notices as $notice)
<li class="alert-card is-compact{{ $notice['unread'] ? ' is-unread' : '' }}">
<div class="alert-card-head">
<h3>@if($notice['url'])<a href="{{ $notice['url'] }}">{{ $notice['title'] }}</a>@else{{ $notice['title'] }}@endif</h3>
<span class="alert-state">{{ $notice['unread']?'Unread':'Read' }}</span>
</div>
<p class="alert-meta"><time datetime="{{ $notice['created_at']?->toIso8601String() }}">{{ $notice['created_at']?->format('j M Y, H:i') }} UTC</time></p>
</li>
@empty
<li class="alert-empty">No account notices yet.</li>
@endforelse
</ul>
{{ $notices->links() }}
</section>
<section>
<h2>Event notifications</h2>
<p>Choose which event copies reach you outside the portal. Email and SMS copies are optional and off by default; the alerts above always stay in your account inbox.</p>
<form method="post" action="{{ route('account.notifications.preferences') }}" class="alert-settings-form">@csrf
<div class="alert-settings">
@foreach($eventPreferences as $eventPreference)
<input type="hidden" name="{{ $eventPreference['key'] }}" value="0">
<label><input type="checkbox" name="{{ $eventPreference['key'] }}" value="1" @checked($eventPreference['enabled'])> <span>{{ $eventPreference['label'] }}</span></label>
@endforeach
</div>
@foreach(array_unique(array_column($eventPreferences, 'hint')) as $hint)
<p class="alert-settings-hint">{{ $hint }}</p>
@endforeach
<button>Save event notifications</button>
</form>
</section>
@endsection

