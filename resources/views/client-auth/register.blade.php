@extends('client-auth.layout')
@section('heading', 'Create your account')
@section('description', 'Provide both contact details, choose one to verify, then create your password.')
@section('form')
<form method="post" action="{{ route('client.register.start') }}">@csrf
<div class="form-field"><label for="name">Your name</label><input id="name" name="name" value="{{ old('name') }}" autocomplete="name" maxlength="255" required></div>
<div class="form-field"><label for="organization">Organization / account name</label><input id="organization" name="organization" value="{{ old('organization') }}" autocomplete="organization" maxlength="255" required></div>
<div class="form-field"><label for="email">Email address</label><input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" maxlength="255" required></div>
<div class="form-field"><label for="phone">Mobile number</label><input id="phone" name="phone" type="tel" value="{{ old('phone') }}" autocomplete="tel" placeholder="0241234567" maxlength="30" required></div>
<div class="form-field"><label for="country">Country</label><select id="country" name="country" required><option value="GH">Ghana</option></select><p>Registration currently supports Ghana mobile numbers.</p></div>
<div class="form-field"><label for="verification_channel">Send my verification code by</label><select id="verification_channel" name="verification_channel" required><option value="email" @selected(old('verification_channel') === 'email')>Email OTP</option><option value="phone" @selected(old('verification_channel') === 'phone')>SMS OTP</option></select><p>Verify one contact to activate your account. You can verify the other in Account settings.</p></div>
<div class="form-field"><label><input type="checkbox" name="terms" value="1" @checked(old('terms')) required> I accept the Terms and Privacy Policy.</label>
<a href="{{ config('client_auth.terms_url') ?: route('legal.policies').'#terms' }}" target="_blank" rel="noopener">Terms</a>
<a href="{{ config('client_auth.privacy_url') ?: route('legal.policies').'#privacy' }}" target="_blank" rel="noopener">Privacy Policy</a>
</div>
<button class="button login-submit">Send verification code →</button></form>

@endsection
