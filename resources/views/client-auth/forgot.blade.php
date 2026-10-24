@extends('client-auth.layout')
@section('heading', 'Forgot your password?')
@section('description', 'Enter your verified client email or phone number. We will send a single-use recovery link.')
@section('form')
<form method="post" action="{{ route('client.password.email') }}">@csrf<div class="form-field"><label for="identifier">Email or phone number</label><input id="identifier" name="identifier" value="{{ old('identifier') }}" autocomplete="username" maxlength="255" required></div><button class="button login-submit">Send recovery link →</button></form>

@endsection
