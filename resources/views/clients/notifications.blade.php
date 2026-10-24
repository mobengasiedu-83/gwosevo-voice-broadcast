@extends('clients.layout')
@section('heading', 'Notifications')
@section('description', 'Recording reviews, campaign outcomes, credit alerts and private report delivery. Read status and preferences are personal to you.')
@section('portal-content')
<link rel="stylesheet" href="{{ asset('css/reports.css') }}">
<section class="panel">
<div class="panel-heading"><h2>{{ $unread }} unread</h2>@if($unread)<form method="post" action="{{ route('client.notifications.read-all') }}">@csrf<button class="button secondary">Mark all as read</button></form>@endif</div>
<form class="filters" method="get"><label>Category<select name="category"><option value="">All categories</option>@foreach(['audio','campaign','wallet','report'] as $category)<option @selected(request('category')===$category)>{{ $category }}</option>@endforeach</select></label><label><input type="checkbox" name="unread" value="1" @checked(request('unread')==='1')> Unread only</label><button class="button secondary">Apply</button></form>
@forelse($notices as $notice)
<article class="notification-item {{ !$notice->read_at ? 'unread' : '' }}"><div class="panel-heading"><h3>{{ $notice->title }}</h3><span class="pill {{ $notice->severity === 'warning' ? 'warn' : '' }}">{{ $notice->severity === 'warning' ? 'Action needed · ' : '' }}{{ $notice->read_at ? 'Read' : 'Unread' }}</span></div><p>{{ $notice->message }}</p>
@if(str_starts_with($notice->kind, 'audio.'))<a href="{{ route('client.audio') }}">Open audio library</a>@elseif(str_starts_with($notice->kind, 'wallet.'))<a href="{{ route('client.wallet') }}">Open wallet</a>@elseif(str_starts_with($notice->kind, 'report.'))<a href="{{ route('client.reports') }}">Open private reports</a>@else<a href="{{ route('client.campaigns') }}">Open campaigns</a>@endif
<div class="panel-heading"><small class="muted">{{ $notice->occurred_at->format('d M Y, H:i') }} UTC</small>@if(!$notice->read_at)<form method="post" action="{{ route('client.notifications.read', $notice->id) }}">@csrf<button class="quiet-button">Mark as read</button></form>@endif</div></article>
@empty<div class="empty"><h3>No notifications yet</h3><p>New account activity will appear here.</p></div>@endforelse
<div class="pagination">{{ $notices->links('clients.pagination') }}</div>
</section>
<section class="panel" style="margin-top:24px"><h2>Notification preferences</h2><p>In-app notices remain available. Email and SMS are optional, use verified contacts and contain only a prompt to sign in. New opt-ins apply to future notices; service configuration is also required.</p>
<form method="post" action="{{ route('client.notifications.preferences') }}">@csrf
<div class="notification-options"><label><input type="checkbox" name="email_enabled" value="1" @checked(old('email_enabled', $preferences?->email_enabled))> Email alerts {{ auth()->user()->email_verified_at ? '(verified email)' : '(email verification required)' }}</label><label><input type="checkbox" name="sms_enabled" value="1" @checked(old('sms_enabled', $preferences?->sms_enabled))> SMS alerts {{ auth()->user()->phone_verified_at ? '(verified phone)' : '(phone verification required)' }}</label></div>
@if($wallet)<div class="form-field"><label for="low_balance">Low-credit threshold ({{ $wallet->currency }}, {{ $wallet->minor_digits }} decimal places)</label><input id="low_balance" name="low_balance" inputmode="decimal" value="{{ old('low_balance', \App\Services\Billing\Ledger::format($preferences?->low_balance_minor ?? 0, $wallet->minor_digits)) }}"><p>Set zero to disable low-credit alerts. Available credit excludes reservations.</p></div>@else<p>Assign a wallet before configuring a credit threshold.</p>@endif
<button class="button">Save preferences</button></form>
</section>
<section class="panel" style="margin-top:24px"><h2>Recent channel delivery</h2><p>At most three attempts per alert and ten outbound attempts per hour. Uncertain interrupted sends are not automatically repeated.</p><div class="table-scroll"><table><thead><tr><th>Channel</th><th>Status</th><th>Attempts</th><th>Time UTC</th><th>Detail</th></tr></thead><tbody>@forelse($deliveries as $delivery)<tr><td>{{ ucfirst($delivery->channel) }}</td><td>{{ $delivery->status }}</td><td>{{ $delivery->attempts }}</td><td>{{ $delivery->sent_at ?? $delivery->updated_at }}</td><td>{{ $delivery->failure_reason }}</td></tr>@empty<tr><td colspan="5">No channel deliveries yet.</td></tr>@endforelse</tbody></table></div></section>
@endsection
