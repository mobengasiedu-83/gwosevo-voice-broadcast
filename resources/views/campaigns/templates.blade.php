@extends('foundation.layout')
@section('title','Campaign templates')
@section('content')
<h2>Campaign templates</h2><p>Immutable configuration blueprints. Save a new version from a campaign; approvals and execution audiences are never carried over.</p>@include('campaigns.nav')
<section>@forelse($templates as $t)<article><h3>{{ $t->name }}@include('partials.client-identity',['record'=>$t]) <span class="pill">Version {{ $t->version }}</span></h3><p>{{ $t->blueprint['purpose'] }} · {{ $t->blueprint['type'] }} · {{ $t->created_at->format('d M Y H:i') }} UTC</p><details><summary>Review blueprint</summary><pre>{{ json_encode($t->blueprint,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre></details>@can('campaigns.manage')<form method="post" action="{{ route('campaigns.instantiate',$t) }}">@csrf<label>New campaign name<input name="name" required maxlength="255" value="{{ $t->name }}"></label><button>Use this version</button></form>@endcan</article>@empty<p>No templates yet. Open a campaign and save its configuration as a template.</p>@endforelse{{ $templates->links() }}</section>
@endsection
