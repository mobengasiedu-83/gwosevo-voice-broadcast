@extends('client-auth.layout')
@section('heading', $challenge->verified_at ? 'Create your password' : 'Check your messages')
@section('description', $challenge->verified_at ? 'Your contact is verified. Use this password for future sign-ins.' : 'Enter the six-digit code sent to '.$challenge->identifier.'.')
@section('form')
@if($challenge->verified_at)
<form method="post" action="{{ route('client.register.finish') }}">@csrf @include('client-auth.password-fields')<button class="button login-submit">Create account →</button></form>
@else
<form method="post" action="{{ route('client.register.check') }}">@csrf<div class="form-field"><label for="code">Verification code</label><input id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus></div><button class="button login-submit">Verify code →</button></form>
<form method="post" action="{{ route('client.register.resend') }}" style="margin-top:1rem">@csrf<button class="button" type="submit">Resend code</button><p>Wait 60 seconds between requests. Codes expire after 10 minutes.</p></form>
@endif
<p><a href="{{ route('client.register') }}">Start again with a different email or number</a></p>

@endsection
