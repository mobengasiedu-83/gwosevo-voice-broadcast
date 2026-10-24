# Step 06 — Client audio library and internal approval

## Delivered

Clients can open **Audio library** at `/client/audio` to upload recordings, preview/download them privately, filter by name or Pending/Approved/Rejected status and read rejection reasons. Status counts and recording duration/review dates are scoped to the active client account.

- New uploads always start **Pending**, regardless of submitted status, owner/account or approval fields.
- The existing upload service validates and canonicalizes PCM WAV audio: mono, 8 kHz, 16-bit, up to the configured byte/duration limits (currently 10,000,000 bytes and 600 seconds). Invalid containers, unsupported encoding and oversized/overlong audio are rejected. MP3 and other formats must first be converted; no automatic transcoding is introduced here.
- Files remain on the private local disk using generated identifiers. No public storage URL, disk path, checksum, PBX token, trunk or caller-ID configuration is exposed by the client library.
- Preview/download require authentication, an active client account and matching ownership. The file must exist and match its recorded checksum. Previews support byte ranges for seeking; responses use private/no-store caching and explicit audio MIME/nosniff headers.
- Active colleagues in the same client account share the account's library, as they do contacts and groups.
- Existing internal reviewers retain exclusive approval/rejection rights. The internal library identifies each client recording's account. No internal audio permission is granted to the Client role.
- Approval/rejection continues to use the existing transactional service and audit trail. It creates the Step 04 in-app notice. Approval moves linked draft campaigns to Ready **only within the recording's account**; readiness is not proof that launch or delivery will succeed.
- Rejected audio can be resubmitted for internal review if its file/checksum are intact. The client cannot edit a review decision. A repeated resubmission of a Pending/Approved recording is rejected.
- To correct audio content, upload a new recording. This creates a separate Pending asset rather than overwriting approved files or immutable campaign snapshots. Resubmission of the same file preserves its bytes/checksum.
- Account suspension and cross-account access restrictions apply to listing, upload, preview, download and resubmission. Clients cannot open internal review/deployment actions, even if they submit approval/ownership fields.
- Client upload/resubmission requests are rate limited; all audio validation and existing global call-admission rules remain in place.

## Routes

- `GET /client/audio` — library, filters and upload form.
- `POST /client/audio` — upload Pending audio.
- `GET /client/audio/{audio}/preview` — authenticated inline WAV.
- `GET /client/audio/{audio}/download` — authenticated file download.
- `POST /client/audio/{audio}/resubmit` — return rejected audio to Pending review.

No client approval endpoint exists. Internal staff continue using **Audio → Audio library**. A rejected recording's reason is also available in client notifications. Client campaign creation/launch is Step 07.

## Deployment

No migration or role-seeder change is required. Deploy the controller, shared audio service/model changes, client/internal templates, routes/middleware and stylesheet. Refresh application caches and long-running processes through the normal procedure:

```bash
cd /var/www/gwosevo-voice-broadcast
sudo -u gwosevo php artisan optimize
sudo -u gwosevo php artisan horizon:terminate
```

Ensure the application process can write to private storage and the web server accepts the configured upload size (`upload_max_filesize`, `post_max_size` and Apache/request limits). Use the existing ownership/permissions setup; do not make private audio publicly accessible or broadly writable.

Upload and review are synchronous. Step 06 does not send SMS/email, place calls, copy recordings to VitalPBX or change its configuration. Application-hosted protected playback remains governed by the existing media mode and admission checks. Production media acceptance tasks remain in `reminder.md`.

## Verification

Tests cover Pending-only creation/field-injection rejection, private storage and media headers, account isolation, status/search filters, review-to-notification/Ready transitions, cross-account readiness protection, rejection/resubmission, corrected-upload artifact preservation, invalid WAV/sample rates, missing/tampered media, byte-range previews, colleague access, suspension and denial of internal review actions. Existing internal audio, integrity and broadcast tests remain the regression baseline. No production recording or call is created by these tests.
