{{-- Delivery-only alerts: events whose in-app copy lives in the account notices feed. --}}
<div class="table-scroll">
    <table class="alert-delivery-table">
        <thead>
            <tr>
                <th>Alert</th>
                <th>Created (UTC)</th>
                <th>Email and SMS</th>
            </tr>
        </thead>
        <tbody>
            @foreach($deliveries as $alert)
            <tr>
                <td>{{ $alert['title'] }}@if($alert['body'])<small class="block">{!! nl2br(e($alert['body'])) !!}</small>@endif</td>
                <td>{{ $alert['created_label'] }}</td>
                <td>
                    <ul class="alert-channels" aria-label="Delivery status">
                        @forelse($alert['deliveries'] as $delivery)
                        <li class="alert-channel is-{{ $delivery['status'] }}">{{ ucfirst($delivery['channel']) }}: {{ $delivery['status'] }}{{ $delivery['attempts'] > 1 ? ' · '.$delivery['attempts'].' attempts' : '' }}{{ $delivery['last_error'] ? ' — '.$delivery['last_error'] : '' }}</li>
                        @empty
                        <li class="alert-channel">No delivery recorded</li>
                        @endforelse
                    </ul>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
