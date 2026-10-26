<?php

namespace App\Jobs;

use App\Mail\AccountAlert;
use App\Models\NotificationDelivery;
use App\Models\UserNotification;
use App\Services\Identity\Delivery as SmsDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queue one email or SMS alert and keep the delivery status observable.
 *
 * Failures never escape in the synchronous testing driver, so a provider
 * outage cannot roll back the business event that raised the alert. On real
 * queues a failed attempt is released for retry up to $tries.
 */
class DeliverNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    public function __construct(public int $deliveryId) {}

    public function handle(SmsDelivery $sms): void
    {
        $delivery = NotificationDelivery::find($this->deliveryId);
        if (! $delivery || $delivery->status === 'sent' || $delivery->status === 'skipped') {
            return;
        }
        $notification = UserNotification::with('user')->find($delivery->notification_id);
        $user = $notification?->user;
        if (! $notification || ! $user || ! config('notifications.enabled', true)) {
            $this->skip($delivery, 'Notification recipient is unavailable.');

            return;
        }
        if (! config('notifications.channels.'.$delivery->channel, true)) {
            $this->skip($delivery, ucfirst($delivery->channel).' alerts are disabled.');

            return;
        }

        $delivery->forceFill(['attempts' => $delivery->attempts + 1, 'attempted_at' => now()])->save();

        try {
            if ($delivery->channel === 'email') {
                if (! $user->email) {
                    $this->skip($delivery, 'The recipient has no email address.');

                    return;
                }
                if (app()->environment('production') && in_array(config('mail.default'), ['log', 'array'], true)) {
                    $this->skip($delivery, 'Email transport is not configured.');

                    return;
                }
                Mail::to($user->email)->send(new AccountAlert(
                    $notification->title,
                    (string) $notification->body,
                    $notification->url,
                    $user->name,
                ));
                $this->sent($delivery);

                return;
            }

            if ($delivery->channel === 'sms') {
                if (! $user->phone_e164) {
                    $this->skip($delivery, 'The recipient has no verified mobile number.');

                    return;
                }
                if (! $this->smsConfigured()) {
                    $this->skip($delivery, 'SMS transport is not configured.');

                    return;
                }
                $message = Str::limit(trim($notification->title.'. '.$notification->body), 420);
                if ($notification->url) {
                    $message = Str::limit($message.' '.$notification->url, 480);
                }
                $providerId = $sms->sms($user->phone_e164, $message);
                $this->sent($delivery, $providerId);

                return;
            }

            $this->skip($delivery, 'Unsupported notification channel.');
        } catch (Throwable $e) {
            $delivery->forceFill([
                'status' => $this->attempts() < $this->tries && config('queue.default') !== 'sync' ? 'queued' : 'failed',
                'last_error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();
            // Never include the alert body or address in logs.
            Log::warning('notification.delivery_failed', [
                'delivery_id' => $delivery->id, 'channel' => $delivery->channel,
                'attempt' => $delivery->attempts, 'exception' => $e::class,
            ]);

            if ($delivery->status === 'queued') {
                $this->release(60 * max(1, $delivery->attempts));
            }
        }
    }

    public function failed(?Throwable $e): void
    {
        NotificationDelivery::whereKey($this->deliveryId)->where('status', '!=', 'sent')->update([
            'status' => 'failed', 'last_error' => mb_substr((string) $e?->getMessage(), 0, 500), 'updated_at' => now(),
        ]);
    }

    private function sent(NotificationDelivery $delivery, ?string $providerId = null): void
    {
        $delivery->forceFill([
            'status' => 'sent', 'sent_at' => now(), 'last_error' => null,
            'provider_message_id' => $providerId ? mb_substr($providerId, 0, 255) : null,
        ])->save();
    }

    private function skip(NotificationDelivery $delivery, string $reason): void
    {
        $delivery->forceFill(['status' => 'skipped', 'last_error' => $reason])->save();
    }

    private function smsConfigured(): bool
    {
        return config('client_auth.sms_driver') === 'txtconnect'
            && config('client_auth.sms_token')
            && config('client_auth.sms_sender')
            && parse_url((string) config('client_auth.sms_url'), PHP_URL_SCHEME) === 'https';
    }
}
