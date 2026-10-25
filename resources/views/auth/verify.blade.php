@extends('auth.shell')
@section('title','Verify contact · VoiceCast')
@section('heading','Check your messages')
@section('description','Enter the six-digit code sent to your chosen contact. Codes expire and can be used once.')
@section('form')
<form method="post" action="{{ route('identity.verify.store') }}">@csrf
<div class="form-field"><label for="code">Verification code</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required autofocus></div>
@include('auth.turnstile',['action'=>'verify'])<button class="button login-submit" type="submit">Verify contact →</button></form>
<details class="identity-resend"><summary>Need another code?</summary><p>Wait at least 60 seconds between requests.</p><form method="post" action="{{ route('identity.resend') }}">@csrf @include('auth.turnstile',['action'=>'resend'])<button class="button" type="submit">Send a new code</button></form></details>
<p class="identity-links"><a href="{{ session('identity.purpose')==='register' ? route('register') : (session('identity.purpose')==='change' ? route('account.security') : (session('identity.purpose')==='activate' ? route('login') : route('password.request'))) }}">Start again with a different contact</a></p>
@endsection
