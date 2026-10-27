# Module 10 client audio and internal approval

Reviewed against Module 10 of the supplied 25 October 2026 Installation and Development Guide and companion roadmap, plus the master/client specifications and permissions tracker. The required workflow is private WAV upload, preview/download, visible review status and rejection reason, internal-only approval/rejection and client resubmission. Campaign launch must reject unapproved recordings.

## Implementation

The audio service accepts common formats, queues incompatible recordings for conversion, validates the resulting RIFF chunks, PCM format, duration and size, discards metadata, and stores canonical 8 kHz mono 16-bit WAV privately. Recordings become Pending after successful processing; conversion failures show an actionable error. See [automatic audio conversion](automatic-audio-conversion.md). Approval verifies the stored checksum, records the internal reviewer and makes matching account-owned draft campaigns Ready. Existing media, consent/DND, scheduling and capacity checks still apply before dispatch; Ready does not itself launch a call.

This review adds account-scoped recording search and Pending/Approved/Rejected totals with private/no-store responses. Client instructions explain internal review, while staff retain approval and PBX deployment controls. Client upload limits now reflect configured byte/duration limits.

Account colleagues can resubmit rejected recordings, matching their existing shared account permissions. Resubmission requires the original private WAV to exist and match its checksum. An unchanged file may be resubmitted for reconsideration; a corrected WAV is uploaded as a new submission so the original media and audit history are preserved. Rejection reasons are validated in the shared service as well as the HTTP form. Prior review events remain in the append-only audit trail after resubmission clears the current rejection fields.

Client-supplied ownership, status, approval and storage fields are ignored during upload. Clients cannot approve/reject or record deployment even if an internal role is accidentally assigned. Foreign account records remain hidden across library, preview, download and mutation routes. Approval only promotes campaigns belonging to the recording's account, including execution without an authenticated HTTP client context.

## Verification and deployment

ClientAudioModuleTest covers the client workflow, ranged preview, download, status/search UI, reviewer decisions/reasons, colleague resubmission, notifications, foreign-account denial, missing/changed WAVs, service reason validation, forged upload fields and rejection of pending/rejected media by launch readiness. Existing engine and media tests cover full launch snapshots, approvals, PCM validation, deployment evidence and protected call-specific media.

No schema migration, credentials, calling configuration or production deployment changes are required. Deploy updated code through the existing workflow and restart long-running workers/gateway through the established process. Tests and UI previews use isolated data; no real OTP, PBX call or audio playback acceptance was performed. The controlled audible playback and PBX validation gate remains Module 4.

Verification on 26 October 2026: the complete suite passed (195 tests), and the focused audio suite passed again after adding the legacy-missing-checksum guard. Pint, Blade compilation and whitespace checks passed. Isolated headless previews at 390px and 1440px showed no document-level horizontal overflow; wide audio tables scroll within their container.
