# Phase 1 discovery

Status: pending live verification and owner sign-off. No outbound calls are enabled by this phase.

## Initial verification on 22 October 2026

From this development host, `php artisan vitalpbx:discover --live` successfully authenticated to the configured AMI server and received Pong. No calls were placed. The overall command returned exit code 1 because the version/trunk/caller ID/dialplan inventory, evidence reference and all channel/CPS limits remain unset. Connectivity from the future production gateway, event permissions and carrier capacity remain unverified. Automated application tests passed: 8 tests, 16 assertions.

This implements the Discovery deliverables in section 15 of the supplied technical specification: AMI/trunk inventory, channel/CPS limits, risk register and test plan. Configuration and a successful Ping do not establish production readiness.

## Run the checks

1. Keep AMI credentials in `.env`; use the keys in `.env.example`. Do not put secrets in evidence or console captures.
2. Run `php artisan vitalpbx:discover` for an offline configuration review. Missing or invalid inventory returns exit code 1.
3. Run `php artisan vitalpbx:discover --live` from the intended gateway host for AMI login and Ping. This opens one diagnostic connection, logs off and places no calls. In the eventual runtime architecture only the persistent gateway should access AMI.
4. Record evidence below, populate discovered values, and rerun the check. Exit code 0 indicates configured checks passed; the approval record is still required.

The channel ceiling is `min(broadcast cap, carrier channels - reserved normal channels, measured safe PBX broadcast capacity)`. Unknown inputs fail closed in this report. This is a readiness calculation, not a runtime admission controller. CPS is a separately verified carrier limit; it is not inferred from channel count.

## Inventory and evidence

Use private evidence references with timestamps and the person who verified each item. Keep phone numbers and credentials out of shared reports.

| Item | Finding | Evidence / verifier / timestamp |
| --- | --- | --- |
| VitalPBX and Asterisk versions | Pending | |
| Supported custom dialplan file and reload procedure | Pending | |
| Private gateway-to-AMI route and firewall source allowlist | Pending | |
| Dedicated AMI account permissions and event visibility | Pending | |
| Login and Ping | Passed from development host; production host pending | Diagnostic run, 22 October 2026 |
| Reconnect behaviour | Pending | |
| PJSIP trunk name, registration and outbound route | Pending | |
| Carrier-approved channel and CPS limits | Pending | |
| Channels reserved for normal contact-centre traffic | Pending | |
| Measured safe additional broadcast channels | Pending | |
| Approved caller ID and outbound number formatting | Pending | |
| Validated originate Channel syntax | Pending | |
| Broadcast custom context and approved audio path | Pending | |
| Baseline CPU, RAM, network, active calls and call quality | Pending | |
| CDR/CEL availability and retention | Pending | |
| Queue/extension transfer allowlist and availability rules | Pending | |
| Deployment topology, backups and recovery objectives | Pending | |

AMI Ping does not discover trunk inventory, prove event access, verify carrier limits or measure capacity. Obtain these through the PBX administration interface, carrier confirmation and controlled tests. Never edit generated VitalPBX files.

## Risk register

| Risk | Impact | Mitigation / required evidence | Owner | Status |
| --- | --- | --- | --- | --- |
| Carrier limits unknown | Rejected calls or trunk exhaustion | Written channel/CPS limits; preserve ordinary traffic capacity | Telephony | Open |
| PBX overloaded | Contact-centre degradation | Baseline measurements; staged load tests with abort thresholds | Telephony / Operations | Open |
| AMI exposed or overprivileged | Unauthorised call control | Private routing, source allowlist, dedicated least-privilege account | Security | Open |
| Unverified dial syntax or caller ID | Misrouting or rejected calls | Carrier/PBX confirmation and approved test recipient | Telephony | Open |
| Disconnect leaves uncertain calls | Duplicate calls or leaked reservations | Reconnect and CDR/CEL reconciliation tests before dispatch implementation | Engineering | Open |
| Generated dialplan overwritten | Broken contact-centre routing | Supported custom context, rollback and regression evidence | Telephony | Open |
| Missing consent or suppression | Unauthorised contact | Purpose/consent policy and approval workflow before any live campaign | Compliance | Open |

## Test plan

Record actual results, evidence references and defects; all scenarios currently remain unexecuted on the PBX.

| Scenario | Procedure | Acceptance / evidence |
| --- | --- | --- |
| Offline readiness | Leave limits unset; then supply validated limits | Unknown limits fail; ceiling respects reservations and all caps |
| AMI reachability | Run live diagnostic from gateway host | Login and Pong succeed; credentials absent from output |
| AMI negative cases | Isolated fake server: reject login, truncate frame, omit Pong | Nonzero exit; socket closes; bounded wait |
| Network isolation | Inspect firewall and test from permitted and unpermitted source | Only permitted private gateway route reaches AMI |
| Event access | Observe approved controlled test call with PBX owner | Required channel, dial, hangup and application events captured |
| Trunk routing | Owner supervises one authorised test call | Correct destination, CLI and trunk; normal routes unaffected |
| Audio and dialplan | Validate custom context using approved fixture | Start/completion/failure events and early hangup distinguishable |
| Recovery | Disconnect diagnostic/event connection during controlled test | Recovery correlates active channels and CDR/CEL without duplicate originate |
| Baseline and load | Record ordinary traffic; later stage 5, 20, 50, 100 only within approved limits | Agreed CPU, memory, quality and normal-traffic thresholds respected |
| Rollback | Disable broadcast component and restore prior custom config | Existing inbound/outbound calls and IVR pass regression |

Before call or load testing, record approved recipients, time window, numeric abort thresholds, monitoring operator and rollback owner. Load testing belongs to later engine/UAT phases; Phase 1 records the plan and limits.

## Sign-off

| Decision | Owner | Evidence / approval date |
| --- | --- | --- |
| Trunk, caller ID, custom context, channel/CPS limits and reservation approved | Telephony | Pending |
| Campaign purpose, calling windows, consent and retention approved | Commercial / Compliance | Pending |
| AMI isolation, secrets and permissions reviewed | Security | Pending |
| Baseline, monitoring, recovery and rollback accepted | Operations | Pending |
| Discovery accepted and Foundation phase authorised | Product | Pending |
