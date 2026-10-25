@extends('layouts.public')
@section('body-class','identity-page')
@section('content')
<main class="identity-shell" id="main">
<a class="back-link" href="{{ route('login') }}">← Back to sign in</a>
<div class="identity-card">@include('partials.brand')<div class="identity-heading"><p class="eyebrow">YOUR VOICECAST ACCOUNT</p><h1>@yield('heading')</h1><p>@yield('description')</p></div>
@if(session('status'))<div class="form-notice" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="form-error-summary" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('form')
</div></main>
@endsection
