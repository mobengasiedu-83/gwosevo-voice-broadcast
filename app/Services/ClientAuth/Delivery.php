<?php

namespace App\Services\ClientAuth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

class Delivery
{
    public function send(string $channel, string $identifier, string $message, ?string $subject = null): void
    {
        $channel = $channel === 'phone' ? 'sms' : $channel;
        if (! in_array($channel, ['email', 'sms'], true)) {
            throw new DeliveryException('Unsupported delivery channel.');
        }
        $context = ['delivery_id' => (string) Str::uuid(), 'recipient_hint' => substr($identifier, -4)];
        Log::channel($channel)->info('Message delivery requested.', $context);
        try {
            if ($channel === 'email') {
                if (app()->isProduction() && in_array(config('mail.default'), ['log', 'array', 'failover'], true)) {
                    throw new RuntimeException('Production mail delivery is not configured.');
                }
                Mail::raw($message, fn ($mail) => $mail->to($identifier)->subject($subject ?? config('app.name').' account security'));

                Log::channel('email')->info('Message handed to configured mail transport.', $context + ['mailer' => config('mail.default')]);

                return;
            }
            $context['failure_stage'] = 'configuration';
            if (config('client_auth.sms.driver') !== 'txtconnect' || ! config('client_auth.sms.token')) {
                throw new RuntimeException('SMS is not configured.');
            }
            $context['failure_stage'] = 'provider_request';
            $response = Http::withToken(config('client_auth.sms.token'))->acceptJson()->timeout(15)->connectTimeout(5)
                ->withOptions(['allow_redirects' => false])->post(config('client_auth.sms.url'), [
                    'to' => ltrim($identifier, '+'), 'from' => config('client_auth.sms.sender'), 'unicode' => '0', 'sms' => $message,
                ]);
            $context['http_status'] = $response->status();
            $code = $response->json('data.status_code');
            $context['provider_code'] = is_scalar($code) ? substr((string) $code, 0, 20) : null;
            if (! $response->successful() || $response->json('data.status_code') !== '000' || $response->json('data.in_error') !== false) {
                throw new RuntimeException('SMS provider rejected delivery.');
            }
            Log::channel('sms')->info('SMS accepted by provider; handset delivery is unconfirmed.', $context);
        } catch (\Throwable $e) {
            Log::channel($channel)->warning('Message delivery failed or is uncertain.', $context + ['exception_class' => get_class($e)]);
            // Do not expose provider responses, tokens, messages or recipient details.
            throw new DeliveryException('Unable to send the message. Please try again later.');
        }
    }
}
