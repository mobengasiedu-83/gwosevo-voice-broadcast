@php($selectedRetryOutcomes = old('retry_policy_configured') ? old('retry_outcomes', []) : ($config['retry_outcomes'] ?? ['busy','no_answer','failed']))
<fieldset><legend>Retry reasons and excluded dates</legend>
<input type="hidden" name="retry_policy_configured" value="1">
<label for="retry-outcomes">Retry only these outcomes (select none for no retries)</label>
<select id="retry-outcomes" name="retry_outcomes[]" multiple size="3">@foreach(['busy'=>'Busy','no_answer'=>'No answer','failed'=>'Failed before delivery'] as $value=>$label)<option value="{{ $value }}" @selected(in_array($value, $selectedRetryOutcomes))>{{ $label }}</option>@endforeach</select>
<p>Uncertain outcomes, completed playback, opt-outs and successful transfers are never retried automatically. Delay increases exponentially within the existing attempt limit.</p>
<label for="excluded-dates">Holiday / excluded dates in campaign timezone</label><textarea id="excluded-dates" name="excluded_dates_text" rows="3" placeholder="2026-12-25, 2027-01-01">{{ old('excluded_dates_text', implode(', ', $config['excluded_dates'] ?? [])) }}</textarea>
<label for="expires-on">Last permitted calling date (optional)</label><input id="expires-on" name="expires_on" type="date" value="{{ old('expires_on', $config['expires_on'] ?? '') }}">
<p>Enter explicit dates; no national holiday calendar is assumed. Exclusions and expiry are frozen when launched and checked before new calls.</p>
</fieldset>
