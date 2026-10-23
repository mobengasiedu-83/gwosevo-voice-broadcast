@extends('foundation.layout')
@section('title','Lists & imports')
@section('content')
<h2>Lists & imports</h2><p>Upload, map and review contacts before adding them to a group.</p>@include('contacts.nav')
@include('contacts.upload',['uploadGroups'=>$groups])
<section><h2>Import history</h2><div class="table-scroll contact-table"><table><thead><tr><th>File</th><th>Status</th><th>Rows</th><th>Linked</th><th>Duplicates</th><th>Invalid</th><th>Created</th></tr></thead><tbody>@forelse($batches as $b)<tr><td><a href="{{ route('contacts.import',$b) }}">{{ $b->filename }}</a></td><td><span class="pill">{{ $b->status }}</span></td><td>{{ $b->total }}</td><td>{{ $b->imported }}</td><td>{{ $b->duplicates }}</td><td>{{ $b->invalid }}</td><td>{{ $b->created_at }} UTC</td></tr>@empty<tr><td colspan="7">No imports yet.</td></tr>@endforelse</tbody></table></div>{{ $batches->links() }}</section>
@endsection
