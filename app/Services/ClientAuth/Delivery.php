<?php

namespace App\Services\ClientAuth;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class Delivery
{
    public function send(string $channel, string $identifier, string $message): void
    {
        try {
            if ($channel === 'email') {
                if (app()->isProduction() && in_array(config('mail.default'), ['log', 'array', 'failover'], true)) {
                    throw new RuntimeException('Production mail delivery is not configured.');
                }
                Mail::raw($message, fn ($mail) => $mail->to($identifier)->subject(config('app.name').' account security'));

                return;
            }
            if (config('client_auth.sms.driver') !== 'txtconnect' || ! config('client_auth.sms.token')) {
                throw new RuntimeException('SMS is not configured.');
            }
            $response = Http::withToken(config('client_auth.sms.token'))->acceptJson()->timeout(15)->connectTimeout(5)
                ->withOptions(['allow_redirects' => false])->post(config('client_auth.sms.url'), [
                    'to' => ltrim($identifier, '+'), 'from' => config('client_auth.sms.sender'), 'unicode' => '0', 'sms' => $message,
                ]);
            if (! $response->successful() || $response->json('data.status_code') !== '000' || $response->json('data.in_error') !== false) {
                throw new RuntimeException('SMS provider rejected delivery.');
            }
        } catch (\Throwable $e) {
            // Do not expose provider responses, tokens, messages or recipient details.
            throw new DeliveryException('Unable to send the message. Please try again later.');
        }
    }
}
