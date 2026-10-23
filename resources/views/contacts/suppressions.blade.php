@extends('foundation.layout')
@section('title','Suppression / DNC')
@section('content')
<h2>Suppression / DNC</h2><p>Global exclusions apply to matching numbers from their effective time. Withdrawal does not silently restore eligibility.</p>@include('contacts.nav')
<section><form method="get"><label>Find number<input name="q" maxlength="100" value="{{ $f['q']??'' }}"></label><button>Search</button></form><div class="table-scroll contact-table"><table><thead><tr><th>Phone</th><th>Reason</th><th>Effective (UTC)</th><th>Added by</th></tr></thead><tbody>@forelse($entries as $entry)<tr><td>{{ $entry->phone_e164 }}</td><td>{{ $entry->reason }}</td><td>{{ $entry->effective_at }}</td><td>User #{{ $entry->created_by }}</td></tr>@empty<tr><td colspan="4">No suppression entries.</td></tr>@endforelse</tbody></table></div>{{ $entries->links() }}</section>
@can('contacts.manage')<section><h2>Add global suppression</h2><form method="post" action="{{ route('contacts.suppressions.store') }}">@csrf<label>Phone<input name="phone" required maxlength="40" placeholder="0241234567"></label><label>Reason / source<input name="reason" required maxlength="255"></label><label>Effective at (UTC; blank = now)<input type="datetime-local" name="effective_at"></label><button class="destructive">Add suppression</button></form></section>@endcan
@endsection
