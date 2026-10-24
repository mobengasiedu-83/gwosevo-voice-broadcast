@extends('layouts.public')
@section('title', 'Terms & Privacy Policy · GWO SEVO VoiceCast')
@section('content')
<link rel="stylesheet" href="{{ asset('css/policies.css') }}">
<header class="policy-header"><a href="{{ url('/') }}">GWO SEVO VoiceCast</a><a href="{{ auth()->check() ? (auth()->user()->isClient() ? route('client.settings') : route('dashboard.overview')) : route('client.register') }}">Back to {{ auth()->check() ? 'your account' : 'registration' }} →</a></header>
<main id="main" class="policy-document">
<p class="policy-kicker">ACCOUNT POLICIES</p><h1>Terms &amp; Privacy Policy</h1>
<p class="policy-placeholder" role="note"><strong>Dummy content for development.</strong> These sample terms and privacy statements are placeholders. Replace them with approved policies before accepting public registrations.</p>
<p>Policy version: {{ config('client_auth.terms_version') }}</p>
<nav aria-label="Policy sections"><a href="#terms">Terms of service</a> · <a href="#privacy">Privacy policy</a></nav>
<section id="terms"><h2>Terms of service</h2>
<h3>1. Your account</h3><p>Sample text: Provide accurate account and business details. Keep your password private and maintain access to your registered email address and mobile number.</p>
<h3>2. Responsible use</h3><p>Sample text: Use voice broadcasting only for authorized communications. Respect recipient consent, suppression requests and applicable requirements. Recordings are subject to the platform’s review process.</p>
<h3>3. Credit and campaigns</h3><p>Sample text: Review the assigned pricing policy and available credit before starting a campaign. Final payment, cancellation, refund and dispute terms will be specified in the approved policy.</p>
<h3>4. Service availability</h3><p>Sample text: Call outcomes depend on network and recipient availability. Final service commitments, account suspension rules and support arrangements remain to be defined.</p>
</section>
<section id="privacy"><h2>Privacy policy</h2>
<h3>1. Information collected</h3><p>Sample text: Account details include your name, organization, email, mobile number and country. Platform records may include uploaded contacts, recordings, campaign results, credit transactions and security events.</p>
<h3>2. How information is used</h3><p>Sample text: Information supports account verification, recovery, authorized broadcasting, account administration and selected service notifications.</p>
<h3>3. Service providers and retention</h3><p>Sample text: Communications may use email, SMS and telephony providers. The approved policy must identify relevant providers, retention periods, safeguards and any international processing arrangements.</p>
<h3>4. Your choices and requests</h3><p>Sample text: Manage optional notifications and verified contact details in Account settings. Approved contact details and procedures for access, correction, deletion and privacy complaints will be added here.</p>
</section>
<p class="policy-placeholder">Placeholder notice: operator identity, contact address, effective date, applicable terms and privacy procedures require completion before launch.</p>
</main>
@endsection
