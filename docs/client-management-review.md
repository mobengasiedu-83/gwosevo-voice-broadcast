# Client Management review and implementation

Reviewed and implemented 25 October 2026 against the supplied Client Management proposal and the existing Laravel application. The module now provides a central organization directory/profile, internal management sections, delegated client users, business verification and operational restrictions. Existing billing, reporting, contact, audio and telephony services remain authoritative.

The sample client counts, balances and activity in the proposal are illustrations. No sample commercial data, real invitations, OTPs, calls, payments or wallet funding were created by this implementation.

## Reconciled scope

| Proposed area | Existing foundation | Implementation and remaining boundary |
| --- | --- | --- |
| Client Overview | Account records and operational data | Filtered account counts, UTC day/week/month/year registration counts, incomplete-contact counts, daily registration trend, campaign/call rankings and finance-authorized zero/low-credit counts and posted charges separated by currency/precision. Detailed call/revenue analysis links to reports rather than creating another analytics engine. |
| All Clients | Create/list/suspend accounts | Search company/contact/email/phone/ID, status/verification/date/API-plan filters, primary contact/verification, finance-authorized wallet balance, canonical profile and permission-controlled formula-safe CSV export capped at 5,000 accounts. |
| Registrations | Dual-contact signup, OTP, durable sanitized OTP history | Internal pending/verified/consumed/expired history, masked destination and failed-attempt counts. Controlled administrative resend preserves the original browser secret and attempt counter, shares identifier/cooldown limits and cannot revive expired/locked/consumed registrations. |
| Client Profiles | Account name and membership | Business/trading names, type, location, contact name/title, registration/tax references, internal account manager, reasoned updates and private supporting documents. Business identity edits invalidate prior business verification. Verified login contacts stay on User records. |
| Client Users | Multiple users per account with a single Client identity boundary | Owner, Administrator, Campaign Manager, Operator, Finance and Viewer capabilities; owner-protected access/role changes, session/API-key revocation, 48-hour hashed single-use email invitations, invitation revocation and existing verified-contact password recovery. Optional MFA remains later work. |
| Verification and Compliance | Independent email/phone verification and versioned terms acceptance | Separate business document approval/rejection, reviewer/reason history, expiry/checksum/private downloads and optional calling-readiness policies. Staff cannot self-mark email/phone verified. Audio/campaign approval is unchanged. |
| Wallets and Credit | Wallet, reservations, append-only ledger, adjustments/refunds | Canonical wallet summary and authorized links to existing finance/reconciliation/report screens. No direct balance editor or duplicate ledger. Payment gateway/invoice workflows remain deferred with Stage 09. |
| Pricing and Plans | Immutable billing policy versions and API quota plans | Links to separate authoritative billing and integration-plan administration. The reference GHS 0.003/minute policy was already confirmed on 24 October; the proposal's unresolved `0.30p` note is historical. Scheduled future price assignments/negotiated discount automation remain extensions. |
| Client Usage | Attempt/playback/IVR/ledger analytics and private exports | Client-scoped links to existing campaign, attempt, IVR and billing reports. They retain date/campaign filters, correct denominators and separate currencies. No synthetic revenue totals or inferred audible success. |
| Account Restrictions | Suspension pauses new dialing and revokes sessions | Independent launch/resume and final-dispatch blocks, optional both-contact/business readiness, active-execution count limit and finance-authorized exact daily call exposure cap. Reasons, audit and account notices are retained. Existing calls may finish. |
| Client Activity Logs | Append-only account audit records | Account/global activity views, successful/failed sign-in, logout, profile/settings, user/invitation/document/restriction/security actions. Failed sign-in actor is unknown rather than attributed to the victim. Private audit metadata is not dumped into management screens. |
| Client Settings | Personal notifications, low-credit preferences and report schedules | Organization timezone, supported language/country and internal account manager. New client campaign forms and report defaults use organization timezone. Existing campaign snapshots and personal notification/report settings are preserved. Caller identity remains internal. |

## Data model decisions

Reuse `client_accounts` and `users.client_account_id`; do not create competing `clients` or `client_users` records. Add profile/settings JSON, verification/restriction fields and manager attribution to the account. Add delegated role, access state and last login to users. Add only `client_documents` and `client_invitations` for new entities. Existing audit, registration/OTP, wallet, financial rate assignment, commercial quota and reporting tables remain the source of truth.

The migration designates the first existing account user as Owner and other existing users as Administrator, preserving their previous operational access. Null roles retain legacy operational behavior for compatibility but do not gain new organization/user administration. New public registrants become Owners; console provisioning chooses Owner for the first member and Administrator thereafter. Review initial owners on deployment.

## Authorization and behavior

Internal read access uses `clients.view`, with compatibility for existing `clients.manage`. Sensitive actions have separate `clients.profile`, `clients.users`, `clients.verify`, `clients.restrict`, `clients.export`, `clients.activity` and `clients.settings` checks. The additive lifecycle seeder registers permission names only and never changes existing role grants. The existing Super Administrator gate continues to authorize its existing broad administrative authority. Other internal users need explicit grants for new controls. No finance, telephony or audio approval permissions are granted. Existing `clients.manage` account creation/suspension controls remain compatible.

Client roles are a separate capability map, never internal staff roles. Owners and Administrators manage their organization, with only Owners allowed to grant/change another Owner. At least one enabled Owner must remain. Campaign Managers author and operate their campaigns. Operators can operate permitted account campaigns without editing their content. Owners/Administrators can manage account campaigns. Legacy colleagues remain read-only on campaigns they did not create. Finance users access wallets/billing reports; Viewers can read permitted operational data without mutation or financial report access.

Web/API checks enforce capabilities server-side. Queued imports, recurring creation/processing, report generation/download, API requests and webhook eligibility recheck role/access where applicable. Revoked client users cannot authenticate or authorize new dispatch. Role changes revoke web sessions and existing API credentials. Existing membership ownership cannot be moved across accounts.

Suspension continues to revoke sessions and pause running/scheduled campaigns. Dispatch blocking is independent and checked at reservation and final network sending; launch-only blocking prevents new start/resume while allowing an already running campaign's dispatch. Restrictions never hang up a call already sent. New controls do not bypass DND, approval, media, credit, capacity, calling windows or emergency stop.

The maximum active-execution count includes running, scheduled and paused campaigns, excluding the campaign being started/resumed. Lowering it does not cancel existing executions. A zero cap blocks new starts. The daily spending cap uses exact wallet denomination, UTC posted call-charge debits plus all held call exposure. Refunds do not restore the daily admission budget. Admission/final-send recheck the cap under the existing engine/financial locks; unresolved call holds remain counted. Unpriced legacy calls cannot bypass a configured spending cap. Existing settlement and dispute rules still govern calls already sent.

Documents are PDF/PNG/JPEG, at most 5 MB each and 100 per account. Private storage uses generated names; downloads are attachments with checksum/no-store/nosniff checks. Only organization administrators and explicitly authorized internal business verifiers can download. Approval requires current intact evidence; expiry makes that document ineligible for optional business-readiness checks. Expiry does not erase review history or rewrite audio approval.

Invitations only create new users; they cannot convert internal staff or move existing account users. Acceptance proves control of the invited email, sets the recipient's own strong password and records current terms acceptance. Secrets are hashed and absent from management views/audit. Development log-mail can contain the actual invitation; keep existing development logs private. No real invitation is sent during installation.

## Deployment and acceptance

1. Back up database, private audio/documents and APP_KEY. Deploy matching code and apply `2026_10_25_020000_add_client_management`.
2. Run the additive `ClientLifecyclePermissionsSeeder` (not a broad role-reset seeder) and assign approved staff grants explicitly before refreshing cached configuration/routes/views. Restart configured workers/gateway using the existing controlled deployment procedure.
3. Verify initial Owners and staff grants. New management controls, including business verification, are not granted to Administrators by default. Configure the existing email transport before inviting real users or requesting real OTP/recovery delivery.
4. Exercise cross-account profile/document/user denial, last-owner protection, role downgrade/revocation, invitation expiry/replay, document expiry/tampering, account restriction changes and financial exposure limits on the target database engine.
5. Complete existing controlled PBX/media/financial release acceptance before enabling paid/live calling. Installation does not change activation flags, assigned prices, wallet balances or PBX configuration.

The schema rollback refuses to discard nonempty document/invitation history. Archive and plan recovery before a rollback; application/source rollback must remain compatible with the retained additive schema.

## Explicit follow-up work

Optional MFA; branches and finer account-manager assignment scope; automatic future-dated pricing/discount schedules; gateway payments/invoices/top-up restrictions; automated document retention/deletion after an approved policy; additional languages/countries; and richer revenue/low-credit/trend visualizations remain product extensions. Historical signup errors or authentication events that were never recorded cannot be reconstructed. Current account states remain Active/Suspended; a distinct Inactive/closed state requires defined lifecycle semantics before implementation.

Production media assurance and audible acceptance remain separately tracked in [pending implementation](pending-implementation.md). The existing report and finance modules provide low-credit notices, charging evidence and financial reconciliation; this module does not certify production readiness.

## Verification on 25 October 2026

The full suite passed with 276 tests and 1,905 assertions. Coverage includes canonical tabs, account isolation, separate verification/finance permissions, document expiry/tampering, invitation acceptance/replay and delivery rollback, OTP cooldown/browser-secret preservation, owner protection, delegated operations, session/key revocation, login auditing, exact financial exposure admission and final-send checks. Permission-registration tests confirm that existing Administrator grants are not expanded.

The additive migration and permission-name registration were applied to local ServBay MySQL. Read-only management overview queries succeeded on that engine. Route cache generation, Blade compilation, Pint and whitespace checks passed; development route/view caches were cleared after checks. No live calls, real invitations/OTPs, payment requests or wallet adjustments were executed. Target deployment, recovery, receiver and audible PBX acceptance remain separate release tasks.
