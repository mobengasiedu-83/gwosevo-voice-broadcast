@if(config('turnstile.enabled'))
<div class="turnstile-wrap"><div class="cf-turnstile" data-sitekey="{{ config('turnstile.site_key') }}" data-action="{{ $action }}" data-theme="auto" data-size="compact"></div></div>
@once<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>@endonce
@endif
