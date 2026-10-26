# Module 12 call reconciliation and minute rating

Implemented against the supplied 25 October 2026 Module 12 guide and AMI Production and Per Minute Billing Specification. The confirmed rule is per attempt: unanswered = 0 minutes; answered = max(1, ceiling(actual answered milliseconds / 60,000)). Pricing, live timing-source validation and playback-failure charging policy require operator approval; this implementation supplies no production tariff or automatically trusted timing source.

## Evidence and rating

Operational AMI receipt timestamps and integer CDR billsec remain available for diagnostics, but cannot establish chargeable duration. Authorized telephony staff can import verified high-precision CDR/CEL evidence through `billing:rate`. Sources must be explicitly configured in `CALL_RATING_TRUSTED_SOURCES` after validating their precision and correlation against the installed PBX/provider.

Each record matches the exact attempt UUID and original own-leg Uniqueid. A supplied Linkedid must also match; a shared Linkedid alone never identifies the billable leg. Answered records require RFC3339 timestamps with timezone and at least three fractional digits. Original timestamp strings are retained canonically in UTC with microseconds. Duration uses integer microsecond subtraction and upward conversion to integer milliseconds; it is never rounded down to seconds. Conflicting supplied duration, answer evidence, chronology or timing creates a hold. A 24-hour import bound rejects implausible records; it is not a commercial maximum-call-duration policy.

Uncertain calls must also pass the existing operational reconciliation gate: fresh complete channel inventory, matching own-leg identity, and no active matching or linked channel. Live/uncertain calls cannot be rated from a claimed CDR alone. Legacy operational reconciliation no longer fabricates an answer timestamp from dispatch time. It can still release a reconciled attempt, but integer-second evidence cannot finalize an answered-call rating.

Authoritative timing records and final call ratings are immutable with a unique row per attempt. Replay does not duplicate a rating. Different evidence does not rewrite prior timing or amounts: it opens a conflict. Late AMI answer/playback evidence contradicting a zero-rated unanswered call opens a hold without rewriting history. Matching precise evidence can resolve missing/low-precision timing holds, and replay after confirmed playback can finalize an otherwise valid held rating. Conflicting evidence needs a reviewed correction/adjustment workflow; there is no unsafe mark-resolved button.

Definitely unsent authorizing/ready attempts cancelled or excluded before any durable send intent receive a separate application-proven zero rating. This does not invent network timing. Other attempts require trusted terminal evidence. Lost/late events and ambiguous writes continue through existing reconciliation; no blind re-originate was added. A verified late answer corrects a previously failed operational outcome and stops a still-pending retry without inventing playback completion or terminating an already active call.

## Tariffs and money

Tariff versions are immutable and declare code/version, three-letter currency, minor-unit scale, integer minor units per minute, playback policy and approval reference. A permissioned assignment affects future attempts, including future retries. Admission copies the tariff into each attempt before origination. Assignment or price changes cannot alter an existing snapshot; legacy attempts without snapshots are not retrospectively priced.

All monetary multiplication and display use integers and decimal strings. A known unanswered attempt rates zero even without a tariff. An answered attempt without an original tariff is held, with calculated minutes visible but no final charge. The required playback policy is either explicitly approved `charge_answered` or `hold_on_failure` (which holds incomplete/silent playback pending confirmed completion or reviewed policy). No default interpretation of the proposed 0.30p price was used.

`call_ratings.amount_minor` is a rated amount, **not a wallet debit or payment settlement**. Module 13 must use a unique per-attempt settlement key and check open exceptions/reservations before debit. This release does not implement balances, credit reservation, settlement, refunds, telecom cost accounting or payment processing.

## Operator workflow

Deploy code, migrate, seed the new Super Administrator billing permission, then restart long-running gateway/workers through the normal service process:

```sh
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan optimize:clear
```

The migration adds tariff/assignment, timing, rating and exception tables plus immutable `call_attempts.tariff_snapshot`. It preserves existing call history and does not assign prices or enable dispatch. Automatic rollback refuses deletion of rating evidence; use a reviewed archival/correction migration.

After financial approval, prepare `approved-tariff.json` with `code`, `version`, `currency`, `minor_unit_scale`, integer `rate_minor_per_minute` and `playback_policy`. These values must come from the approved commercial policy:

```sh
php artisan billing:tariff approved-tariff.json --actor=ADMIN_ID --evidence="Private approval reference"
php artisan billing:assign CLIENT_ID TARIFF_ID --actor=ADMIN_ID --reason="Approved client tariff assignment"
```

Validate a millisecond-capable adapter before configuring its source name. Then import a private JSON array of matched terminal call records:

```sh
php artisan billing:rate verified-cel.json --actor=TELEPHONY_ADMIN_ID --source=VALIDATED_SOURCE_NAME --evidence="Private CDR/CEL evidence reference"
```

Record schema (replace identifiers/timestamps with actual verified evidence):

```json
{
  "attempt_uuid": "original application attempt UUID",
  "uniqueid": "exact own-leg Asterisk Uniqueid",
  "linkedid": "matching Linkedid when supplied",
  "disposition": "ANSWERED",
  "answered_at": "2026-10-26T11:58:00.000000Z",
  "ended_at": "2026-10-26T11:59:00.001000Z",
  "duration_ms": 60001
}
```

Unanswered records use `BUSY`, `NO ANSWER` or `FAILED`, omit/null `answered_at`, and use zero duration if supplied. File input is bounded to 10 MB/10,000 records. Each record commits separately; replay is safe. The command exits unsuccessfully when records are held or an import fails; earlier committed records remain intact. It never prints raw provider responses or credentials.

The internal Call reconciliation page at `/billing/reconciliation` lists open/resolved holds for telephony administrators. Campaign reports show verified duration, per-attempt rounded minutes and rated amount separately, with open exceptions held. Clients cannot see private evidence references, import timing, assign prices or inspect another account's ratings.

## Validation and remaining acceptance

Tests cover exact 60/120-second boundaries, 60.001/120.001 seconds, 60.4 seconds, sub-millisecond crossings, minimum one minute, zero unanswered calls, independent retries, immutable snapshots, idempotent replay, stale/live channel inventory, correlation conflicts, missing tariffs, playback holds, late contradictory events, unsent zero ratings, protected reports and cross-account denial. Existing AMI, campaign, audio, DND and gateway tests remain regression coverage.

Local schema installation and permission seeding are separate from production deployment. No production tariff, trusted source, credentials or calling settings were configured. Live millisecond-source validation, audible PBX playback acceptance, commercial policy sign-off and wallet settlement remain external/dependent release gates.

Verification on 26 October 2026: **216 tests passed with 1,450 assertions**. Pint, route-cache compilation, Blade compilation and whitespace checks passed. Isolated report and exception-queue previews at 390px/1440px showed no document-level horizontal overflow. The new local MySQL schema migration and permission seeder completed successfully; production deployment remains separate.
