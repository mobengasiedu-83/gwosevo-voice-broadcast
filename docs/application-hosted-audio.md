# Application-hosted audio playback

Recordings remain in private application storage. Asterisk downloads an approved PCM WAV over HTTP(S) when a call needs it, then plays it through the existing configured carrier trunk. Asterisk may keep a temporary media cache; there is no per-recording PBX copy or AstDB audio-checksum installation step in URL mode.

## Application configuration

Set these in .env:

    VOICE_BROADCAST_MEDIA_MODE=url
    VOICE_BROADCAST_MEDIA_BASE_URL=https://voice.example.com/gwosevo-voice-broadcast/public

Use the actual application base address accessible FROM VitalPBX, including the deployment path. Do not use localhost. HTTPS with a certificate trusted by VitalPBX is recommended. No browser login, redirects or self-signed certificate exceptions are required by the media endpoint.

Run php artisan config:clear (or rebuild your deployment's config cache) and restart the broadcast gateway after configuring. Switching delivery modes invalidates previously launched snapshots; use a new campaign execution for those runs. Unlaunched Ready campaigns need no new audio approval.

The application's .env selects URL mode with the planned deployment base URL http://173.16.254.253:85. This is a future deployment address, not a verified reachable service. Deploy at that address and complete the PBX setup before starting calls. If deployment uses a subdirectory, append that path to the base URL. The local development server is not automatically published at this address.

## One-time VitalPBX setup

1. Confirm HTTP media and WAV modules are available using the Asterisk CLI: module show like res_http_media_cache and module show like format_wav. Check res_curl dependencies if HTTP media support is absent.
2. Install deploy/asterisk/gwosevo-broadcast-url.conf through VitalPBX's supported custom dialplan mechanism. Include its context without exposing it to public/inbound routing. Keep the original context if old local-media runs still need it. Use dialplan reload, then dialplan show gwosevo-broadcast-url to verify.
3. Ensure VitalPBX can resolve and reach the application's HTTP(S) server. An unauthenticated request to a made-up /pbx-media/... URL must return 404, not a login page or redirect.
4. For interactive campaigns, download and install the regenerated approved IVR dialplan and record its actual new checksum. The compiler now generates protected URL playback and Read prompts; IVR dialplan deployment is still required.
5. Perform a controlled call and verify playback-start/completion events. No call has been placed automatically by this implementation.

## Access controls

Each URL contains a random existing per-attempt token, attempt UUID, approved audio UUID and frozen SHA-256. Only a sent, unfinished attempt less than 15 minutes old can fetch assets listed in its immutable launch snapshot. Wrong tokens, unrelated audio, changed checksums, unapproved/missing files and ended/expired/unsent attempts return 404. IVR access is limited to that flow's frozen assets. Existing one-time AstDB call authorization remains in the dialplan.

Treat full media URLs as credentials: redact /pbx-media/ request paths in reverse-proxy/access logs and avoid sharing SIP/AMI logs containing token variables. Responses request no storage/revalidation. The URL capability expires at the application; already downloaded PBX bytes cannot be recalled.

HTTP failure produces the existing playback-failed event and hangup; it never reports playback completion. No automatic fall-back to an unrelated recording or trunk occurs.

## References

- https://docs.asterisk.org/Certified-Asterisk_20.7_Documentation/API_Documentation/Dialplan_Applications/Playback/
- https://docs.asterisk.org/Certified-Asterisk_20.7_Documentation/API_Documentation/Module_Configuration/res_http_media_cache/
