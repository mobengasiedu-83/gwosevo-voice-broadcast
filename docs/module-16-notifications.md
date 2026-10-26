# Module 16 personal notifications and delivery

Implemented against the 26 October 2026 Module 16 guide: a per-user account inbox, a top-bar bell on client and staff layouts, queued email/SMS copies, observable delivery status and campaign-report CSV export. It complements, rather than replaces, the audit-backed client notices introduced in Module 8.

Notification failures never roll back the business event that raised them. A provider or notification-table fault is logged without the alert body or recipient address, and the payment, call settlement, review or campaign transition still commits.

## Trigger sources

| Event key | Category | Audience | In-app |
| --- | --- | --- | --- |
| `audio.approved`, `audio.rejected` | `review` | The active member who uploaded the recording, unless they made the decision. | No; delivered only, because the audit-backed client notices already show the decision. |
| `campaign.review.approve`, `campaign.review.reject` | `campaign` | Active account owners plus the campaign owner. | Yes |
| `campaign.completed` | `campaign` | Active account owners plus the campaign owner. | Yes |
| `billing.low_balance` | `billing` | Every active member of the client account. | Yes |
| `payment.succeeded`, `payment.failed`, `payment.cancelled` | `payment` | The active member who initiated the top-up. | Yes |

Low-credit alerts use the configured threshold, or one full call hold when no fixed threshold is configured. They are deduplicated per wallet and cooldown window: a burst of declined calls produces one alert, not one per attempt. Rejected campaign and audio alerts include the reviewer's reason.

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

`NotificationModuleTest` covers campaign review and completion audiences, per-user isolation, personal mark-through, category filtering, queued email delivery with `Mail::fake`, SMS provider delivery with `Http::fake`, missing-address skipping, low-credit deduplication and disabled notifications. `BroadcastReportExportTest` covers the CSV header, formula escaping, empty reports, download headers, authentication and tenant isolation. Module 10 audio, Module 12 rating, Modules 13–15 wallet/payment/finance and the campaign suites remain regression coverage.

Remaining external/business acceptance: production email sender/domain verification, TxtConnect sender and message-length UAT, per-event notification preferences beyond the existing unread-badge toggle, retention policy for old alerts and delivery records, and monitoring for delivery failures. This release does not send to real recipients until those transports are configured and signed off.

