# Module 2 — Campaigns

**Current flow:** [Audio approval → Ready → Start](campaign-audio-workflow.md). This newer flow replaces the separate campaign approval step described below for new campaigns; the earlier endpoints remain for compatibility.

Implements specification v2 section 19.2 using the existing approved direct-broadcast and interactive-IVR engine. Open **Campaigns** in the sidebar, or `/campaigns`.

## Workflows

- **All campaigns:** scoped search, status/type filters, pagination, archive/restore and duplication. Campaign Managers see their own records; existing approver, operator, analyst and auditor permissions provide cross-campaign read scope. Mutations enforce ownership and permissions on the server.
- **Create/edit:** a five-section draft editor covers objective, type, direct strategy, source group and additional contact IDs, recording, approved IVR destinations, trunk/CLI, timezone, calling window, launch schedule, CPS/concurrency, retries and relative priority. Incomplete drafts can be saved. Readiness checks block submission until approved/deployed assets, permitted CLI, valid audience and calling policy are present. Preview/progressive/predictive strategies belong to later dialing/agent modules and cannot be submitted here.
- **Audience:** group membership is resolved on save. Submission freezes recipient IDs/numbers and calling assets. Eligibility totals distinguish consent, suppression and changed contacts; admission rechecks every recipient immediately before sending. Subsequent group membership changes cannot expand an approved audience.
- **Approvals:** owner submission and independent approval/rejection require notes. Owners cannot approve their own work. Approval compares the current readiness snapshot with submission and blocks changed audience/assets until rejection and resubmission. Review reasons and immutable approved versions are visible on the campaign detail screen.
- **Templates:** immutable blueprint families with sequential versions. Save a new family/version from an owned campaign. Using a template creates a fresh draft; audience, schedule and approval are excluded. Templates never authorize execution.
- **Campaign runs:** launching records a separate immutable approved snapshot and launch actor. Each executable campaign has one run; repeating it creates a linked new campaign draft with fresh approval and its own recipients/results. The original run and retry history remain intact. Existing pre-module executions retain their legacy reports; the dedicated run ledger records launches made after this migration.
- **Scheduled/active:** monthly calendar in Africa/Accra, launch times shown in campaign timezone, start/pause/resume/cancel and reason audit. Operator controls reuse engine validation. Cancellation requires the existing emergency permission and retains channel reservations until confirmed termination/reconciliation. Archiving cannot hide running, paused, scheduled, pending-review or unreconciled reserved work.
- **Priority/allocation:** displays approved weight, CPS, concurrency and current reservations. Existing weighted dispatch prevents starvation among eligible campaigns while global/trunk caps, CPS and reserved normal traffic remain authoritative. Weights are relative admission shares, not guaranteed capacity. Changing approved limits requires a new draft/review.

Legacy foundation and broadcast routes remain supported. Archived drafts cannot be edited/submitted or launched through those routes either. No dispatch settings are enabled by this module.

## Themes

All public and authenticated layouts offer a light/dark switch. Selection is stored under `gwosevo-theme` in browser local storage and synchronized across tabs. On first visit, the operating-system preference is used. The early initialization script applies the selection before styles render; the toggle has an accessible label/pressed state. If storage is blocked, switching still works for the current page. Shared tokens are in `public/css/theme.css`; light overrides are in `public/css/themes.css`.

## Installation and verification

Run `php artisan migrate --force`; the migration adds archive/submission fields and immutable template/run tables. Existing role permissions are reused, so no new role grant is necessary. Restart long-running gateway/worker processes through the deployment's existing service manager after deploying updated code.

Verification: **86 tests passed, 360 assertions**. Blade compilation and JavaScript syntax checks pass. The light editor was visually reviewed at desktop size; browser measurements confirmed a 390px mobile viewport without horizontal overflow, and the theme toggle updated both the active theme and stored preference.

Feature coverage includes access boundaries, incomplete drafts, unsupported strategies, group snapshots, independent approval, changed assets, execution creation, duplication without execution state, immutable template versions, archive guards, scheduled/allocation views, control permissions and theme controls. Existing engine tests continue covering admission, retries, consent, IVR and precise hangup ownership.

Live PBX acceptance, carrier limits, predictive pacing and later agent modules are outside Module 2; readiness and capacity protections continue to fail closed.
