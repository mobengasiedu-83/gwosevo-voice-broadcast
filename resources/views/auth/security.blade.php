@extends('dashboard.layout')
@section('title','Account security')
@section('content')
<h1>Account security</h1>
<nav class="workspace-actions" aria-label="Account shortcuts">
@if(auth()->user()->clientMembership()->exists())<a class="button secondary" href="{{ route('account.profile') }}">Account profile</a>@endif
<a class="button secondary" href="{{ route('dashboard.overview') }}">Dashboard</a>
</nav>
<section><h2>Update your sign-in contact</h2><p>Confirm your password and verify the new contact before it replaces your sign-in details.</p>
<p>Email: {{ auth()->user()->email ?: 'Not added' }} · {{ auth()->user()->email_verified_at ? 'Verified' : 'Not verified' }}</p>
<p>Phone: {{ auth()->user()->phone_e164 ?: 'Not added' }} · {{ auth()->user()->phone_verified_at ? 'Verified' : 'Not verified' }}</p>
<form method="post" action="{{ route('account.contact') }}">@csrf
<div class="form-field"><label for="channel">Contact to add or replace</label><select id="channel" name="channel"><option value="email">Email</option>@if(config('client_auth.sms_driver')==='txtconnect')<option value="sms">Mobile phone</option>@endif</select></div>
<div class="form-field"><label for="identifier">New contact</label><input id="identifier" name="identifier" maxlength="254" autocomplete="username" required value="{{ old('identifier') }}"></div>
<div class="form-field"><label for="current_password">Current password</label><input id="current_password" type="password" name="current_password" autocomplete="current-password" required></div>
@include('auth.turnstile',['action'=>'identity'])<button class="button login-submit" type="submit">Verify new contact →</button></form>
</section>
<section><h2>Change password</h2><p>Changing your password also revokes your other sessions.</p><form method="post" action="{{ route('account.security.save') }}" class="stack-form">@csrf<input type="hidden" name="action" value="password"><label>Current password<input type="password" name="current_password" required autocomplete="current-password" maxlength="128"></label><label>New password<input type="password" name="password" required autocomplete="new-password" minlength="12" maxlength="128"></label><label>Confirm new password<input type="password" name="password_confirmation" required autocomplete="new-password" maxlength="128"></label><p>Use at least 12 characters with upper/lowercase letters, a number and a symbol.</p>@include('auth.turnstile',['action'=>'identity'])<button>Change password</button></form></section>
<section><h2>Sign out other sessions</h2><p>Keep this session and require your other devices to sign in again.</p><form method="post" action="{{ route('account.security.save') }}" class="stack-form">@csrf<input type="hidden" name="action" value="sessions"><label>Current password<input type="password" name="current_password" required autocomplete="current-password" maxlength="128"></label>@include('auth.turnstile',['action'=>'identity'])<button>Sign out other sessions</button></form></section>
@endsection
