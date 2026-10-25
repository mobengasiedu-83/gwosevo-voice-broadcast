<?php

namespace App\Services\Identity;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class Turnstile
{
    public function verify(Request $request, string $action): void
    {
        if (! config('turnstile.enabled')) {
            return;
        }
        $token = $request->input('cf-turnstile-response');
        $fail = fn () => throw ValidationException::withMessages(['turnstile' => 'Security verification failed or expired. Please try again.']);
        if (! config('turnstile.site_key') || ! config('turnstile.secret_key') || ! config('turnstile.hostnames') || ! is_string($token) || strlen($token) > 2048 || $token === '') {
            $fail();
        }
        $key = 'turnstile-used:'.hash('sha256', $token);
        if (Cache::has($key)) {
            $fail();
        }
        try {
            $response = Http::asForm()->timeout(max(1, min(15, config('turnstile.timeout'))))->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => config('turnstile.secret_key'), 'response' => $token, 'remoteip' => $request->ip(),
            ]);
            $result = $response->json();
        } catch (\Throwable $e) {
            $fail();
        }
        if (! $response->successful() || ! is_array($result) || ($result['success'] ?? false) !== true || ($result['action'] ?? null) !== $action || ! in_array($result['hostname'] ?? null, config('turnstile.hostnames'), true)) {
            $fail();
        }
        if (! Cache::add($key, true, 300)) {
            $fail();
        }
    }
}
