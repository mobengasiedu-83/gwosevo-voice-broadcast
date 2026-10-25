<?php

namespace App\Services\Integrations;

use App\Jobs\DeliverWebhook;
use App\Models\Campaign;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\Foundation;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Webhooks
{
    public const EVENTS = ['campaign.completed', 'campaign.failed', 'campaign.cancelled', 'call.outcome'];

    public function capture($record): void
    {
        if ($record instanceof Campaign) {
            if (! $record->client_account_id || ! in_array($record->status, ['completed', 'failed', 'cancelled'], true)) {
                return;
            }
            $account = $record->client_account_id;
            $type = 'campaign.'.$record->status;
            $key = 'campaign:'.$record->id.':'.$record->status;
            $data = ['campaign_id' => $record->id, 'status' => $record->status];
        } else {
            if (! $record->ended_at || ! $record->outcome || $record->state === 'unknown') {
                return;
            }
            $campaign = DB::table('campaign_recipients as r')->join('campaigns as c', 'c.id', '=', 'r.campaign_id')->where('r.id', $record->campaign_recipient_id)->select('c.id', 'c.client_account_id')->first();
            if (! $campaign?->client_account_id) {
                return;
            }
            $account = $campaign->client_account_id;
            $type = 'call.outcome';
            $key = 'attempt:'.$record->id.':'.hash('sha256', json_encode([$record->outcome, (bool) $record->answered_at, (bool) $record->playback_completed_at]));
            $data = ['campaign_id' => $campaign->id, 'attempt_id' => $record->uuid, 'outcome' => $record->outcome, 'answered' => (bool) $record->answered_at, 'playback_completed' => (bool) $record->playback_completed_at];
        }
        // An outbox row is committed or rolled back with the business update. No network I/O here.
        DB::transaction(function () use ($key, $account, $type, $data) {
            DB::table('client_accounts')->where('id', $account)->lockForUpdate()->firstOrFail();
            DB::table('integration_events')->insertOrIgnore(['event_key' => $key, 'client_account_id' => $account, 'type' => $type, 'payload' => json_encode($data, JSON_THROW_ON_ERROR), 'occurred_at' => now()]);
        });
    }

    public function create(User $user, string $url, array $events): array
    {
        app(Access::class)->owner($user);
        abort_unless($user->clientCan('integrations.manage'), 403);
        abort_unless($events && ! array_diff($events, self::EVENTS), 422);
        app(WebhookTransport::class)->destination($url);

        return DB::transaction(function () use ($user, $url, $events) {
            DB::table('client_accounts')->where('id', $user->client_account_id)->lockForUpdate()->firstOrFail();
            abort_if(DB::table('webhook_subscriptions')->where('client_account_id', $user->client_account_id)->where('active', true)->count() >= app(Access::class)->limits($user->client_account_id)['webhook_limit'], 422, 'Webhook subscription limit reached.');
            $secret = Str::random(64);
            $id = DB::table('webhook_subscriptions')->insertGetId(['uuid' => (string) Str::uuid(), 'client_account_id' => $user->client_account_id, 'user_id' => $user->id, 'url' => $url, 'events' => json_encode(array_values(array_unique($events))), 'signing_secret' => Crypt::encryptString($secret), 'last_event_id' => DB::table('integration_events')->max('id') ?? 0, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            app(Foundation::class)->audit($user, 'webhook.created', ClientAccount::findOrFail($user->client_account_id), ['subscription_id' => $id]);

            return ['id' => $id, 'secret' => $secret];
        });
    }

    public function maintain(): void
    {
        DB::table('webhook_deliveries')->whereIn('status', ['pending', 'failed'])->where('created_at', '<', now()->subDays(7))->update(['status' => 'expired', 'failure_reason' => 'Delivery retry window expired.', 'updated_at' => now()]);
        // A crashed worker may have sent the request. Do not blindly replay an ambiguous send.
        DB::table('webhook_deliveries')->where('status', 'sending')->where('updated_at', '<', now()->subMinutes(5))->update(['status' => 'unknown', 'failure_reason' => 'Worker interrupted; receiver reconciliation required.', 'updated_at' => now()]);
        DB::table('integration_events')->where('occurred_at', '<', now()->subDays(30))->whereNotNull('payload')->update(['payload' => null]);
        DB::table('webhook_deliveries')->where('created_at', '<', now()->subDays(30))->whereNull('redacted_at')->whereNotIn('status', ['pending', 'sending'])->update(['payload' => '{}', 'redacted_at' => now()]);
        DB::table('integration_usage')->where('bucket', '<', now('UTC')->subDays(30)->format('Y-m-d'))->delete();
        DB::table('api_idempotency')->where('expires_at', '<', now()->subDays(30))->update(['response_body' => null]);
        if (! config('integrations.webhook_delivery_enabled')) {
            return;
        }
        DB::table('webhook_subscriptions')->where('active', true)->orderBy('id')->chunkById(100, function ($subscriptions) {
            foreach ($subscriptions as $subscription) {
                DB::transaction(function () use ($subscription) {
                    DB::table('client_accounts')->where('id', $subscription->client_account_id)->lockForUpdate()->firstOrFail();
                    $s = DB::table('webhook_subscriptions')->where('id', $subscription->id)->lockForUpdate()->first();
                    $user = User::find($s->user_id);
                    if (! $s->active || ! $user?->isClient() || ! $user->clientCan('integrations.manage') || ! $user->clientAccountActive() || (int) $user->client_account_id !== (int) $s->client_account_id || ! ($user->email_verified_at || $user->phone_verified_at)) {
                        return;
                    }
                    $events = DB::table('integration_events')->where('client_account_id', $s->client_account_id)->where('id', '>', $s->last_event_id)->orderBy('id')->limit(200)->get();
                    foreach ($events as $event) {
                        if ($event->payload && in_array($event->type, json_decode($s->events, true), true) && $event->occurred_at >= $s->created_at) {
                            $uuid = (string) Str::uuid();
                            DB::table('webhook_deliveries')->insertOrIgnore(['uuid' => $uuid, 'webhook_subscription_id' => $s->id, 'event_key' => $event->event_key, 'payload' => json_encode(['id' => $uuid, 'type' => $event->type, 'occurred_at' => $event->occurred_at, 'data' => json_decode($event->payload, true)], JSON_THROW_ON_ERROR), 'status' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
                        }
                        DB::table('webhook_subscriptions')->where('id', $s->id)->update(['last_event_id' => $event->id]);
                    }
                });
            }
        });
        DB::table('webhook_deliveries')->whereIn('status', ['pending', 'failed'])->where('attempts', '<', 5)->whereNull('redacted_at')->where('created_at', '>=', now()->subDays(7))->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('id')->limit(200)->pluck('id')->each(fn ($id) => DeliverWebhook::dispatch($id));
    }
}
