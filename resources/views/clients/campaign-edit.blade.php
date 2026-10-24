@extends('clients.layout')
@section('heading', $campaign ? 'Edit campaign' : 'Create campaign')
@section('description', 'Choose your audience and approved recording, then set when recipients may be called.')
@section('portal-content')
<form class="panel campaign-wizard" method="post" action="{{ $campaign ? route('client.campaigns.save', $campaign) : route('client.campaigns.store') }}">
@csrf
@php($config = $campaign?->configuration ?? [])
<fieldset><legend>1 · Message and audience</legend>
<div class="form-field"><label for="name">Campaign name</label><input id="name" name="name" required maxlength="240" value="{{ old('name', $campaign?->name) }}"></div>
<div class="form-field"><label for="purpose">Purpose</label><input id="purpose" name="purpose" required maxlength="255" value="{{ old('purpose', $campaign?->purpose) }}"></div>
<div class="form-field"><label for="source_group_id">Contact group</label><select id="source_group_id" name="source_group_id" required><option value="">Choose an active group</option>@foreach($groups as $group)<option value="{{ $group->id }}" @selected(old('source_group_id', $config['source_group_id'] ?? '') == $group->id)>{{ $group->name }} ({{ $group->contacts_count }} contacts)</option>@endforeach</select></div>
<p>Saving captures the current group audience. DND exclusions are checked again before dialing.</p>
<div class="form-field"><label for="audio_id">Approved recording</label><select id="audio_id" name="audio_id" required><option value="">Choose approved audio</option>@foreach($audio as $recording)<option value="{{ $recording->id }}" @selected(old('audio_id', $config['audio_id'] ?? '') == $recording->id)>{{ $recording->name }} ({{ $recording->duration }} seconds)</option>@endforeach</select></div>
<p>Need a recording? <a href="{{ route('client.audio') }}">Upload audio and wait for internal approval.</a></p>
</fieldset>
<fieldset><legend>2 · Schedule and calling window</legend>
<div class="form-field"><label for="timezone">Timezone</label><select id="timezone" name="timezone" required>@foreach(timezone_identifiers_list() as $zone)<option @selected(old('timezone', $campaign?->timezone ?? 'Africa/Accra') === $zone)>{{ $zone }}</option>@endforeach</select></div>
<div class="form-field"><label for="scheduled_at">Start time in selected timezone (optional)</label><input type="datetime-local" id="scheduled_at" name="scheduled_at" value="{{ old('scheduled_at', empty($config['scheduled_at']) ? '' : \Carbon\CarbonImmutable::parse($config['scheduled_at'])->format('Y-m-d\TH:i')) }}"></div>
<p>Leave blank to start when you confirm launch. A future schedule is armed only after you click Start.</p>
<div class="form-field"><label for="window_start">Daily calling window starts</label><input type="time" id="window_start" name="window_start" required value="{{ old('window_start', $config['window_start'] ?? '08:00') }}"></div>
<div class="form-field"><label for="window_end">Daily calling window ends</label><input type="time" id="window_end" name="window_end" required value="{{ old('window_end', $config['window_end'] ?? '18:00') }}"></div>
</fieldset>
<fieldset><legend>3 · Retry settings</legend>
<div class="form-field"><label for="max_attempts">Attempts per recipient including first call</label><input type="number" id="max_attempts" name="max_attempts" min="1" max="5" required value="{{ old('max_attempts', $config['max_attempts'] ?? 1) }}"></div>
<div class="form-field"><label for="retry_delay_seconds">Initial retry delay in seconds</label><input type="number" id="retry_delay_seconds" name="retry_delay_seconds" min="60" max="86400" required value="{{ old('retry_delay_seconds', $config['retry_delay_seconds'] ?? 300) }}"></div>
<p>Busy, no-answer and failed calls share this retry policy. The delay doubles after each failure, up to one day.</p>
</fieldset>
<button class="button">Save and review campaign</button> <a href="{{ route('client.campaigns') }}">Back to campaigns</a>
</form>
@endsection
