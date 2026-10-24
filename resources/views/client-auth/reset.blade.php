@extends('client-auth.layout')
@section('heading', 'Choose a new password')
@section('description', 'Your recovery link can only be used once. After resetting, sign in with your new password.')
@section('form')
<form method="post" action="{{ route('client.password.update', ['id' => $id, 'token' => $token]) }}">@csrf @include('client-auth.password-fields')<button class="button login-submit">Reset password →</button></form>

@endsection
