<?php

namespace App\Services\Broadcast;

use App\Models\CallAttempt;

class MediaDelivery
{
    public function remote(): bool
    {
        return config('voice_broadcast.media_mode') === 'url';
    }

    public function baseUrl(): string
    {
        $base = rtrim((string) config('voice_broadcast.media_base_url'), '/');
        $parts = parse_url($base);
        if (! $parts || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '::1'], true) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || preg_match('/[^A-Za-z0-9.:_~%\/-]/', $base)) {
            app(Readiness::class)->fail('Set VOICE_BROADCAST_MEDIA_BASE_URL to the HTTP(S) application URL reachable from VitalPBX; localhost cannot be used.');
        }

        return $base;
    }

    public function prefix(CallAttempt $attempt): string
    {
        return $this->baseUrl().'/pbx-media/'.$attempt->uuid.'/'.$attempt->token;
    }
}
