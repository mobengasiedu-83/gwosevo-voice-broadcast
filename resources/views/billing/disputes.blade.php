@extends('foundation.layout')
@section('title','Billing disputes')
@section('content')
<h1>Billing disputes</h1>
<p>Disputes are append-only records. A resolution may award a credit, which posts as a new wallet adjustment linked to this dispute; no historical charge or payment is edited. <a href="{{ route('billing.finance') }}">Finance reports →</a> · <a href="{{ route('billing.wallets') }}">Wallets →</a></p>
@if(session('status'))<p class="notice">{{ session('status') }}</p>@endif
<div class="kpi-grid">
@foreach(['open','under_review','resolved','rejected'] as $state)
<div class="kpi"><div>{{ ucfirst(str_replace('_',' ', $state)) }}</div><strong>{{ number_format((int) ($counts[$state] ?? 0)) }}</strong></div>
@endforeach
</div>
<section><h2>Open a dispute</h2>
<form method="post" action="{{ route('billing.disputes.store') }}" class="stack-form">@csrf
<label>Client<select name="client_id" required><option value="">Select client</option>@foreach($clients as $client)<option value="{{ $client->id }}" @selected((string) old('client_id') === (string) $client->id)>{{ $client->name }} · {{ $client->display_email }} · {{ $client->status }}</option>@endforeach</select></label>
<label>Category<select name="category">@foreach(\App\Models\BillingDispute::CATEGORIES as $category)<option value="{{ $category }}" @selected(old('category') === $category)>{{ ucfirst($category) }}</option>@endforeach</select></label>
<label>Subject<input name="subject" required minlength="5" maxlength="160" value="{{ old('subject') }}"></label>
<label>Description<textarea name="description" required minlength="10" maxlength="4000">{{ old('description') }}</textarea></label>
<label>Payment reference (optional UUID)<input name="payment_reference" value="{{ old('payment_reference') }}" maxlength="36"></label>
<label>Attempt reference (optional UUID)<input name="attempt_reference" value="{{ old('attempt_reference') }}" maxlength="36"></label>
<label>Ledger entry ID (optional)<input name="ledger_entry_id" type="number" min="1" value="{{ old('ledger_entry_id') }}"></label>
<label>Disputed amount (optional, major units)<input name="amount" maxlength="32" value="{{ old('amount') }}" inputmode="decimal" placeholder="0.00"></label>
<button>Open dispute</button>
</form>
</section>
<form method="get" class="campaign-filters">
<label>Status<select name="status"><option value="all">All statuses</option>@foreach(\App\Models\BillingDispute::STATUSES as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? 'all') === $status)>{{ ucfirst(str_replace('_',' ', $status)) }}</option>@endforeach</select></label>
<label>Search<input name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Client, subject or dispute ID"></label>
<button>Filter</button>
</form>
<section><div class="table-scroll"><table><thead><tr><th>Dispute</th><th>Client</th><th>Category / status</th><th>Subject and reference</th><th>Amount</th><th>Opened</th><th>Resolution</th></tr></thead><tbody>
@forelse($disputes as $dispute)
<tr>
<td><small class="mono">{{ $dispute->public_id }}</small><br>{{ $dispute->source }}</td>
<td>{{ $dispute->client?->name ?? 'Client #'.$dispute->client_id }}@include('partials.client-identity',['record'=>$dispute])</td>
<td>{{ ucfirst($dispute->category) }}<br><strong>{{ ucfirst(str_replace('_',' ', $dispute->status)) }}</strong></td>
<td>{{ $dispute->subject }}<br><small>{{ $dispute->description }}</small>
@if($dispute->payment)<br><small>Payment {{ $dispute->payment->public_id }}</small>@endif
@if($dispute->attempt)<br><small>Attempt {{ $dispute->attempt->uuid }}</small>@endif
@if($dispute->ledgerEntry)<br><small>Ledger entry #{{ $dispute->ledgerEntry->id }}</small>@endif
</td>
<td>@if($dispute->amount_minor !== null){{ \App\Services\Billing\Wallet::amount($dispute->amount_minor, $dispute->minor_unit_scale) }} {{ $dispute->currency }}@else — @endif</td>
<td>{{ $dispute->created_at?->format('Y-m-d H:i') }}<br><small>{{ $dispute->opener?->name ?? 'System' }}</small></td>
<td>
@if($dispute->isOpen())
<details><summary>Resolve</summary>
<form method="post" action="{{ route('billing.disputes.resolve', $dispute) }}" class="stack-form">@csrf
<label>Decision<select name="status"><option value="resolved">Resolved</option><option value="rejected">Rejected</option></select></label>
<label>Resolution<textarea name="resolution" required minlength="3" maxlength="4000"></textarea></label>
<label>Credit amount (optional, major units)<input name="credit_amount" maxlength="32" inputmode="decimal" placeholder="0.00"></label>
<label>Approval reference (optional)<input name="approval" maxlength="255"></label>
<button>Record decision</button>
</form>
</details>
@else
{{ $dispute->resolution }}<br><small>{{ $dispute->resolver?->name }} · {{ $dispute->resolved_at?->format('Y-m-d H:i') }}</small>
@if($dispute->credit_amount_minor > 0)<br><small>Credit {{ \App\Services\Billing\Wallet::amount($dispute->credit_amount_minor, $dispute->minor_unit_scale) }} {{ $dispute->currency }} · entry #{{ $dispute->credit_ledger_entry_id }}</small>@endif
@endif
</td>
</tr>
@empty
<tr><td colspan="7">No disputes match.</td></tr>
@endforelse
</tbody></table></div>{{ $disputes->links() }}</section>
@endsection
