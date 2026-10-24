# Step 04 — Client portal and dashboard

## Delivered

- A separate, responsive client workspace with persistent light/dark switching, account identity, accessible navigation, profile access and sign-out.
- `/client` replaces the temporary Step 02 landing page. Registration and email/phone password sign-in continue redirecting here.
- Account-scoped overview counts: contacts, groups, running/scheduled campaigns, pending/approved audio, ready campaigns and unique campaign recipients with at least one confirmed completed playback.
- Recent campaigns and a paginated, searchable/status-filtered campaign summary screen at `/client/campaigns`.
- Campaign summaries show execution status, schedule in UTC and completed-playback recipients versus audience size. “Completed” is explicitly described as execution ending, not guaranteed delivery. Retries do not inflate a recipient's successful delivery count.
- Recording approval/rejection notifications at `/client/notifications`, including the review reason on rejection. Internal audio review remains the only source of new decisions; clients cannot approve recordings.
- Per-user read/unread receipts, idempotent “Mark as read” and “Mark all as read”, unread badge and dashboard updates.
- Account settings from Step 03 now use the same client layout. Name/password updates still require the current password; sign-in identity/account ownership remain read-only.
- Account-scoped and identity-restricted queries/routes. Clients cannot open staff screens, another account's summaries or notifications, manipulate someone else's read receipts, or obtain trunk/caller-ID details, audio media tokens or PBX credentials through the portal.
- Suspended accounts cannot enter the portal. Staff reviews can still generate notices for a suspended account, ready for the client to see after reactivation.

This stage provides summaries and in-app review notifications. Contacts/groups/imports are now available through [Step 05](step-05-client-contacts.md). Client audio workflows (Step 06), campaign creation/control and detailed reporting (Step 07), billing and additional notification channels remain later work. No nonfunctional links to those operations are presented as working features.

## Notification behavior

A review and its notification commit in the same database transaction. Each audio decision is keyed by recording, decision and review time; repeated synchronization does not duplicate a decision. Read status is private to each user, while review content is shared with active users in the owning account. No SMS or email is sent by Step 04.

New reviews automatically create notifications. Import existing current approval/rejection decisions once after deploying:

```bash
php artisan client:sync-review-notices
```

This command can be repeated safely. It imports current states with recorded review timestamps, not a fabricated history of earlier decisions.

## Deployment

The migration has been applied to the local development database. Production must be updated separately. After backing up its database and deploying the code:

```bash
cd /var/www/gwosevo-voice-broadcast
sudo -u gwosevo php artisan migrate --force
sudo -u gwosevo php artisan client:sync-review-notices
sudo -u gwosevo php artisan optimize
sudo -u gwosevo php artisan horizon:terminate
```

Deploy the new `public/css/client-portal.css` asset with the application. No additional role grants, SMS settings or PBX changes are required. Refresh long-running processes through the normal deployment procedure; arrange any gateway restart outside active calls.

## Verification

Tests cover tenant isolation in dashboards/campaigns/notifications, cross-account read attempts, per-user idempotent receipts, approval hooks, suspended-account review behavior, guest/staff denial, empty states, campaign pagination/filter preservation, retry delivery deduplication and profile rendering. Step 02–03 authentication/isolation tests and existing internal audio/broadcast tests remain the regression baseline.
