@extends('dashboard.layout')
@section('title','Account security')
@section('content')
<div class="contact-heading">
    <div>
        <h1>Account security</h1>
        <p>Email or SMS codes verify a new sign-in contact before it replaces the current one. Every change here is confirmed with your password.</p>
    </div>
    @if(auth()->user()->clientMembership()->exists())
    <div class="contact-heading-actions">
        <a class="button secondary" href="{{ route('account.profile') }}">Account profile</a>
        <a class="button secondary" href="{{ route('dashboard.overview') }}">Dashboard</a>
    </div>
    @else
    <div class="contact-heading-actions"><a class="button secondary" href="{{ route('dashboard.overview') }}">Dashboard</a></div>
    @endif
</div>
<section>
<h2>Update your sign-in contact</h2>
<p>Confirm your password and verify the new contact before it replaces your sign-in details.</p>
<p>Email: {{ auth()->user()->email ?: 'Not added' }} · {{ auth()->user()->email_verified_at ? 'Verified' : 'Not verified' }}</p>
<p>Phone: {{ auth()->user()->phone_e164 ?: 'Not added' }} · {{ auth()->user()->phone_verified_at ? 'Verified' : 'Not verified' }}</p>
<form method="post" action="{{ route('account.contact') }}">@csrf
<div class="field-grid">
    <div class="form-field"><label for="channel">Contact to add or replace</label><select id="channel" name="channel"><option value="email">Email</option>@if(config('client_auth.sms_driver')==='txtconnect')<option value="sms">Mobile phone</option>@endif</select></div>
    <div class="form-field"><label for="identifier">New contact</label><input id="identifier" name="identifier" maxlength="254" autocomplete="username" required value="{{ old('identifier') }}"></div>
    <div class="form-field"><label for="current_password">Current password</label><input id="current_password" type="password" name="current_password" autocomplete="current-password" required></div>
    @include('auth.turnstile',['action'=>'identity'])
</div>
<div class="form-actions"><button class="button" type="submit">Verify new contact →</button></div>
</form>
</section>
<section>
<h2>Change password</h2>
<p>Changing your password also revokes your other sessions.</p>
<form method="post" action="{{ route('account.security.save') }}">@csrf
<input type="hidden" name="action" value="password">
<div class="field-grid">
    <label class="form-field-wide">Current password<input type="password" name="current_password" required autocomplete="current-password" maxlength="128"></label>
    <label>New password<input type="password" name="password" required autocomplete="new-password" minlength="12" maxlength="128"></label>
    <label>Confirm new password<input type="password" name="password_confirmation" required autocomplete="new-password" maxlength="128"></label>
</div>
<p>Use at least 12 characters with upper/lowercase letters, a number and a symbol.</p>
<div class="form-actions">@include('auth.turnstile',['action'=>'identity'])<button class="button" type="submit">Change password</button></div>
</form>
</section>
<section>
<h2>Sign out other sessions</h2>
<p>Keep this session and require your other devices to sign in again.</p>
<form method="post" action="{{ route('account.security.save') }}">@csrf
<input type="hidden" name="action" value="sessions">
<div class="form-actions">
    <label>Current password<input type="password" name="current_password" required autocomplete="current-password" maxlength="128"></label>
    @include('auth.turnstile',['action'=>'identity'])
    <button class="button secondary" type="submit">Sign out other sessions</button>
</div>
</form>
</section>
@endsection
