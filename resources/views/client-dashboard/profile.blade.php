@extends('dashboard.layout')
@section('title','Account profile')
@section('content')
@php($profile = $client->profile ?? [])
<div class="contact-heading">
    <div>
        <h1>Account profile</h1>
        <p>This business profile is shared by members of {{ $client->name }}. Profile contact details do not change verified sign-in contacts.</p>
    </div>
    <a class="button secondary" href="{{ route('account.security') }}">Password &amp; sessions →</a>
</div>
<section>
<h2>Business profile</h2>
<p>These details identify the account on invoices, payment receipts and campaign records. Only the account name is required.</p>
<form method="post" action="{{ route('account.profile.save') }}">@csrf
<div class="field-grid">
    <label>Account name<input name="name" value="{{ old('name', $client->name) }}" required maxlength="255"></label>
    <label>Account type<select name="account_type"><option value="">Not specified</option>@foreach(['individual', 'business'] as $type)<option @selected(old('account_type', $profile['account_type'] ?? '') === $type)>{{ $type }}</option>@endforeach</select></label>
    <label>Business name (optional)<input name="business_name" value="{{ old('business_name', $profile['business_name'] ?? '') }}" maxlength="255"></label>
    <label>Country<input name="country" value="{{ old('country', $profile['country'] ?? '') }}" maxlength="80" autocomplete="country-name"></label>
    <label>Contact person (optional)<input name="contact_name" value="{{ old('contact_name', $profile['contact_name'] ?? '') }}" maxlength="255" autocomplete="name"></label>
    <label>Business phone (optional)<input name="contact_phone" value="{{ old('contact_phone', $profile['contact_phone'] ?? '') }}" maxlength="40" autocomplete="tel"></label>
    <label class="form-field-wide">Business email (optional)<input name="contact_email" type="email" value="{{ old('contact_email', $profile['contact_email'] ?? '') }}" maxlength="254" autocomplete="email"></label>
    <label class="form-field-wide">Address (optional)<textarea name="address" maxlength="1000" rows="3">{{ old('address', $profile['address'] ?? '') }}</textarea></label>
    <label class="form-field-wide">Broadcasting purpose<textarea name="broadcasting_purpose" maxlength="1000" rows="3">{{ old('broadcasting_purpose', $profile['broadcasting_purpose'] ?? '') }}</textarea></label>
</div>
<p>Broadcasting purpose describes the calls this account makes. Telephony partners may ask for it when reviewing the outgoing number.</p>
<div class="form-actions"><button>Save profile</button></div>
</form>
</section>
<section>
<h2>Notification display</h2>
<p>Choose whether the notification bell in the top bar counts your unread notices. The setting applies to your login only.</p>
<form method="post" action="{{ route('account.preferences') }}" class="preference-row">@csrf
<input type="hidden" name="notification_badge" value="0">
<label><input type="checkbox" name="notification_badge" value="1" @checked(auth()->user()->portal_preferences['notification_badge'] ?? true)> Show my unread notice badge</label>
<button class="button secondary">Save preference</button>
</form>
<p>Notices remain in your inbox either way. Campaign email and SMS copies are opt-in on your <a href="{{ route('account.notifications') }}">notifications</a> page and are off by default.</p>
</section>
@endsection
