@extends('foundation.layout')
@section('title','Contact directory')
@section('content')
<h2>Contact directory</h2>
<p>Find contacts, review consent and organize campaign audiences.</p>@include('contacts.nav')
<section>
    <form method="get"><label>Search name or phone<input name="q" value="{{ $f['q']??'' }}" maxlength="100"></label><label>Group<select name="group_id">
                <option value="">All groups</option>@foreach($groups as $g)<option value="{{ $g->id }}" @selected((string)($f['group_id']??'')===(string)$g->id)>{{ $g->name }}</option>@endforeach
            </select></label><label>Consent evidence<select name="consent">
                <option value="">All</option>
                <option value="granted" @selected(($f['consent']??'')==='granted' )>Has current grant</option>
                <option value="revoked" @selected(($f['consent']??'')==='revoked' )>Has withdrawal</option>
            </select></label><label>Suppression<select name="suppressed">
                <option value="">All</option>
                <option value="1" @selected(($f['suppressed']??'')==='1' )>Suppressed now</option>
            </select></label><button>Search</button><a class="button secondary" href="{{ route('contacts.index') }}" aria-label="Reset filters">Reset</a></form>
    <div class="table-scroll contact-table">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Contact</th>
                    <th>Phone</th>
                    <th>Source</th>
                    <th>Consent grants</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>@forelse($contacts as $c)<tr>
                    <td>{{ $c->id }}</td>
                    <td><a href="{{ route('contacts.show',$c) }}">{{ $c->name??'Unnamed' }}</a></td>
                    <td>{{ $c->phone_e164 }}</td>
                    <td>{{ $c->source }}</td>
                    <td>{{ $c->active_consents_count }}<small class="block">Purpose-specific evidence required</small></td>
                    <td><span class="pill {{ isset($suppressed[$c->phone_e164])?'warn':'neutral' }}">{{ isset($suppressed[$c->phone_e164])?'Suppressed':'Not suppressed' }}</span></td>
                </tr>@empty<tr>
                    <td colspan="6">No matching contacts.</td>
                </tr>@endforelse</tbody>
        </table>
    </div>{{ $contacts->links() }}
</section>
@can('contacts.manage')<section>
    <h2>Add contact</h2>
    <form method="post" action="{{ route('contacts.store') }}">@csrf<label>Name<input name="name" required maxlength="255" value="{{ old('name') }}"></label><label>Phone<input name="phone" required maxlength="40" placeholder="0241234567" value="{{ old('phone') }}"></label><label>Source<input name="source" required maxlength="255" value="{{ old('source') }}"></label><button>Add contact</button></form>
    <p>Creating a contact does not grant permission to call. Record purpose-specific consent on its detail screen.</p>
</section>@endcan
@endsection