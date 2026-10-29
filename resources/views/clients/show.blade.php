@extends('foundation.layout')
@section('title', $client->name)
@section('content')
@if($client)@include('partials.client-identity',['record'=>$client])@endif
<a href="{{ route('clients.index') }}">← Clients</a><h1>{{ $client->name }}</h1><p>{{ $client->uuid }} · {{ ucfirst($client->status) }}</p>
<nav class="module-tabs" aria-label="Client sections">@foreach(['overview','profile','users','campaigns','activity','finance'] as $section)<a href="{{ route('clients.show',[$client->id,'tab'=>$section]) }}" @if($tab===$section) aria-current="page" class="selected" @endif>{{ ucfirst($section) }}</a>@endforeach</nav>
@if($tab==='profile')
<section><h2>Client profile</h2><form method="post" action="{{ route('clients.profile',$client->id) }}" class="stack-form">@csrf
@foreach(['name'=>'Client name','business_name'=>'Business name','contact_name'=>'Contact name','contact_email'=>'Contact email','contact_phone'=>'Contact phone','country'=>'Country','address'=>'Address','broadcasting_purpose'=>'Broadcasting purpose'] as $field=>$label)<label>{{ $label }}<input name="{{ $field }}" value="{{ old($field,$field==='name'?$client->name:($client->profile[$field]??'')) }}" @required($field==='name') maxlength="{{ in_array($field,['address','broadcasting_purpose'])?1000:($field==='contact_phone'?40:($field==='country'?80:254)) }}"></label>@endforeach<label>Account type<select name="account_type"><option value="">Not specified</option>@foreach(['individual','business'] as $type)<option @selected(($client->profile['account_type']??'')===$type)>{{ $type }}</option>@endforeach</select></label><button>Save profile</button></form></section>
@endif
@if($tab==='overview')
<div class="kpi-grid">@foreach(['contacts','campaigns','imports','recordings','memberships'] as $metric)<div class="kpi"><div>{{ ucfirst($metric) }}</div><strong>{{ number_format($client->{$metric.'_count'}) }}</strong></div>@endforeach</div>
<section><h2>Account status</h2><p>{{ $client->status_reason }}</p><form method="post" action="{{ route('clients.status',$client->id) }}" class="stack-form">@csrf<label>Status<select name="status">@foreach(['pending','active','suspended','closed'] as $state)<option @selected($client->status===$state)>{{ $state }}</option>@endforeach</select></label><label>Reason<textarea name="reason" required maxlength="2000">{{ old('reason') }}</textarea></label><button>Update status</button></form><p>Suspension blocks account access and new call dispatch. Reactivation does not launch campaigns.</p></section>
@endif
@if($memberships!==null)
<section><h2>Client users</h2>@forelse($memberships as $membership)<form method="post" action="{{ route('clients.membership',[$client->id,$membership->id]) }}" class="stack-form">@csrf<h3>{{ $membership->user->name }}</h3><p>{{ $membership->user->email ?: $membership->user->phone_e164 }} · Email {{ $membership->user->email_verified_at?'verified':'unverified' }} · Phone {{ $membership->user->phone_verified_at?'verified':'unverified' }}</p><label>Role<select name="role">@foreach(['owner','member'] as $role)<option @selected($membership->role===$role)>{{ $role }}</option>@endforeach</select></label><label>Access<select name="status">@foreach(['active','revoked'] as $state)<option @selected($membership->status===$state)>{{ $state }}</option>@endforeach</select></label><label>Reason<input name="reason" required maxlength="2000"></label><button>Update membership</button></form>@empty<p>No users assigned.</p>@endforelse{{ $memberships->links() }}</section>
@endif
@if($tab==='users')
<section><h2>Create client user</h2><p>Supply at least one sign-in contact. Share the temporary password securely. Contact verification is required on first sign-in.</p><form method="post" action="{{ route('clients.users.store',$client->id) }}" class="stack-form">@csrf
@foreach(['name'=>'Name','email'=>'Email','phone'=>'Phone','password'=>'Temporary password','password_confirmation'=>'Confirm password'] as $field=>$label)<label>{{ $label }}<input name="{{ $field }}" type="{{ str_starts_with($field,'password')?'password':($field==='email'?'email':'text') }}" @required(in_array($field,['name','password','password_confirmation'])) @if(str_starts_with($field,'password')) autocomplete="new-password" @else value="{{ old($field) }}" @endif></label>@endforeach
<label>Role<select name="role"><option>member</option><option>owner</option></select></label><button>Create user</button></form></section>
<section><h2>Assign existing login</h2><p>Only an unprivileged login without existing business records can be assigned. A login cannot move between clients.</p><form method="post" action="{{ route('clients.assign',$client->id) }}" class="stack-form">@csrf<label>Email<input name="email" type="email" required></label><label>Role<select name="role"><option>member</option><option>owner</option></select></label><label>Reason<input name="reason" required maxlength="2000"></label><button>Assign login</button></form></section>
@endif
@if($campaigns!==null)<section><h2>Campaigns</h2><form method="get"><input type="hidden" name="tab" value="campaigns"><label>Status<select name="campaign_status"><option value="">All statuses</option>@foreach(['draft','ready','pending_approval','approved','scheduled','running','paused','completed','cancelled','failed'] as $state)<option @selected(($filters['campaign_status']??'')===$state)>{{ $state }}</option>@endforeach</select></label><button>Filter</button></form><div class="table-scroll"><table><thead><tr><th>Name</th><th>Status</th><th>Created</th></tr></thead><tbody>@forelse($campaigns as $campaign)<tr><td>{{ $campaign->name }}</td><td>{{ $campaign->status }}</td><td>{{ $campaign->created_at }}</td></tr>@empty<tr><td colspan="3">No campaigns.</td></tr>@endforelse</tbody></table></div>{{ $campaigns->links() }}</section>@endif
@if($usage)<section><h2>Call usage</h2><p>Attempts: {{ $usage->attempts }} · Sent: {{ $usage->sent??0 }} · Answered: {{ $usage->answered??0 }} · Playback completed: {{ $usage->played??0 }}</p></section>@endif
@if($imports!==null)<section><h2>Imports</h2>@forelse($imports as $import)<p>#{{ $import->id }} · {{ $import->status }} · {{ $import->created_at }}</p>@empty<p>No imports.</p>@endforelse{{ $imports->links() }}</section>@endif
@if($audits!==null)<section><h2>Activity</h2><form method="get"><input type="hidden" name="tab" value="activity"><label>Activity type<select name="activity">@foreach(['all','logins','account'] as $type)<option @selected(($filters['activity']??'all')===$type)>{{ $type }}</option>@endforeach</select></label><button>Filter</button></form><table><thead><tr><th>Time</th><th>Action</th><th>Actor ID</th></tr></thead><tbody>@forelse($audits as $audit)<tr><td>{{ $audit->created_at }}</td><td>{{ $audit->action }}</td><td>{{ $audit->actor_id??'System' }}</td></tr>@empty<tr><td colspan="3">No activity.</td></tr>@endforelse</tbody></table>{{ $audits->links() }}</section>@endif
@if($tab==='finance')
<section><h2>Finance</h2>
@can('billing.manage')
<p><a href="{{ route('billing.rates') }}">Rate plans and assignments</a> · <a href="{{ route('billing.finance',['client_id'=>$client->id]) }}">Finance report for this client</a> · <a href="{{ route('billing.payments',['q'=>$client->name]) }}">Payments</a> · <a href="{{ route('billing.disputes',['q'=>$client->name]) }}">Disputes</a>@if($financeWallet) · <a href="{{ route('billing.wallets.statement',$client) }}">Download statement CSV</a>@endif</p>
@if($financeWallet)
<section><h2>Manual credit</h2>
<p>Administrator and Super Administrator accounts can credit this wallet immediately. The entry is append-only and audited; an approval reference is optional but recommended.</p>
<form method="post" action="{{ route('billing.wallets.credit',$client) }}" class="stack-form">@csrf
<input type="hidden" name="credit_key" value="{{ \Illuminate\Support\Str::uuid() }}">
<label>Amount ({{ $financeWallet->currency }})<input name="amount" required maxlength="32" inputmode="decimal" placeholder="50.00"></label>
<label>Reason<input name="reason" required minlength="3" maxlength="255" placeholder="Administrator manual credit"></label>
<label>Approval reference (optional)<input name="approval" maxlength="255"></label>
<button>Credit this account</button>
</form>
</section>
@endif
@if($financeTariffs)
<section><h2>Tariff assignment</h2>
@if($financeTariff)<p>Current: <strong>{{ $financeTariff['code'] }}</strong> v{{ $financeTariff['version'] }} · {{ \App\Services\Billing\Wallet::amount($financeTariff['rate_minor_per_minute'], $financeTariff['minor_unit_scale']) }} {{ $financeTariff['currency'] }} per billed minute.</p>@else<p>No tariff is assigned. Assignment is required before wallet-backed calling.</p>@endif
<form method="post" action="{{ route('billing.rates.assign') }}" class="stack-form">@csrf
<input type="hidden" name="client_id" value="{{ $client->id }}">
<label>Assign tariff<select name="tariff_id" required><option value="">Select tariff</option>@foreach($financeTariffs as $tariffOption)<option value="{{ $tariffOption->id }}">{{ $tariffOption->code }} v{{ $tariffOption->version }} · {{ $tariffOption->currency }}</option>@endforeach</select></label>
<label>Reason<input name="reason" required minlength="3" maxlength="1000" placeholder="Approved commercial assignment"></label>
<button>Assign tariff</button>
</form>
</section>
@endif
@endcan
@cannot('billing.manage')
<p>Finance details require the <code>billing.manage</code> permission. Ask a Super Administrator to review this client's wallet, rate assignment and reconciliation.</p>
@else
@if(! $financeWallet)
<p>This client has no calling wallet. Credit is not enforced for this account and campaigns keep the manual billing process.</p>
<p><a href="{{ route('billing.wallets.show',$client) }}">Open a wallet in the billing console →</a></p>
@else
@php($financeFmt = fn ($minor) => \App\Services\Billing\Wallet::amount((int) $minor, $financeWallet->minor_unit_scale))
<div class="kpi-grid">
<div class="kpi"><div>Available credit</div><strong>{{ $financeFmt($financeWallet->available_minor) }} {{ $financeWallet->currency }}</strong></div>
<div class="kpi"><div>Reserved credit</div><strong>{{ $financeFmt($financeWallet->reserved_minor) }} {{ $financeWallet->currency }}</strong></div>
<div class="kpi"><div>Calls holding credit</div><strong>{{ number_format($financeSummary['held_count']) }}</strong></div>
<div class="kpi"><div>Uncovered shortfall</div><strong>{{ $financeFmt($financeSummary['shortfall_minor']) }} {{ $financeWallet->currency }}</strong></div>
</div>
@if(! $financeVerification['ok'])
<p class="notice">Ledger reconciliation failed: {{ implode(' ', $financeVerification['issues']) }} Escalate to Finance before further activity.</p>
@else
<p>Ledger reconciliation passed: balances, entry chain, open holds and settlement lifecycle match the append-only ledger.</p>
@endif
<section><h2>Tariff and one-call coverage</h2>
@if($financeTariff)
<p>Assigned tariff <strong>{{ $financeTariff['code'] }}</strong> version {{ $financeTariff['version'] }} · {{ $financeFmt($financeTariff['rate_minor_per_minute']) }} {{ $financeTariff['currency'] }} per billed minute · maximum hold {{ config('billing.max_call_seconds') }} seconds.</p>
@else
<p>No approved call tariff is assigned. Wallet admission stays blocked until Finance <a href="{{ route('billing.rates') }}">assigns one</a>.</p>
@endif
@if($financeAffordability)
<p>One-call exposure: {{ $financeFmt($financeAffordability['exposure_minor']) }} {{ $financeWallet->currency }} · Full holds covered: {{ $financeAffordability['covered_calls'] === null ? 'No charge' : number_format($financeAffordability['covered_calls']) }} · Oldest open hold: {{ $financeSummary['oldest_hold_at']?->diffForHumans() ?? 'None' }} · Holds older than 24h: {{ number_format($financeSummary['stale_hold_count']) }}</p>
@if($financeAffordability['low'])<p class="notice">{{ \App\Services\Billing\Wallet::message($financeAffordability['reason']) }}</p>@endif
@endif
<p><a href="{{ route('billing.wallets.show',$client) }}">Manage wallet, ledger and reservations →</a></p>
</section>
<h3>Recent ledger entries</h3>
<form method="get" class="campaign-filters"><input type="hidden" name="tab" value="finance">
<label>Entry type<select name="ledger_type"><option value="">All entries</option>@foreach(\App\Services\Billing\Wallet::TYPES as $type)<option value="{{ $type }}" @selected(($filters['ledger_type']??'')===$type)>{{ ucfirst(str_replace('_',' ',$type)) }}</option>@endforeach</select></label>
@if($filters['reservation_state']??null)<input type="hidden" name="reservation_state" value="{{ $filters['reservation_state'] }}">@endif
<button>Filter ledger</button></form>
<div class="table-scroll"><table><thead><tr><th>Date (UTC)</th><th>Type</th><th>Available Δ</th><th>Reserved Δ</th><th>Reason</th></tr></thead><tbody>
@forelse($financeEntries as $entry)
<tr><td>{{ $entry->created_at?->format('d M Y H:i') }}</td><td>{{ str_replace('_',' ',$entry->type) }}</td><td>{{ $entry->available_delta_minor > 0 ? '+' : '' }}{{ $financeFmt($entry->available_delta_minor) }}</td><td>{{ $entry->reserved_delta_minor > 0 ? '+' : '' }}{{ $financeFmt($entry->reserved_delta_minor) }}</td><td>{{ $entry->reason }}</td></tr>
@empty
<tr><td colspan="5">No ledger entries.</td></tr>
@endforelse
</tbody></table></div>{{ $financeEntries->links() }}
<h3>Recent reservations</h3>
<form method="get" class="campaign-filters"><input type="hidden" name="tab" value="finance">
<label>Reservation state<select name="reservation_state"><option value="">All states</option>@foreach(['held'=>'Held','settled'=>'Settled','released'=>'Released','shortfall'=>'Settled with shortfall'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['reservation_state']??'')===$value)>{{ $label }}</option>@endforeach</select></label>
@if($filters['ledger_type']??null)<input type="hidden" name="ledger_type" value="{{ $filters['ledger_type'] }}">@endif
<button>Filter reservations</button></form>
<div class="table-scroll"><table><thead><tr><th>#</th><th>Attempt</th><th>Held</th><th>State</th><th>Settled</th><th>Shortfall</th><th>Reserved (UTC)</th></tr></thead><tbody>
@forelse($financeReservations as $reservation)
<tr><td>{{ $reservation->id }}</td><td><a href="{{ route('dashboard.call',$reservation->call_attempt_id) }}">Attempt {{ $reservation->call_attempt_id }}</a></td><td>{{ $financeFmt($reservation->amount_minor) }}</td><td>{{ $reservation->state }}</td><td>{{ $reservation->settled_minor === null ? '—' : $financeFmt($reservation->settled_minor) }}</td><td>{{ $financeFmt($reservation->shortfall_minor) }}</td><td>{{ $reservation->reserved_at?->format('d M Y H:i') }}</td></tr>
@empty
<tr><td colspan="7">No reservations.</td></tr>
@endforelse
</tbody></table></div>{{ $financeReservations->links() }}
@endif
@if($financePayments && $financePayments->isNotEmpty())
<h3>Recent payments</h3>
<div class="table-scroll"><table><thead><tr><th>Reference</th><th>Amount</th><th>Status</th><th>Created (UTC)</th><th>Credited (UTC)</th></tr></thead><tbody>
@foreach($financePayments as $payment)
<tr><td><a href="{{ route('billing.payments.show',$payment->public_id) }}">{{ $payment->public_id }}</a></td><td>{{ \App\Services\Billing\Wallet::amount($payment->amount_minor, $payment->minor_unit_scale) }} {{ $payment->currency }}</td><td>{{ $payment->status }}</td><td>{{ $payment->created_at?->format('d M Y H:i') }}</td><td>{{ $payment->credited_at?->format('d M Y H:i') ?? '—' }}</td></tr>
@endforeach
</tbody></table></div>
@endif
@if($financeDisputes && $financeDisputes->isNotEmpty())
<h3>Recent disputes</h3>
<div class="table-scroll"><table><thead><tr><th>Opened</th><th>Subject</th><th>Category</th><th>Status</th><th>Credit</th></tr></thead><tbody>
@foreach($financeDisputes as $dispute)
<tr><td>{{ $dispute->created_at?->format('d M Y H:i') }}</td><td>{{ $dispute->subject }}</td><td>{{ $dispute->category }}</td><td>{{ $dispute->status }}</td><td>@if($dispute->credit_amount_minor > 0){{ \App\Services\Billing\Wallet::amount($dispute->credit_amount_minor, $dispute->minor_unit_scale) }} {{ $dispute->currency }}@else — @endif</td></tr>
@endforeach
</tbody></table></div>
@endif
<p><a href="{{ route('billing.disputes',['q'=>$client->name]) }}">Open or resolve a dispute →</a></p>
@endcannot
</section>
@endif
@endsection
