@extends('layouts.public')
@section('title', config('app.name').' · Client account')
@section('body-class', 'login-page')
@section('content')
<div class="login-shell">
<aside class="login-story">@include('partials.brand')<div class="story-content"><span class="story-kicker">GWO SEVO VOICECAST</span><h2>Your message.<br><em>Your people.</em></h2><p>Set up your account and connect with your audience.</p></div></aside>
<main class="login-main" id="main"><a class="back-link" href="{{ auth()->check() ? route('client.foundation') : route('login') }}">← {{ auth()->check() ? 'Back to account' : 'Back to sign in' }}</a><div class="login-form-wrap">
<h1>@yield('heading')</h1><p class="login-description">@yield('description')</p>
@if(session('status'))<div class="form-notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="form-error-summary" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('form')
</div></main></div>
@endsection
