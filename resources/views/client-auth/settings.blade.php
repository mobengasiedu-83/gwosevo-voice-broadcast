@extends('clients.layout')
@section('heading', 'Account settings')
@section('description', 'Update your name or password. Confirm your current password to save changes.')
@section('portal-content')
<p>Sign-in contact: <strong>{{ $user->email ?? $user->phone_e164 }}</strong></p><p>Account: {{ $user->clientAccount->name }}</p>
<form method="post" action="{{ route('client.settings.update') }}">@csrf
<div class="form-field"><label for="name">Your name</label><input id="name" name="name" value="{{ old('name', $user->name) }}" maxlength="255" autocomplete="name" required></div>
<div class="form-field"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
<div class="form-field"><label for="password">New password (optional)</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="128"><p>At least 12 characters, uppercase and lowercase letters, a number and a symbol.</p></div>
<div class="form-field"><label for="password_confirmation">Confirm new password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" maxlength="128"></div>
<button class="button login-submit">Save changes →</button></form>

@endsection
