@extends('foundation.layout')
@section('title','Contact groups')
@section('content')
<div class="contact-heading">
    <div>
        <h1>Contact groups</h1>
        <p>Create and manage contact groups for your voice broadcast campaigns.</p>
    </div>@can('contacts.manage')<a class="button" href="#create-group">＋ Create group</a>@endcan
</div>
<div class="kpi-grid contact-kpis">@foreach(['groups'=>['Total groups','contacts.groups','◈'],'contacts'=>['Total contacts','contacts.index','♧'],'imports'=>['My imports this month','contacts.imports','↑'],'suppressed'=>['Suppressed numbers','contacts.suppressions','⊘']] as $key=>[$label,$route,$icon])<div class="kpi">
        <div>{{ $label }}<span class="metric-icon">{{ $icon }}</span></div><strong>{{ number_format($stats[$key]) }}</strong>@if($key!=='imports'||auth()->user()->can('contacts.manage'))<a class="text-link" href="{{ route($route) }}">View {{ strtolower($label) }} →</a>@endif
    </div>@endforeach</div>
@include('contacts.nav')
<nav class="module-tabs" aria-label="Group filters"><a href="{{ route('contacts.groups') }}">All groups</a><a href="{{ route('contacts.groups',['active'=>1]) }}">Active groups</a>@can('contacts.manage')<a href="{{ route('contacts.imports') }}">Import history</a>@endcan<a href="{{ route('contacts.suppressions') }}">Suppression list</a></nav>
<section>
    <form method="get" class="campaign-filters"><label>Search groups<input name="q" value="{{ $f['q']??'' }}" placeholder="Search groups…" maxlength="100"></label><label>Category<select name="category">
                <option value="">All categories</option>@foreach($categories as $category)<option @selected(($f['category']??'')===$category)>{{ $category }}</option>@endforeach
            </select></label><label>Status<select name="active">
                <option value="">All statuses</option>
                <option value="1" @selected(($f['active']??'')==='1' )>Active</option>
                <option value="0" @selected(($f['active']??'')==='0' )>Inactive</option>
            </select></label><label>Sort<select name="sort">@foreach(['newest'=>'Newest first','oldest'=>'Oldest first','name'=>'Name A–Z'] as $key=>$label)<option value="{{ $key }}" @selected(($f['sort']??'newest')===$key)>{{ $label }}</option>@endforeach</select></label><button>Search</button><a class="button secondary" href="{{ route('contacts.groups') }}" aria-label="Reset filters">Reset</a></form>
    <div class="table-scroll contact-table">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Group name</th>
                    <th>Description</th>
                    <th>Category</th>
                    <th>Contacts</th>
                    <th>Created date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>@forelse($groups as $g)<tr>
                    <td>{{ $g->id }}</td>
                    <td><a href="{{ route('contacts.groups.show',$g) }}">{{ $g->name }}</a></td>
                    <td>{{ $g->description??'—' }}</td>
                    <td><span class="pill">{{ $g->category }}</span></td>
                    <td>{{ number_format($g->contacts_count) }}</td>
                    <td>{{ $g->created_at?->format('d M Y')??'—' }}</td>
                    <td><span class="pill {{ $g->active?'good':'neutral' }}">{{ $g->active?'Active':'Inactive' }}</span></td>
                    <td><a href="{{ route('contacts.groups.show',$g) }}">Manage →</a></td>
                </tr>@empty<tr>
                    <td colspan="8">No groups match these filters. Create your first group below.</td>
                </tr>@endforelse</tbody>
        </table>
    </div>{{ $groups->links() }}
</section>
<div class="contact-panels">@can('contacts.manage')<section id="create-group">
        <h2>Create new group</h2>
        <form method="post" action="{{ route('contacts.groups.store') }}" class="stack-form">@csrf<label>Group name<input name="name" required maxlength="255" placeholder="Enter group name" value="{{ old('name') }}"></label><label>Description<textarea name="description" rows="3" maxlength="2000" placeholder="Optional description">{{ old('description') }}</textarea></label><label>Category<input name="category" required maxlength="40" list="group-categories" value="{{ old('category','General') }}"></label><datalist id="group-categories">@foreach(['General','Surveys','Finance','Marketing','Events','Test','Leads','VIP'] as $category)<option>{{ $category }}</option>@endforeach</datalist><input type="hidden" name="active" value="1"><button>Create group</button></form>
    </section>@endcan
    @include('contacts.upload')
</div>
@endsection