@extends('layouts.public')
@section('title', 'Sign in · GWO SEVO')
@section('body-class', 'login-page')
@section('content')
<div class="login-shell">
<aside class="login-story">@include('partials.brand')<div class="story-content"><span class="story-kicker">GOOD COMMUNICATION STARTS WITH CARE.</span><h2>A voice that<br>brings people<br><em>closer.</em></h2><p>Your people, your messages and your next campaign. All in one place.</p><div class="story-wave" aria-hidden="true">@foreach([15,30,22,50,36,70,48,90,60,100,73,42,63,80,51,69,40,58,30,45,22,33,16] as $height)<i style="--bar:{{ $height }}%"></i>@endforeach</div></div><p class="story-footer"><span class="story-dot"></span> GWO SEVO · Voice broadcasting</p><div class="story-orbit" aria-hidden="true"></div></aside>
<main class="login-main" id="main"><a class="back-link" href="{{ url('/') }}"><span aria-hidden="true">←</span> Back to home</a><div class="login-mobile-brand">@include('partials.brand')</div><div class="login-form-wrap"><p class="eyebrow">YOUR WORKSPACE AWAITS</p><h1>Welcome back.</h1><p class="login-description">Sign in to pick up where you left off.</p>
@if(session('status'))<div class="form-notice" role="status">{{ session('status') }}</div>@endif
@if($errors->has('broadcast') || $errors->has('throttle'))<div class="form-error-summary" role="alert">{{ $errors->first() }}</div>@endif
<form method="post" action="{{ route('login.store') }}" id="login-form">@csrf
<div class="form-field"><label for="email">Email address</label><input id="email" type="email" name="email" value="{{ old('email') }}" placeholder="you@organization.com" autocomplete="username" required autofocus @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>@error('email')<p id="email-error" class="field-error" role="alert">{{ $message }}</p>@enderror</div>
<div class="form-field"><label for="password">Password</label><div class="password-field"><input id="password" type="password" name="password" placeholder="Enter your password" autocomplete="current-password" required @error('password') aria-invalid="true" aria-describedby="password-error" @enderror><button type="button" class="password-toggle" data-password-toggle aria-controls="password" aria-label="Show password" aria-pressed="false" hidden><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" stroke="currentColor" stroke-width="1.5"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.5"/></svg></button></div>@error('password')<p id="password-error" class="field-error" role="alert">{{ $message }}</p>@enderror</div>
<button class="button login-submit" type="submit"><span data-submit-label>Sign in to workspace</span><span aria-hidden="true" data-submit-arrow>↗</span></button>
</form><div class="access-note"><span class="access-icon" aria-hidden="true">↳</span><p>Need access or help signing in?<br><strong>Contact your organization’s administrator.</strong></p></div></div><footer class="login-footer"><span>© {{ date('Y') }} GWO SEVO</span><span>Made for meaningful connections.</span></footer></main>
</div>
@endsection
