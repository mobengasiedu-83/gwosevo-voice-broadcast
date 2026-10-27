# Automatic audio conversion

The audio library accepts WAV, MP3, M4A, AAC, OGG/OGA, Opus, FLAC, AIFF/AIF, WMA and audio-only WebM. The source must be no larger than 10 MB and the decoded recording no longer than 600 seconds. Video, playlists, protected media and unreadable audio fail validation or conversion.

Compatible 8 kHz mono 16-bit PCM WAV files are validated and canonicalized immediately. Other files are stored privately and queued for conversion through FFmpeg. FFprobe verifies an audio stream and duration first. FFmpeg selects the first audio stream, mixes stereo down to mono, resamples to 8 kHz, and encodes signed 16-bit little-endian PCM WAV. Conversion provides telephone compatibility, not restoration of missing source quality. Output volume is not automatically normalized.

The converted WAV is checked again using the same RIFF/PCM, byte-size and duration validation as direct WAV uploads. Metadata is stripped, a SHA-256 checksum is generated, and the record changes from `processing` to `pending`. Only then are preview, download and review available. Approval, tenant ownership, PBX deployment checks and campaign readiness rules remain in effect.

A processing failure produces `conversion_failed` with a safe, actionable message and a replacement-upload link. Partial output and source files are deleted after success or failure. Worker timeouts are limited to 55 seconds; FFprobe has a 5-second limit and FFmpeg 40 seconds. A failed job marks the recording failed and cleans up. Jobs are idempotent once processing has completed and overlapping jobs for one recording are prevented.

## Installation

Install both `ffmpeg` and `ffprobe` with the required decoders. For example, `brew install ffmpeg` on macOS or `apt install ffmpeg` on Debian/Ubuntu. They must be executable by the PHP/queue service account. Use absolute paths in environments whose service PATH differs from the shell:

```dotenv
FFMPEG_BINARY=/usr/local/bin/ffmpeg
FFPROBE_BINARY=/usr/local/bin/ffprobe
```

Run:

```sh
php artisan migrate
php artisan config:clear
php artisan audio:conversion-check
```

Use an asynchronous queue (`QUEUE_CONNECTION=redis` or `database`) and run Horizon or `php artisan queue:work --queue=audio-conversion --tries=1 --timeout=60`. Horizon includes a dedicated audio-conversion supervisor (one local process, two production processes), keeping media work separate from other jobs. No additional systemd service is required when Horizon is already running. Keep queue retry_after above 60 seconds (the repository defaults to 90). A sync connection also works but conversion takes place during upload. Restart existing workers after updating code or binary paths. Make sure PHP/web-server upload limits permit the application’s 10 MB source limit.

The library refreshes processing rows every five seconds for up to five minutes without replacing active review forms. A Refresh status link remains available afterward. Processing and conversion-failed filters are available to staff and clients within their existing account scope.

## Verification

`tests/Feature/AudioConversionTest.php` generates real short stereo fixtures and exercises FFmpeg/FFprobe conversions for all supported format families. It also covers pending-review gating, cleanup, missing tools, corrupt media, size/duration limits, job failure and repeated delivery. Existing client/audio and broadcast tests protect review, tenancy and playback behavior.

No production deployment is implied by a local installation. Provision tools and apply migrations on a target host before deploying the upload code there.

## This local workspace

FFmpeg and FFprobe 9.0.2 are installed in `.tools/audio/` (ignored by Git), with absolute paths in the local `.env`. This avoids depending on the web server’s PATH. The conversion migration has been applied to the local MySQL database. Production has not been modified for this feature.

Validation on 2026-10-27: the full PHP suite completed with 309 tests, 2,584 assertions and one optional browser-fixture export skipped (308 tests passed). `npm run test:audio-browser` passed desktop and mobile Chrome checks, including the transition from Processing to Pending without a page reload. A real local MySQL/Redis upload converted a one-second stereo MP3 in the background, produced a verified checksum and Pending status, and its temporary test record was removed afterward.
