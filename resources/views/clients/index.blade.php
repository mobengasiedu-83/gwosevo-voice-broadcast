@extends('foundation.layout')
@section('title','Clients')
@section('content')
<h1>Clients</h1>
<p>Manage client accounts, profiles and access.</p>
<div class="kpi-grid">@foreach(['pending','active','suspended','closed'] as $state)<div class="kpi">
        <div>{{ ucfirst($state) }}</div><strong>{{ number_format($counts[$state] ?? 0) }}</strong>
    </div>@endforeach</div>
<section>
    <form method="get" class="campaign-filters">
        <label>Search<input name="q" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Name, UUID, email or phone"></label>
        <label>Status<select name="status">
                <option value="">All statuses</option>@foreach(['pending','active','suspended','closed'] as $state)<option @selected(($filters['status']??'')===$state)>{{ $state }}</option>@endforeach
            </select></label>
        <label>Created from<input type="date" name="from" value="{{ $filters['from']??'' }}"></label><label>Created to<input type="date" name="to" value="{{ $filters['to']??'' }}"></label>
        <label>Sort<select name="sort">@foreach(['newest','oldest','name'] as $sort)<option @selected(($filters['sort']??'newest')===$sort)>{{ $sort }}</option>@endforeach</select></label><button>Search</button><a class="button secondary" href="{{ route('clients.index') }}" aria-label="Reset filters">Reset</a>
    </form>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>Client</th>
                    <th>Status</th>
                    <th>Users</th>
                    <th>Contacts</th>
                    <th>Campaigns</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>@forelse($clients as $client)<tr>
                    <td><a href="{{ route('clients.show',$client->id) }}">{{ $client->name }}</a><small>{{ $client->uuid }}</small></td>
                    <td>{{ $client->status }}</td>
                    <td>{{ $client->memberships_count }}</td>
                    <td>{{ $client->contacts_count }}</td>
                    <td>{{ $client->campaigns_count }}</td>
                    <td>{{ $client->created_at?->format('d M Y') }}</td>
                </tr>@empty<tr>
                    <td colspan="6">No clients match these filters.</td>
                </tr>@endforelse</tbody>
        </table>
    </div>{{ $clients->links() }}
</section>
<section>
    <h2>Create client</h2>
    <form method="post" action="{{ route('clients.store') }}" class="stack-form">@csrf<label>Client name<input name="name" required maxlength="255" value="{{ old('name') }}"></label><button>Create pending client</button></form>
</section>
@endsection