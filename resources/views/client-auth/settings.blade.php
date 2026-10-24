@extends('clients.layout')
@section('heading', 'Account settings')
@section('description', 'Update your name or password. Confirm your current password to save changes.')
@section('portal-content')
<section class="panel"><h2>Contact verification</h2>
<p>Email: <strong>{{ $user->email ?: 'Not provided' }}</strong> · {{ $user->email_verified_at ? 'Verified' : 'Not verified' }}</p>
<p>Mobile: <strong>{{ $user->phone_e164 ?: 'Not provided' }}</strong> · {{ $user->phone_verified_at ? 'Verified' : 'Not verified' }}</p>
<p>Country: {{ $user->country === 'GH' ? 'Ghana' : 'Not recorded' }}</p>
<p>Verify your second contact to enable recovery and optional alerts through it. You can sign in using either saved contact and your password.</p>
<form method="post" action="{{ route('client.settings.contact') }}">@csrf
<div class="form-field"><label for="channel">Verify or replace</label><select id="channel" name="channel" required><option value="email">Email address</option><option value="phone">Ghana mobile number</option></select></div>
<div class="form-field"><label for="contact">Contact to verify</label><input id="contact" name="contact" value="{{ old('contact') }}" maxlength="255" required><p>Enter your saved contact to verify it, or a new contact to replace it after verification.</p></div>
<div class="form-field"><label for="contact_password">Current password</label><input id="contact_password" name="current_password" type="password" autocomplete="current-password" required></div>
<button class="button">Send verification code</button><p>Five-minute expiry · 60-second cooldown · three codes per hour.</p></form>
@if(session()->has('client.contact_verification'))
<form method="post" action="{{ route('client.settings.contact.verify') }}">@csrf
<div class="form-field"><label for="contact_code">Verification code</label><input id="contact_code" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></div><button class="button">Verify and save contact</button></form>
@endif
</section><p>Account: {{ $user->clientAccount->name }}</p>
<form method="post" action="{{ route('client.settings.update') }}">@csrf
<div class="form-field"><label for="name">Your name</label><input id="name" name="name" value="{{ old('name', $user->name) }}" maxlength="255" autocomplete="name" required></div>
<div class="form-field"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
<div class="form-field"><label for="password">New password (optional)</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="128"><p>At least 12 characters, uppercase and lowercase letters, a number and a symbol.</p></div>
<div class="form-field"><label for="password_confirmation">Confirm new password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" maxlength="128"></div>
<button class="button login-submit">Save changes →</button></form>

@endsection
