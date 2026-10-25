<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Integrations\WebhookTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 30;

    public function __construct(public int $deliveryId) {}

    public function handle(WebhookTransport $transport): void
    {
        if (! config('integrations.webhook_delivery_enabled')) {
            return;
        }
        $claim = DB::transaction(function () {
            $d = DB::table('webhook_deliveries')->where('id', $this->deliveryId)->lockForUpdate()->first();
            if (! $d || ! in_array($d->status, ['pending', 'failed'], true) || $d->attempts >= 5 || $d->redacted_at || ($d->next_attempt_at && now()->lt($d->next_attempt_at))) {
                return null;
            }
            $s = DB::table('webhook_subscriptions')->where('id', $d->webhook_subscription_id)->first();
            $u = $s ? User::find($s->user_id) : null;
            if (! $s?->active || ! $u?->isClient() || ! $u->clientCan('integrations.manage') || ! $u->clientAccountActive() || (int) $u->client_account_id !== (int) $s->client_account_id || ! ($u->email_verified_at || $u->phone_verified_at) || now()->subDays(7)->gt($d->created_at)) {
                DB::table('webhook_deliveries')->where('id', $d->id)->update(['status' => 'skipped', 'failure_reason' => 'Subscription, account or delivery no longer eligible.', 'updated_at' => now()]);

                return null;
            }
            DB::table('webhook_deliveries')->where('id', $d->id)->update(['status' => 'sending', 'attempts' => $d->attempts + 1, 'updated_at' => now()]);

            return [$d, $s];
        });
        if (! $claim) {
            return;
        }
        [$d, $s] = $claim;
        $status = null;
        $reason = null;
        try {
            $timestamp = (string) now()->timestamp;
            $signature = hash_hmac('sha256', $timestamp.'.'.$d->uuid.'.'.$d->payload, Crypt::decryptString($s->signing_secret));
            $status = $transport->send($s->url, $d->payload, ['Content-Type: application/json', 'X-VoiceCast-Delivery: '.$d->uuid, 'X-VoiceCast-Timestamp: '.$timestamp, 'X-VoiceCast-Signature: v1='.$signature]);
            $result = $status >= 200 && $status < 300 ? 'sent' : 'failed';
            $reason = $result === 'sent' ? null : 'Receiver returned a non-success HTTP status.';
        } catch (\Throwable $e) {
            $result = 'failed';
            $reason = 'Endpoint validation or transport did not confirm delivery.';
        }
        // If persistence fails after a send, leave sending for maintenance to classify as unknown.
        DB::table('webhook_deliveries')->where('id', $d->id)->where('status', 'sending')->where('attempts', $d->attempts + 1)->update(['status' => $result, 'http_status' => $status, 'failure_reason' => $reason, 'sent_at' => $result === 'sent' ? now() : null, 'next_attempt_at' => $result === 'failed' && $d->attempts + 1 < 5 ? now()->addSeconds(60 * 2 ** $d->attempts) : null, 'updated_at' => now()]);
        Log::channel('webhooks')->info('Webhook attempt completed.', ['delivery_id' => $d->uuid, 'status' => $result, 'http_status' => $status, 'attempt' => $d->attempts + 1]);
    }
}
