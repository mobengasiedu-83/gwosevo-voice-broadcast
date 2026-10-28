@extends('foundation.layout')
@section('title','Notifications')
@section('content')
<div class="contact-heading">
    <div>
        <h1>Notifications</h1>
        <p>Personal review, campaign, payment and calling-credit alerts for {{ auth()->user()->name }}. Email and SMS copies are queued independently of requests.</p>
    </div>
</div>
<section>
<h2>Account alerts</h2>
<div class="alert-toolbar">
<nav class="module-tabs" aria-label="Alert categories">
<a href="{{ route('notifications.index') }}" @if(!$category) aria-current="page" @endif>All</a>
@foreach(\App\Services\Notifications\Center::CATEGORIES as $key)
<a href="{{ route('notifications.index', ['category' => $key]) }}" @if($category === $key) aria-current="page" @endif>{{ ucfirst($key) }}</a>
@endforeach
</nav>
@if($summary['unread'] && $summary['latest_id'])
<form method="post" action="{{ route('notifications.read') }}" class="alert-toolbar-action">@csrf
<input type="hidden" name="through_id" value="{{ $summary['latest_id'] }}">
<button class="button secondary">Mark alerts as read</button>
</form>
@endif
</div>
@include('notifications.partials.alerts', ['alerts' => $notificationPage])
{{ $notificationPage->links() }}
</section>
@if($deliveries->isNotEmpty())
<section>
<h2>Delivery-only alerts</h2>
<p>These events already appear in operational notices, so only their email and SMS delivery is repeated here.</p>
@include('notifications.partials.deliveries', ['deliveries' => $deliveries])
{{ $deliveries->links() }}
</section>
@endif
@endsection
