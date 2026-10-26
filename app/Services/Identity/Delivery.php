<?php

namespace App\Services\Identity;

use App\Mail\IdentityCode;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
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
        $this->sms($identifier, "Your GWO SEVO code is {$code}. Expires in {$minutes} minutes. Do not share it.");
    }

    /**
     * Send one SMS through TxtConnect and return the provider message id.
     *
     * Provider acceptance only means the message was queued: TxtConnect reports
     * status 000 at submission time, so handset delivery still needs confirming.
     */
    public function sms(string $phone, string $text): string
    {
        if (config('client_auth.sms_driver') !== 'txtconnect' || ! config('client_auth.sms_token') || ! config('client_auth.sms_sender') || parse_url((string) config('client_auth.sms_url'), PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('SMS delivery is not configured.');
        }
        $response = Http::withToken(config('client_auth.sms_token'))->acceptJson()->timeout(10)->post(config('client_auth.sms_url'), [
            'to' => ltrim($phone, '+'), 'from' => config('client_auth.sms_sender'),
            'unicode' => (string) config('client_auth.sms_unicode'),
            'sms' => $text,
        ]);
        $provider = [
            'http_status' => $response->status(),
            'provider_code' => (string) $response->json('data.status_code'),
            'provider_message' => Str::limit((string) ($response->json('data.reason') ?? $response->json('data.message') ?? $response->json('msg') ?? ''), 200),
            'identifier_hash' => hash('sha256', $phone),
        ];
        // Never log the message body: it carries the verification code.
        if (! $response->successful() || $provider['provider_code'] !== '000' || $response->json('data.in_error') !== false) {
            Log::warning('identity.sms_failed', $provider);

            throw new RuntimeException('SMS delivery failed.');
        }
        $messageId = (string) ($response->json('messageId') ?? '');
        Log::notice('identity.sms_accepted', ['provider_code' => '000', 'message_id' => $messageId, 'identifier_hash' => $provider['identifier_hash']]);

        return $messageId;
    }
}
