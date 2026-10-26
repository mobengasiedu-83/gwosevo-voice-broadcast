@extends('dashboard.layout')
@section('title','Billing disputes')
@section('content')
<div class="contact-heading"><div><h1>Billing disputes</h1><p>{{ $client->name }} · Questions about a charge or payment</p></div><a class="button secondary" href="{{ route('wallet.index') }}">← Calling credit</a></div>
@if(session('status'))<p class="notice">{{ session('status') }}</p>@endif
@if($wallet)
<p>Disputes are reviewed by Finance. A resolved dispute may include a credit, which is posted as a new wallet adjustment; your original transaction history is never edited. Amounts are in {{ $wallet->currency }} with {{ $wallet->minor_unit_scale }} decimal place(s).</p>
@else
<p>No wallet is configured yet. You can still record a dispute about a payment or account charge.</p>
@endif
<section><h2>Submit a dispute</h2>
<form method="post" action="{{ route('wallet.disputes.store') }}" class="stack-form">@csrf
<label>Category<select name="category">@foreach(\App\Models\BillingDispute::CATEGORIES as $category)<option value="{{ $category }}" @selected(old('category') === $category)>{{ ucfirst($category) }}</option>@endforeach</select></label>
<label>Subject<input name="subject" required minlength="5" maxlength="160" value="{{ old('subject') }}" placeholder="Charge for call on 26 Oct"></label>
<label>What happened?<textarea name="description" required minlength="10" maxlength="4000">{{ old('description') }}</textarea></label>
<label>Payment (optional)<select name="payment_reference"><option value="">No specific payment</option>@foreach($payments as $payment)<option value="{{ $payment->public_id }}" @selected(old('payment_reference') === $payment->public_id)>{{ $payment->public_id }} · {{ \App\Services\Billing\Wallet::amount($payment->amount_minor, $payment->minor_unit_scale) }} {{ $payment->currency }} · {{ $payment->status }}</option>@endforeach</select></label>
<label>Attempt reference (optional UUID)<input name="attempt_reference" value="{{ old('attempt_reference') }}" maxlength="36" placeholder="Copy from a campaign report"></label>
<label>Ledger entry ID (optional)<input name="ledger_entry_id" type="number" min="1" value="{{ old('ledger_entry_id') }}"></label>
<button>Submit dispute</button>
</form>
<p>Do not include card numbers, passwords or provider secrets. Quote references only.</p>
</section>
<form method="get" class="campaign-filters">
<label>Status<select name="status"><option value="all">All statuses</option>@foreach(\App\Models\BillingDispute::STATUSES as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? 'all') === $status)>{{ ucfirst(str_replace('_',' ', $status)) }}</option>@endforeach</select></label>
<button>Filter</button>
</form>
<section><div class="table-scroll"><table><thead><tr><th>Opened</th><th>Subject</th><th>Category</th><th>Status</th><th>Reference</th><th>Resolution</th></tr></thead><tbody>
@forelse($disputes as $dispute)
<tr>
<td>{{ $dispute->created_at?->format('d M Y H:i') }}</td>
<td>{{ $dispute->subject }}<br><small>{{ $dispute->description }}</small></td>
<td>{{ ucfirst($dispute->category) }}</td>
<td>{{ ucfirst(str_replace('_',' ', $dispute->status)) }}</td>
<td><small class="mono">{{ $dispute->public_id }}</small>@if($dispute->payment)<br><small>Payment {{ $dispute->payment->public_id }}</small>@endif@if($dispute->attempt)<br><small>Attempt {{ $dispute->attempt->uuid }}</small>@endif</td>
<td>
@if($dispute->resolution)
{{ $dispute->resolution }}@if($dispute->credit_amount_minor > 0)<br><small>Credit: {{ \App\Services\Billing\Wallet::amount($dispute->credit_amount_minor, $dispute->minor_unit_scale) }} {{ $dispute->currency }}</small>@endif
@else
Awaiting Finance review
@endif
</td>
</tr>
@empty
<tr><td colspan="6">No disputes submitted.</td></tr>
@endforelse
</tbody></table></div>{{ $disputes->links() }}</section>
@endsection
