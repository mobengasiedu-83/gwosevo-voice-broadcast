@extends('foundation.layout')
@section('content')
<h2>Broadcast workspace</h2>
<nav class="workspace-actions" aria-label="Workspace shortcuts">
<a class="button secondary" href="{{ route('dashboard.overview') }}">Operations dashboard</a>
<a class="button secondary" href="{{ route('broadcast.index') }}">One-way broadcasts, voice library and reports</a>
</nav>
<p>Manage contacts and campaign reviews. Configure and operate approved one-way campaigns from Broadcast operations.</p>
@can('contacts.view')<section id="contacts"><h2>Contacts</h2>
@can('contacts.manage')<form method="post" action="{{ route('contacts.store') }}">@csrf
<label>Name <input name="name" required maxlength="255"></label><label>Phone <input name="phone" placeholder="0241234567" required></label><label>Source <input name="source" required maxlength="255"></label><button>Add contact</button></form>@endcan
@foreach($contacts as $contact)<article><h3>#{{ $contact->id }} {{ $contact->name ?? 'Unnamed contact' }}@include('partials.client-identity',['record'=>$contact]) <small>{{ $contact->phone_e164 }}</small></h3>
@can('contacts.manage')
<p>Groups: {{ $contact->groups->pluck('name')->join(', ') ?: 'None' }}</p>
<form method="post" action="{{ route('contacts.group',$contact) }}">@csrf<label>Group name <input name="name" required maxlength="255"></label><button>Add to group</button></form>
<details><summary>Record consent evidence</summary><form method="post" action="{{ route('contacts.consent',$contact) }}">@csrf
@foreach(['purpose'=>'Purpose','legal_basis'=>'Legal basis','source'=>'Source','evidence_ref'=>'Evidence reference'] as $field=>$label)<label>{{ $label }} <input name="{{ $field }}" required maxlength="255"></label>@endforeach
<label>Granted at <input type="datetime-local" name="granted_at" required></label><button>Record consent</button></form></details>
<form method="post" action="{{ route('contacts.suppress',$contact) }}">@csrf<label>Suppression reason <input name="reason" required maxlength="255"></label><button>Suppress globally</button></form>
@endcan
@foreach($contact->consents as $consent)<p>{{ $consent->purpose }} — {{ $consent->revoked_at ? 'Withdrawn' : 'Granted' }}</p>
@can('contacts.manage')@if(!$consent->revoked_at)<form method="post" action="{{ route('consents.revoke',$consent) }}">@csrf<button>Withdraw consent</button></form>@endif@endcan
@endforeach</article>@endforeach{{ $contacts->links() }}</section>@endcan
@can('campaigns.view')<section id="campaigns"><h2>Campaigns and approvals</h2>
@can('campaigns.manage')<form method="post" action="{{ route('campaigns.store') }}">@csrf
<label>Name <input name="name" required maxlength="255"></label><label>Purpose <input name="purpose" required maxlength="255"></label><label>Type <select name="type"><option value="one_way">One-way</option><option value="interactive">Interactive</option></select></label><button>Create draft</button></form>@endcan
<table><thead><tr><th>Campaign</th><th>Status</th><th>Review</th></tr></thead><tbody>@foreach($campaigns as $campaign)<tr><td>{{ $campaign->name }}<br>@include('partials.client-identity',['record'=>$campaign])<small>{{ $campaign->purpose }}</small></td><td>{{ str_replace('_',' ',$campaign->status) }}</td><td>
@if($campaign->status==='draft' && ($campaign->owner_id===auth()->id()||auth()->user()->isSuperAdministrator()) && auth()->user()->can('campaigns.manage'))
<details><summary>Edit draft</summary><form method="post" action="{{ route('campaigns.update',$campaign) }}">@csrf<label>Name <input name="name" value="{{ $campaign->name }}" required maxlength="255"></label><label>Purpose <input name="purpose" value="{{ $campaign->purpose }}" required maxlength="255"></label><input type="hidden" name="type" value="{{ $campaign->type }}"><button>Save draft</button></form></details>@endif
@if(($campaign->status==='draft'  && ($campaign->owner_id===auth()->id()||auth()->user()->isSuperAdministrator()) && auth()->user()->can('campaigns.manage')) || ($campaign->status==='pending_approval' && ($campaign->owner_id!==auth()->id()||auth()->user()->isSuperAdministrator()) && auth()->user()->can('campaigns.approve')))
<form method="post" action="{{ route('campaigns.review',$campaign) }}">@csrf<label>Reason <input name="reason" required maxlength="2000"></label>
@if($campaign->status==='draft')<button name="decision" value="submit">Submit for review</button>@else<button name="decision" value="approve">Approve</button><button name="decision" value="reject">Reject</button>@endif</form>@endif
</td></tr>@endforeach</tbody></table>{{ $campaigns->links() }}</section>@endcan
@can('audit.view')<section><h2>Audit history</h2><table><thead><tr><th>Time (UTC)</th><th>Actor</th><th>Action</th><th>Entity</th></tr></thead><tbody>@foreach($audits as $audit)<tr><td>{{ $audit->created_at }}</td><td>{{ $audit->actor_id }}</td><td>{{ $audit->action }}</td><td>{{ class_basename($audit->entity_type) }} #{{ $audit->entity_id }}</td></tr>@endforeach</tbody></table>{{ $audits->links() }}</section>@endcan
@endsection
