# Module 11 client campaigns schedules and IVR

Reviewed against Module 11 of the supplied 25 October 2026 Installation and Development Guide, module roadmap, master specification and client/admin feature tracker. The scope is the account-scoped campaign builder, frozen audience and execution snapshots, scheduling, operational controls and IVR version/review/deployment gates.

## Changes

- Shared account groups selected in the builder now resolve on save, matching the groups visible in the form. Colleagues can edit, start/arm, pause, resume, cancel, archive and template account campaigns under existing Client Member permissions. Internal owner and operator boundaries remain enforced.
- Client caller-ID input is discarded before validation and configuration construction. The outgoing identity remains administrator-configured. Client instructions no longer offer approval or caller-ID selection.
- Duplicate campaigns, templates and template instances retain the source account even when created by an internal administrator. Template lineage cannot cross accounts, and account colleagues can create subsequent template versions. Templates still omit audience, schedule and execution state.
- The IVR builder includes approved application-hosted prompts without requiring a local-PBX audio installation marker. IVR flow deployment itself is still required. Shared-account clone controls are visible, source ownership survives staff cloning, and prompt validation/version creation occur under the admission mutex. Client approval/deployment endpoints remain forbidden.
- Starting rejects an audience with no currently eligible contacts, including all-suppressed or changed frozen targets. Failed starts roll back launch snapshots and run records. DND is checked again by existing reservation/send logic.
- Resume rechecks the active immutable launch version and current media/IVR/trunk readiness. Missing or changed assets leave the campaign paused. Due schedules do not activate for inactive accounts or archived campaigns; existing admission, window and capacity enforcement remain authoritative before any originate.
- Builder, template and IVR pages use private/no-store responses.

## Existing gates preserved

Pending or rejected recordings cannot launch. Interactive campaigns require an internally approved, deployed IVR version with approved intact prompts and active allowlisted transfer routes. Launch freezes contacts, numbers, audio checksum, outgoing identity, schedule/retry policy and IVR routing. Changing group membership after campaign save does not alter the saved audience. Each new execution requires its own immutable run snapshot; duplication does not inherit previous run results.

Gateway/capacity health, emergency stop, calling windows, DND, account status and precise call ownership remain enforced by the existing dispatch engine. Scheduling or marking a campaign Ready does not enable live dispatch. No calling configuration, PBX state, password or login policy was changed in this review.

## Financial dependency

The Module 11 specification also names rejection of underfunded campaigns. This checkout has no wallet, reservation ledger or tariff service; those are Modules 12–13. Wallet-based admission cannot be verified or claimed complete here. Existing unpriced/manual operation is preserved. Commercial acceptance remains incomplete until authoritative funding/reservation checks are integrated at launch and per-attempt admission. No fake credit values or permissive wallet stub was introduced.

## Validation and deployment

ClientCampaignModuleTest covers shared group save, forged caller-ID input, cross-account control denial, frozen future execution, pause/resume/cancel, account-preserving template lineage and staff cloning, URL-mode IVR prompts, internal-only approval/deployment, unapproved recordings/IVR, fully suppressed launch rollback, inactive scheduled accounts and media changes before resume. Existing campaign, one-way engine, client-isolation and interactive tests provide regression coverage for admission, retries, schedules, caller identity, graph validation, protected media and immutable history.

No schema migration is required. Deploy reviewed code and restart existing long-running gateway/worker processes through the normal deployment workflow. Automated verification uses isolated SQLite and fake storage; it does not place PBX calls or establish audible playback, carrier capacity or commercial funding acceptance.

Verification on 26 October 2026: **201 tests passed with 1,341 assertions**. Pint, route-cache compilation, Blade compilation and whitespace checks passed. Isolated client campaign/IVR previews at 390px and 1440px showed no document-level horizontal overflow.
