<?php

namespace App\Services\ClientAuth;

use App\Models\ClientAuthChallenge;
use App\Models\User;
use App\Services\Foundation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Recovery
{
    public function send(string $input): void
    {
        [$channel, $identifier] = Identity::parse($input);
        $key = 'recovery:'.hash('sha256', $identifier);
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return;
        }
        RateLimiter::hit($key, 3600);
        $user = User::where(Identity::column($channel), $identifier)->first();
        $verified = $channel === 'email' ? 'email_verified_at' : 'phone_verified_at';
        if (! $user || ! $user->isClient() || ! $user->clientAccountActive() || ! $user->$verified) {
            return;
        }
        $secret = Str::random(64);
        try {
            DB::transaction(function () use ($user, $channel, $identifier, $secret) {
                // Serialize issuance with reset/password changes for this user.
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                ClientAuthChallenge::where('purpose', 'recovery')->where('identifier', $identifier)->whereNull('consumed_at')->update(['consumed_at' => now()]);
                $c = ClientAuthChallenge::create([
                    'id' => (string) Str::uuid(), 'purpose' => 'recovery', 'channel' => $channel, 'identifier' => $identifier,
                    'secret_hash' => hash('sha256', $secret), 'details' => ['user_id' => $user->id], 'sent_at' => now(), 'expires_at' => now()->addMinutes(30),
                ]);
                $url = route('client.password.reset', ['id' => $c->id, 'token' => $secret]);
                app(Delivery::class)->send($channel, $identifier, config('app.name').": Reset your password within 30 minutes: {$url} If you did not request this, ignore this message.");
            });
        } catch (DeliveryException $e) {
            // Keep the same public response for absent users and delivery failures.
            logger()->warning('Client password recovery delivery unavailable.');
        }
    }

    public function valid(string $id, string $token): ClientAuthChallenge
    {
        $c = ClientAuthChallenge::whereKey($id)->where('purpose', 'recovery')->first();
        if (! $c || ! hash_equals($c->secret_hash, hash('sha256', $token)) || $c->consumed_at || $c->expires_at->isPast()) {
            throw ValidationException::withMessages(['token' => 'This recovery link is invalid or expired. Request a new link.']);
        }

        return $c;
    }

    public function reset(string $id, string $token, string $password): void
    {
        $c = $this->valid($id, $token);
        DB::transaction(function () use ($c, $id, $token, $password) {
            $user = User::whereKey($c->details['user_id'])->lockForUpdate()->firstOrFail();
            $locked = ClientAuthChallenge::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->valid($id, $token);
            $column = Identity::column($locked->channel);
            $verified = $locked->channel === 'email' ? 'email_verified_at' : 'phone_verified_at';
            abort_unless($user->isClient() && $user->clientAccountActive() && $user->$verified && $locked->identifier === $user->$column, 403, 'Account unavailable.');
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            ClientAuthChallenge::where('purpose', 'recovery')->whereJsonContains('details->user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            app(Foundation::class)->audit($user, 'client.password_reset', $user->clientAccount);
        });
    }
}
