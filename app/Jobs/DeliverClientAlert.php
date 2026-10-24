<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ClientAuth\Delivery;
use App\Services\Clients\Portal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class DeliverClientAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $deliveryId) {}

    public function handle(Delivery $transport): void
    {
        if (! config('client_alerts.delivery_enabled')) {
            return;
        }
        $payload = DB::transaction(function () {
            $d = DB::table('notification_deliveries')->where('id', $this->deliveryId)->lockForUpdate()->first();
            if (! $d || ! in_array($d->status, ['pending', 'failed'], true) || $d->attempts >= config('client_alerts.max_attempts') || ($d->next_attempt_at && now()->lt($d->next_attempt_at))) {
                return null;
            }
            $user = User::whereKey($d->user_id)->lockForUpdate()->first();
            $pref = DB::table('notification_preferences')->where('user_id', $d->user_id)->first();
            if (! $user || ! $user->isClient() || ! $user->clientAccountActive() || ! $pref || ! $pref->{$d->channel.'_enabled'}) {
                DB::table('notification_deliveries')->where('id', $d->id)->update(['status' => 'skipped', 'failure_reason' => 'Recipient unavailable or channel disabled.', 'updated_at' => now()]);

                return null;
            }
            $notice = app(Portal::class)->notices($user)->whereKey($d->client_notice_id)->first();
            $identifier = $d->channel === 'email' ? ($user->email_verified_at ? $user->email : null) : ($user->phone_verified_at ? $user->phone_e164 : null);
            if (! $notice || ! $identifier || $notice->occurred_at->lt(now()->subDays(7)) || ($pref->channels_enabled_at && $notice->occurred_at->lt($pref->channels_enabled_at))) {
                DB::table('notification_deliveries')->where('id', $d->id)->update(['status' => 'skipped', 'failure_reason' => 'Notice or verified contact unavailable.', 'updated_at' => now()]);

                return null;
            }
            $recent = DB::table('notification_deliveries')->where('user_id', $user->id)->where('attempts', '>', 0)->where('updated_at', '>=', now()->subHour())->sum('attempts');
            if ($recent >= config('client_alerts.per_user_per_hour')) {
                DB::table('notification_deliveries')->where('id', $d->id)->update(['next_attempt_at' => now()->addMinutes(15), 'updated_at' => now()]);

                return null;
            }
            DB::table('notification_deliveries')->where('id', $d->id)->update(['status' => 'sending', 'attempts' => $d->attempts + 1, 'updated_at' => now()]);

            return [$d->channel, $identifier, $d->attempts + 1];
        });
        if (! $payload) {
            return;
        }
        $confirmed = false;
        try {
            $transport->send($payload[0], $payload[1], 'VoiceCast: You have a new account notification. Sign in to your client workspace to review it.', config('app.name').' notification');
            $confirmed = true;
            DB::table('notification_deliveries')->where('id', $this->deliveryId)->update(['status' => 'sent', 'sent_at' => now(), 'failure_reason' => null, 'updated_at' => now()]);
        } catch (\Throwable $e) {
            DB::table('notification_deliveries')->where('id', $this->deliveryId)->update(['status' => $confirmed ? 'unknown' : 'failed', 'failure_reason' => 'The delivery service did not confirm delivery.', 'next_attempt_at' => $confirmed ? null : now()->addSeconds(120 * 2 ** ($payload[2] - 1)), 'updated_at' => now()]);
        }
    }
}
