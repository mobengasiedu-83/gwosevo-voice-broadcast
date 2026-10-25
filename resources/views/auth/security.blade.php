@extends('auth.shell')
@section('title','Account security · VoiceCast')
@section('heading','Update your sign-in contact')
@section('description','Confirm your password, then verify your new contact before it replaces your sign-in details.')
@section('form')
<p>Email: {{ auth()->user()->email ?: 'Not added' }} · {{ auth()->user()->email_verified_at ? 'Verified' : 'Not verified' }}</p>
<p>Phone: {{ auth()->user()->phone_e164 ?: 'Not added' }} · {{ auth()->user()->phone_verified_at ? 'Verified' : 'Not verified' }}</p>
<form method="post" action="{{ route('account.contact') }}">@csrf
<div class="form-field"><label for="channel">Contact to add or replace</label><select id="channel" name="channel"><option value="email">Email</option>@if(config('client_auth.sms_driver')==='txtconnect')<option value="sms">Mobile phone</option>@endif</select></div>
<div class="form-field"><label for="identifier">New contact</label><input id="identifier" name="identifier" maxlength="254" autocomplete="username" required value="{{ old('identifier') }}"></div>
<div class="form-field"><label for="current_password">Current password</label><input id="current_password" type="password" name="current_password" autocomplete="current-password" required></div>
@include('auth.turnstile',['action'=>'identity'])<button class="button login-submit" type="submit">Verify new contact →</button></form>
@endsection
