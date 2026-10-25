@extends('clients.layout')
@section('heading', 'Campaigns')
@section('description', 'Create, schedule and monitor campaigns in your account. Completed means execution ended; it does not guarantee successful delivery.')
@section('portal-content')
@if(auth()->user()->clientCan('campaigns.write'))<p><a class="button" href="{{ route('client.campaigns.create') }}">Create campaign</a></p>@endif
<form class="filters panel" method="get" action="{{ route('client.campaigns') }}"><div class="form-field"><label for="q">Campaign name</label><input id="q" name="q" value="{{ request('q') }}" maxlength="100" placeholder="Search campaigns"></div><div class="form-field"><label for="status">Status</label><select id="status" name="status"><option value="">All statuses</option>@foreach(['draft','ready','scheduled','running','paused','completed','cancelled'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></div><button class="button">Apply filters</button><a href="{{ route('client.campaigns') }}">Reset</a></form>
<section class="panel">@include('clients.campaign-table')<div class="pagination">{{ $campaigns->links('clients.pagination') }}</div></section>
@endsection
