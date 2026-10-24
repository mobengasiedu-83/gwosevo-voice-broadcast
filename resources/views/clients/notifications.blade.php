@extends('clients.layout')
@section('heading', 'Notifications')
@section('description', 'Recording approval decisions for your account. Read status is personal to you.')
@section('portal-content')
<section class="panel"><div class="panel-heading"><h2>{{ $unread }} unread</h2>@if($unread)<form method="post" action="{{ route('client.notifications.read-all') }}">@csrf<button class="button secondary">Mark all as read</button></form>@endif</div>
@forelse($notices as $notice)<article class="notification-item {{ !$notice->read_at ? 'unread' : '' }}"><div class="panel-heading"><h3>{{ $notice->title }}</h3><span class="pill">{{ $notice->read_at ? 'Read' : 'Unread' }}</span></div><p>{{ $notice->message }}</p><div class="panel-heading"><small class="muted">{{ $notice->occurred_at->format('d M Y, H:i') }} UTC</small>@if(!$notice->read_at)<form method="post" action="{{ route('client.notifications.read', $notice->id) }}">@csrf<button class="quiet-button">Mark as read</button></form>@endif</div></article>@empty<div class="empty"><h3>No notifications yet</h3><p>Your recording review decisions will appear here.</p></div>@endforelse
<div class="pagination">{{ $notices->links('clients.pagination') }}</div></section>
@endsection
