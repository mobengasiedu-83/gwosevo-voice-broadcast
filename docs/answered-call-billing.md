# Recording-length billing and CDR

Once a call is answered, the full selected recording is charged immediately:

`max(1, ceil(recording_duration_ms / 60000)) × frozen rate per minute`

A 62-second recording therefore costs two minutes, even if the caller hangs up immediately. Call duration, playback completion, playback failure, prompts and delays do not change the charge. Unanswered calls cost zero. Each answered retry is a separate charge.

The recording length is frozen in `call_attempts.recording_duration_ms` from `broadcast.audio_duration_ms` in the immutable approved campaign version. For older versions without that field, migration and attempt creation recover the measured length only when the audio checksum matches the approved checksum. Replacing an audio file or editing a campaign cannot reprice that approved version. A missing measurement opens `recording_duration_missing`; billing never substitutes talk time or invents a recording length. A valid frozen tariff and matching wallet currency remain required.

The full recording payment uses the append-only `answer_charge` ledger type and `billing:answer:{attempt_id}` key. `answer_paid_minor` and `answer_reserved_minor` track payment and hold consumption. At hangup a final rating with policy `recording_minute_v1` stores recording milliseconds, rounded recording minutes and total cost. Settlement deducts only any unpaid balance and releases unused credit; it does not charge the recording again. Insufficient credit creates a finance shortfall without a negative wallet balance. Internal campaigns without a client remain outside client wallets.

Actual answer-to-hangup duration is retained separately in timing evidence and CDR `time_spent_ms`. The CDR page and CSV also show `recording_duration_ms` and billing basis. Historical final ratings retain their original policy and financial history; they are not silently repriced. Legacy provider imports for attempts without an approved campaign version retain their historical call-duration policy.

`php artisan billing:finalize-answered` previews missed answered calls; `--apply` charges and settles them. The scheduler runs recovery every minute. Hold cleanup recovers answered-call billing instead of releasing the recording charge for free. Existing answer-payment and settlement idempotency keys prevent replays from duplicating a deduction.

The CDR is at `/reports/cdr` and `/reports/cdr.csv`, under `reports.view`. Account scoping applies to both. Filters cover MSISDN, campaign ID, UTC date range and answer status. Records include attempt/campaign IDs, MSISDN, state/outcome, send/answer/end times, actual milliseconds/seconds, recording milliseconds, billing basis, billed minutes, charge, paid amount, shortfall, currency and billing state. An active call shows the full recording cost and its actual elapsed duration at report time. CSV text is protected against spreadsheet formula execution.
