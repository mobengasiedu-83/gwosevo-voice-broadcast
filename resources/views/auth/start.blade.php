@extends('auth.shell')
@section('title', $purpose==='register'?'Create account · VoiceCast':'Recover account · VoiceCast')
@section('heading', $purpose==='register'?'Create your account':'Recover your account')
@section('description', 'Choose a contact to verify. We’ll send you a short-lived verification code.')
@section('form')
<form method="post" action="{{ $purpose==='register' ? route('register.store') : route('password.email') }}">@csrf
@if($purpose==='register')
<div class="form-field"><label for="name">Full name</label><input id="name" name="name" maxlength="255" autocomplete="name" required value="{{ old('name') }}"></div>
<div class="form-field"><label for="account_name">Account or business name</label><input id="account_name" name="account_name" maxlength="255" autocomplete="organization" required value="{{ old('account_name') }}"></div>
<div class="form-field"><label for="country">Country</label><input id="country" name="country" maxlength="80" autocomplete="country-name" required value="{{ old('country','Ghana') }}"></div>
<div class="form-field"><label for="broadcasting_purpose">What will you use voice broadcasts for?</label><textarea id="broadcasting_purpose" name="broadcasting_purpose" maxlength="1000" required>{{ old('broadcasting_purpose') }}</textarea></div>
<div class="form-field"><label for="email">Email address</label><input id="email" type="email" name="email" maxlength="254" autocomplete="email" required value="{{ old('email') }}" placeholder="you@example.com"><p class="field-note">Saved as a sign-in and recovery contact for this account.</p></div>
<div class="form-field"><label for="phone">Mobile number</label><input id="phone" type="tel" name="phone" maxlength="32" autocomplete="tel" required value="{{ old('phone') }}" placeholder="+233241234567"><p class="field-note">Include the country code; Ghana numbers such as 024 123 4567 are also accepted.</p></div>
@endif
<div class="form-field"><label for="channel">{{ $purpose==='register' ? 'Send the verification code to' : 'Verification method' }}</label><select id="channel" name="channel"><option value="email" @selected(old('channel','email')==='email')>{{ $purpose==='register' ? 'Email address' : 'Email code' }}</option>@if(config('client_auth.sms_driver')==='txtconnect')<option value="sms" @selected(old('channel')==='sms')>{{ $purpose==='register' ? 'Mobile number' : 'SMS code' }}</option>@endif</select></div>
@if($purpose!=='register')
<div class="form-field"><label for="identifier">Email address or mobile number</label><input id="identifier" name="identifier" maxlength="254" autocomplete="username" required value="{{ old('identifier') }}" placeholder="you@example.com or +233241234567"></div>
@endif
@if($purpose==='register')<label class="identity-check"><input type="checkbox" name="terms" value="1" required @checked(old('terms'))> I agree to the <a href="{{ route('terms') }}" target="_blank" rel="noopener">terms and privacy notice</a>.</label>@endif
@include('auth.turnstile',['action'=>$purpose==='register'?'register':'recovery'])
<button class="button login-submit" type="submit">Send verification code →</button>
</form>
@endsection
