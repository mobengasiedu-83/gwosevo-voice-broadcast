<?php

namespace App\Services\Identity;

use App\Mail\IdentityCode;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

class Delivery
{
    public function send(string $channel, string $identifier, string $code, int $minutes): void
    {
        if ($channel === 'email') {
            if (app()->environment('production') && in_array(config('mail.default'), ['log', 'array'], true)) {
                throw new RuntimeException('Email delivery is not configured.');
            }
            Mail::to($identifier)->send(new IdentityCode($code, $minutes));

            return;
        }
        if (config('client_auth.sms_driver') !== 'txtconnect' || ! config('client_auth.sms_token') || ! config('client_auth.sms_sender') || parse_url(config('client_auth.sms_url'), PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('SMS delivery is not configured.');
        }
        $response = Http::withToken(config('client_auth.sms_token'))->acceptJson()->timeout(10)->post(config('client_auth.sms_url'), [
            'to' => ltrim($identifier, '+'), 'from' => config('client_auth.sms_sender'),
            'unicode' => (string) config('client_auth.sms_unicode'),
            'sms' => "Your GWO SEVO code is {$code}. Expires in {$minutes} minutes. Do not share it.",
        ]);
        if (! $response->successful() || (string) $response->json('data.status_code') !== '000' || $response->json('data.in_error') !== false) {
            throw new RuntimeException('SMS delivery failed.');
        }
    }
}
