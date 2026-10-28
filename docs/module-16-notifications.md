# Module 16 personal notifications and delivery

Implemented against the 26 October 2026 Module 16 guide: a per-user account inbox, a top-bar bell on client and staff layouts, queued email/SMS copies, observable delivery status and campaign-report CSV export. It complements, rather than replaces, the audit-backed client notices introduced in Module 8.

Notification failures never roll back the business event that raised them. A provider or notification-table fault is logged without the alert body or recipient address, and the payment, call settlement, review or campaign transition still commits.

## Trigger sources

| Event key | Category | Audience | In-app |
| --- | --- | --- | --- |
| `audio.approved`, `audio.rejected` | `review` | The active member who uploaded the recording, unless they made the decision. | No; delivered only, because the audit-backed client notices already show the decision. |
| `audio.submitted` | `review` | Staff reviewers — super administrators, administrators and approvers — excluding the uploader's own login. | Yes |
| `campaign.review.approve`, `campaign.review.reject` | `campaign` | Active account owners plus the campaign owner. | Yes |
| `campaign.completed` | `campaign` | Active account owners plus the campaign owner. Email and SMS only after that login opts in. | Yes |
| `billing.low_balance` | `billing` | Every active member of the client account. | Yes |
| `payment.succeeded`, `payment.failed`, `payment.cancelled` | `payment` | The active member who initiated the top-up. | Yes |

Low-credit alerts use the configured threshold, or one full call hold when no fixed threshold is configured. They are deduplicated per wallet and cooldown window: a burst of declined calls produces one alert, not one per attempt. Rejected campaign and audio alerts include the reviewer's reason.

Audio review alerts (`audio.submitted`) are raised the moment a client recording becomes reviewable, so approvals do not wait for a reviewer to open the library. WAV uploads alert immediately; converted sources alert from the conversion worker only after the verified PCM WAV exists, and a client resubmission after rejection raises a fresh alert. They are email-only because SMS would add cost without speeding the review, they include an in-app copy for the staff bell and inbox, and they skip the uploader's own login. Recipients are resolved from the `audio.approve` ability, which covers the seeded Super Administrator, Administrator and Approver roles while excluding client logins and staff roles such as Analyst or Operator.

## Per-event email and SMS opt-ins

Campaign-completion copies are the first event covered by client-controlled subscriptions. Both channels start switched off, so a newly onboarded client receives the in-app alert only until they choose otherwise.

- The toggles live on the client **Account → Notifications** page (`/account/notifications`, `POST /account/notifications/preferences`), in a final **Event notifications** section below the alert inbox and account notices. The account profile page links to them from its notification-display section.
- Choices are stored as booleans in `users.portal_preferences` (`campaign_completed_email`, `campaign_completed_sms`) on the member's own login, so each owner opts in separately and one member's choice never changes a colleague's or the staff copy. A missing key, `null` or a non-`true` value means disabled.
- `Notifier::campaignCompleted()` resolves each recipient's `channels` before creating the alert. Disabled channels create no `notification_deliveries` rows at all, rather than `skipped` rows. The personal in-app alert is still recorded so the event remains in the account inbox and bell.
- The endpoint is client-only: staff logins have no membership and receive `403`. Unknown keys (for example a forged `campaign_completed_push` or `notification_badge`) are ignored and cannot rewrite unrelated preferences; non-boolean values fail validation and return to the page with an error.
- Saving records `identity.preferences_updated` in the audit log as the member's own action, using the same event key as the unread-badge toggle.
- Subscription keys are declared once in `App\Services\Notifications\Preferences::SUBSCRIPTIONS`; adding a new opt-in event means adding an entry there and passing `channels` from `Preferences::channels()` at the notification call site.

## Configuration

| Variable | Default | Purpose |
| --- | --- | --- |
| `NOTIFICATIONS_ENABLED` | `true` | Master switch for creating alerts and delivering them. |
| `NOTIFICATIONS_EMAIL_ENABLED` | `true` | Enables the email delivery channel. |
| `NOTIFICATIONS_SMS_ENABLED` | `true` | Enables the SMS delivery channel. |
| `NOTIFICATIONS_LOW_BALANCE_MINOR` | empty | Fixed threshold in integer minor units. Empty uses one call's maximum hold. |
| `NOTIFICATIONS_LOW_BALANCE_COOLDOWN_MINUTES` | `1440` | Deduplication window, clamped to 1–10080 minutes. |
| `NOTIFICATIONS_QUEUE` | `default` | Queue used by `DeliverNotification`. |

Email uses the application mailer. SMS uses the existing TxtConnect identity delivery adapter; if SMS credentials are not configured, the SMS delivery is marked `skipped` rather than retried forever. In production an unconfigured email transport is also skipped.

## Data model and privacy

`user_notifications` stores one row per recipient with category, event key, severity, title, body, safe link, JSON context, optional dedupe key, an `in_app` flag and a personal `read_at`. Visibility is always by `user_id`; a member cannot read or mark a colleague's copy. The unique `(user_id, dedupe_key)` key makes low-credit and payment retries idempotent.

`notification_deliveries` stores one row per notification and channel with status (`queued`, `sent`, `failed`, `skipped`), attempt count, provider message ID, truncated error text and queued/attempted/sent timestamps. Bodies, addresses and provider payloads are not written to logs.

## Delivery behaviour

`DeliverNotification` is dispatched after the surrounding database transaction commits. It retries up to three times with a 20-second timeout. Missing addresses, disabled channels and unconfigured transports become `skipped`; genuine send failures become `failed` on the synchronous driver or are released for retry on a real queue. The job refuses to resend a delivery already marked `sent` or `skipped`.

## Inbox and reading

- Staff use `/notifications`; client users use `/account/notifications`. Both pages show personal account alerts, delivery status, category filtering (`review`, `campaign`, `billing`, `payment`) and delivery-only records.
- Both inboxes share the same presentation: each alert is a card carrying its title, timestamp, category and unread/read state, body, and one chip per email/SMS delivery with its status (queued, sent, failed or skipped). The markup lives in the shared partials `notifications.partials.alerts` and `notifications.partials.deliveries`, so the staff and client shells cannot drift, and the category filters use the shared `module-tabs` control. Delivery-only records render as a table, so a review decision whose in-app copy already exists in client notices is not repeated as a card.
- The staff layout bell shows the unread count and the eight newest personal alerts. The client layout combines unread personal alerts with unread audit-backed notices.
- Marking read is monotonic by visible notification ID (`through_id`). It updates only the authenticated user's in-app alerts at or below that ID; posting another user's ID returns 404. Marking through the newest visible alert is the mark-all behaviour.
- Response caching is disabled for both inboxes so one login's alerts cannot be served to another.

## Campaign CSV export

`GET /broadcast/campaigns/{campaign}/report.csv` streams one row per call attempt with timing, billable minutes, rated amount, currency and billing exceptions. Access requires an operational/reporting permission or ownership of a campaign the user may view. Tenant scoping still applies, so a foreign campaign ID returns 404.

The export uses `private, no-store`, aligns every row with the documented header and prefixes free-text cells beginning with `=`, `+`, `-` or `@` with an apostrophe to prevent spreadsheet formula execution.

## Deployment

```sh
php artisan migrate --force
php artisan optimize:clear
php artisan view:cache
```

The migration creates `user_notifications` and `notification_deliveries`. Run a queue worker for `NOTIFICATIONS_QUEUE` (default `default`) in any environment where email or SMS delivery is enabled. Keep `QUEUE_CONNECTION=sync` only for tests or single-process deployments where blocking on provider calls is acceptable.

## Validation and remaining acceptance

`NotificationModuleTest` covers campaign review and completion audiences, per-user isolation, personal mark-through, category filtering, queued email delivery with `Mail::fake`, SMS provider delivery with `Http::fake`, missing-address skipping, low-credit deduplication, disabled notifications and the campaign-completion opt-in: the default-off account receives only the in-app alert, enabling email and SMS from `/account/notifications` queues both copies for that login alone, the colleague keeps the default, unknown keys are ignored, the audit row is written, and a staff login posting the form receives `403`. `ClientAudioModuleTest` covers the reviewer alert audience on client upload, converted uploads, resubmission, uploader self-exclusion and the staff inbox render with an existing alert. `BroadcastReportExportTest` covers the CSV header, formula escaping, empty reports, download headers, authentication and tenant isolation. Module 10 audio, Module 12 rating, Modules 13–15 wallet/payment/finance and the campaign suites remain regression coverage.

Remaining external/business acceptance: production email sender/domain verification, TxtConnect sender and message-length UAT, retention policy for old alerts and delivery records, and monitoring for delivery failures. This release does not send to real recipients until those transports are configured and signed off.

