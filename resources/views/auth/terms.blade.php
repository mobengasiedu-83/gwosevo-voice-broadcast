@extends('auth.shell')
@section('title','Registration notice · VoiceCast')
@section('heading','Registration and privacy notice')
@section('description','How this application uses the information you submit when creating an account.')
@section('form')
<div class="identity-notice"><p>Your name, account name, country, broadcasting purpose and selected contact are stored to create and administer your VoiceCast account. The application records the notice version and time you accept it.</p><p>Your chosen email address or mobile number is used to send verification and recovery codes. Only the contact you verify is marked as verified. Passwords are stored as hashes; verification codes expire and cannot be reused.</p><p>Authorized internal administrators can review and manage accounts. Client records are scoped to your account, and sensitive account changes are audited. Registration does not grant permission to approve recordings or administer telephony.</p><p>Only upload contact records and recordings you are authorized to use. Global and account suppression rules and internal recording approval continue to apply.</p><p>Contact your GWO SEVO administrator for the organization’s full service terms, retention policy, privacy enquiries or account removal requests.</p><p>Notice version: {{ config('client_auth.terms_version') }}</p></div>
@endsection
