@extends('auth.shell')
@section('title','Set password · VoiceCast')
@section('heading','Choose your password')
@section('description','Use at least 12 characters with uppercase and lowercase letters, a number and a symbol.')
@section('form')
<form method="post" action="{{ route('identity.password.store') }}">@csrf
<div class="form-field"><label for="password">New password</label><input type="password" id="password" name="password" minlength="12" maxlength="128" autocomplete="new-password" required></div>
<div class="form-field"><label for="password_confirmation">Confirm password</label><input type="password" id="password_confirmation" name="password_confirmation" minlength="12" maxlength="128" autocomplete="new-password" required></div>
@include('auth.turnstile',['action'=>'password'])<button class="button login-submit" type="submit">Save password →</button></form>
@endsection
