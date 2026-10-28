{{-- Personal alert cards. Expects a paginator of presented notifications. --}}
<ul class="alert-list">
    @forelse($alerts as $alert)
    <li class="alert-card is-{{ $alert['severity'] }}{{ $alert['unread'] ? ' is-unread' : '' }}">
        <div class="alert-card-head">
            <h3>@if($alert['url'])<a href="{{ $alert['url'] }}">{{ $alert['title'] }}</a>@else{{ $alert['title'] }}@endif</h3>
            <span class="alert-state">{{ $alert['unread'] ? 'Unread' : 'Read' }}</span>
            <span class="alert-tag">{{ ucfirst($alert['category']) }}</span>
        </div>
        <p class="alert-meta"><time datetime="{{ $alert['created_at'] }}">{{ $alert['created_label'] }}</time></p>
        @if($alert['body'])
        <p class="alert-body">{!! nl2br(e($alert['body'])) !!}</p>
        @endif
        @if(! empty($alert['deliveries']))
        <ul class="alert-channels" aria-label="Email and SMS copies">
            @foreach($alert['deliveries'] as $delivery)
            <li class="alert-channel is-{{ $delivery['status'] }}">{{ ucfirst($delivery['channel']) }}: {{ $delivery['status'] }}{{ $delivery['attempts'] > 1 ? ' · '.$delivery['attempts'].' attempts' : '' }}{{ $delivery['last_error'] ? ' — '.$delivery['last_error'] : '' }}</li>
            @endforeach
        </ul>
        @endif
    </li>
    @empty
    <li class="alert-empty">No account alerts in this category.</li>
    @endforelse
</ul>
