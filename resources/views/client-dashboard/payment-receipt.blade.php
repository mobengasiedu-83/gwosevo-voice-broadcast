@extends('dashboard.layout')
@section('title','Receipt '.$intent->receiptNumber())
@section('content')
@php($fmt = fn ($minor) => \App\Services\Billing\Wallet::amount((int) $minor, $intent->minor_unit_scale))
<div class="contact-heading"><div><h1>Payment receipt</h1><p>{{ $intent->receiptNumber() }}</p></div><a class="button secondary" href="{{ route('wallet.payments.show', $intent->public_id) }}">← Payment status</a></div>
<section>
<h2>GWO SEVO VoiceCast</h2>
<p>Official receipt for calling-credit top-up. This receipt is issued for the payment shown and does not represent the current wallet balance.</p>
<div class="table-scroll"><table><tbody>
<tr><th scope="row">Receipt number</th><td>{{ $intent->receiptNumber() }}</td></tr>
<tr><th scope="row">Billed to</th><td>{{ $client->name }}</td></tr>
<tr><th scope="row">Payment date</th><td>{{ ($intent->credited_at ?? $intent->created_at)?->format('d M Y H:i:s') }} UTC</td></tr>
<tr><th scope="row">Description</th><td>Calling-credit top-up</td></tr>
<tr><th scope="row">Amount paid</th><td><strong>{{ $fmt($intent->amount_minor) }} {{ $intent->currency }}</strong></td></tr>
<tr><th scope="row">Payment status</th><td>{{ $intent->statusLabel() }}</td></tr>
<tr><th scope="row">Provider</th><td>{{ $intent->provider }}</td></tr>
<tr><th scope="row">Provider reference</th><td>{{ $intent->provider_reference ?? '—' }}</td></tr>
<tr><th scope="row">Payment reference</th><td>{{ $intent->public_id }}</td></tr>
<tr><th scope="row">Wallet credited</th><td>#{{ $intent->wallet_id }} · {{ $intent->currency }}</td></tr>
</tbody></table></div>
@if($intent->status === 'refunded')
<p class="notice">This payment was refunded on {{ $intent->refunded_at?->format('d M Y H:i') }} UTC.
@if($intent->refund_reference)Refund reference {{ $intent->refund_reference }}. @endif
The original wallet credit was reversed by a separate ledger entry. This receipt remains part of the permanent payment history.</p>
@endif
<p>Questions about this payment? Quote the receipt number when contacting Finance. Wallet and payment history is append-only; corrections are posted as new entries.</p>
</section>
@endsection
