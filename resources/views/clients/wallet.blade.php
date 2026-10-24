@extends('clients.layout')
@section('heading', 'Wallet and transactions')
@section('description', 'Available credit excludes funds reserved for active or uncertain calls.')
@section('portal-content')
@php($money = fn($value) => \App\Services\Billing\Ledger::format((int) $value, (int) $wallet->minor_digits))
@if(!$billingEnabled)<div class="notice">Automatic billing admission is disabled pending financial acceptance.</div>@endif
@if(!$wallet)<section class="panel"><h2>Wallet not configured</h2><p>Contact support to assign pricing and arrange verified manual credit. Online top-ups will be introduced separately.</p></section>
@else
<div class="metrics"><section class="metric"><div>Balance · {{ $wallet->currency }}</div><strong>{{ $money($wallet->balance_minor) }}</strong></section><section class="metric"><div>Reserved · {{ $wallet->currency }}</div><strong>{{ $money($wallet->reserved_minor) }}</strong></section><section class="metric"><div>Available · {{ $wallet->currency }}</div><strong>{{ $money($wallet->balance_minor - $wallet->reserved_minor) }}</strong></section></div>
<section class="panel"><h2>Your price</h2>@if($rate)<p>{{ $wallet->currency }} {{ $money($rate->rate_minor_per_minute) }} per minute · {{ $rate->plan_code }} v{{ $rate->version }}</p><p>{{ \App\Services\Billing\Policy::description($rate->toArray()) }} Contact support about disputed calls and refunds. Each execution retains its launch price.</p>@else<p>No price has been assigned. Contact support.</p>@endif</section>
<section class="panel"><h2>Transactions</h2><div class="table-scroll"><table><thead><tr><th>Time (UTC)</th><th>Type</th><th>Amount ({{ $wallet->currency }})</th><th>Balance after</th></tr></thead><tbody>@forelse($entries as $entry)<tr><td>{{ $entry->created_at }}</td><td>{{ str_replace('_', ' ', ucfirst($entry->kind)) }}</td><td>{{ $money($entry->amount_minor) }}</td><td>{{ $money($entry->balance_after_minor) }}</td></tr>@empty<tr><td colspan="4">No transactions yet.</td></tr>@endforelse</tbody></table></div>{{ $entries->links('clients.pagination') }}</section>
@endif
@endsection
