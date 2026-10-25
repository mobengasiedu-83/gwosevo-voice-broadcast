@extends('client-auth.layout')
@section('heading', 'Accept client invitation')
@section('form')
<h1>Accept client invitation</h1><p>{{ $invitation->name }}, set your password for {{ $invitation->email }}. Your assigned client role is {{ str_replace('_',' ',$invitation->role) }}.</p>
@if($errors->any())<ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
<form method="post" action="{{ route('client.invitation.accept',compact('uuid','token')) }}">@csrf<label>Password<input type="password" name="password" autocomplete="new-password" required minlength="12"></label><label>Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" required></label><p>Use at least 12 characters, upper/lowercase letters, a number and a symbol.</p><label><input type="checkbox" name="terms" value="1" required> I accept the <a href="{{ route('legal.policies') }}" target="_blank" rel="noopener noreferrer">Terms and Privacy Policy</a>.</label><button>Accept invitation</button></form>
@endsection
