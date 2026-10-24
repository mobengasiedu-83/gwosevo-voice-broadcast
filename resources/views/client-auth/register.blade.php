@extends('client-auth.layout')
@section('heading', 'Create your account')
@section('description', 'Verify either your email address or Ghana phone number, then create your password.')
@section('form')
<form method="post" action="{{ route('client.register.start') }}">@csrf
<div class="form-field"><label for="name">Your name</label><input id="name" name="name" value="{{ old('name') }}" autocomplete="name" maxlength="255" required></div>
<div class="form-field"><label for="organization">Organization / account name</label><input id="organization" name="organization" value="{{ old('organization') }}" autocomplete="organization" maxlength="255" required></div>
<div class="form-field"><label for="identifier">Email address or phone number</label><input id="identifier" name="identifier" value="{{ old('identifier') }}" autocomplete="username" placeholder="you@example.com or 0241234567" maxlength="255" required><p>We will send a verification code to the address or number you enter.</p></div>
<button class="button login-submit">Send verification code →</button></form>

@endsection
