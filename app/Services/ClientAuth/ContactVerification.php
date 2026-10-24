<?php

namespace App\Services\ClientAuth;

use App\Models\ClientAuthChallenge;
use App\Models\User;
use App\Services\Clients\Portal;
use App\Services\Foundation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContactVerification
{
    public function start(User $user, string $channel, string $input): array
    {
        app(Portal::class)->authorize($user);
        [$parsed, $identifier] = Identity::parse($input);
        if ($parsed !== $channel) {
            throw ValidationException::withMessages(['contact' => 'Enter a contact matching the selected verification method.']);
        }
        $key = 'contact-verification:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 3) || RateLimiter::tooManyAttempts($key.':cooldown', 1)) {
            throw ValidationException::withMessages(['contact' => 'Wait 60 seconds between codes; at most three codes per hour.']);
        }
        RateLimiter::hit($key, 3600);
        RateLimiter::hit($key.':cooldown', 60);
        $secret = Str::random(64);
        $code = (string) random_int(100000, 999999);
        $id = DB::transaction(function () use ($user, $channel, $identifier, $secret, $code) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->available($locked, $channel, $identifier);
            $previous = ClientAuthChallenge::where('purpose', 'contact')->whereJsonContains('details->user_id', $user->id)->whereNull('consumed_at')->pluck('id');
            ClientAuthChallenge::whereIn('id', $previous)->update(['consumed_at' => now()]);
            DB::table('client_otp_logs')->whereIn('challenge_id', $previous)->where('status', 'sent')->update(['status' => 'superseded', 'updated_at' => now()]);
            $c = ClientAuthChallenge::create([
                'id' => (string) Str::uuid(), 'purpose' => 'contact', 'channel' => $channel, 'identifier' => $identifier,
                'secret_hash' => hash('sha256', $secret), 'code_hash' => Hash::make($code),
                'details' => ['user_id' => $user->id, 'previous' => $locked->{Identity::column($channel)}],
                'sent_at' => now(), 'expires_at' => now()->addMinutes(5),
            ]);
            app(Delivery::class)->send($channel, $identifier, config('app.name').": Your contact verification code is {$code}. It expires in 5 minutes. Do not share this code.");
            DB::table('client_otp_logs')->insert([
                'challenge_id' => $c->id, 'client_account_id' => $user->client_account_id, 'user_id' => $user->id,
                'purpose' => 'contact', 'channel' => $channel === 'phone' ? 'sms' : 'email', 'recipient_hint' => substr($identifier, -4),
                'status' => 'sent', 'requested_at' => now(), 'sent_at' => now(), 'expires_at' => $c->expires_at,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $c->id;
        });

        return [$id, $secret];
    }

    private function available(User $user, string $channel, string $identifier): void
    {
        $q = User::where('id', '!=', $user->id);
        $channel === 'email' ? $q->whereRaw('LOWER(email) = ?', [$identifier]) : $q->where('phone_e164', $identifier);
        if ($q->exists()) {
            throw ValidationException::withMessages(['contact' => 'This contact is already registered to another user.']);
        }
    }

    public function verify(User $user, string $id, string $secret, string $code): void
    {
        app(Portal::class)->authorize($user);
        $valid = DB::transaction(function () use ($user, $id, $secret, $code) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $c = ClientAuthChallenge::whereKey($id)->where('purpose', 'contact')->lockForUpdate()->first();
            if (! $c || (int) ($c->details['user_id'] ?? 0) !== $user->id || ! hash_equals($c->secret_hash, hash('sha256', $secret)) || $c->consumed_at || $c->expires_at->isPast() || $c->attempts >= 5) {
                throw ValidationException::withMessages(['code' => 'This code is expired or unavailable. Request a new code.']);
            }
            $column = Identity::column($c->channel);
            if ($locked->$column !== ($c->details['previous'] ?? null)) {
                throw ValidationException::withMessages(['code' => 'Your contact changed. Request a new code.']);
            }
            $this->available($locked, $c->channel, $c->identifier);
            $valid = Hash::check($code, $c->code_hash);
            $c->attempts++;
            if ($valid) {
                $changed = $c->identifier !== $locked->$column;
                $locked->$column = $c->identifier;
                $locked->{$c->channel === 'email' ? 'email_verified_at' : 'phone_verified_at'} = now();
                if ($changed) {
                    $locked->remember_token = Str::random(60);
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                    ClientAuthChallenge::where('purpose', 'recovery')->whereJsonContains('details->user_id', $user->id)->update(['consumed_at' => now()]);
                    DB::table('notification_preferences')->where('user_id', $user->id)->update([$c->channel === 'email' ? 'email_enabled' : 'sms_enabled' => false, 'updated_at' => now()]);
                }
                $locked->save();
                $c->verified_at = now();
                $c->consumed_at = now();
                $c->code_hash = null;
                app(Foundation::class)->audit($locked, 'client.contact_verified', $locked->clientAccount, ['channel' => $c->channel, 'replaced' => $changed]);
            }
            $c->save();
            DB::table('client_otp_logs')->where('challenge_id', $c->id)->update([
                'verification_attempts' => $c->attempts, 'verified_at' => $c->verified_at, 'consumed_at' => $c->consumed_at,
                'status' => $valid ? 'consumed' : ($c->attempts >= 5 ? 'locked' : 'sent'), 'updated_at' => now(),
            ]);

            return $valid;
        });
        if (! $valid) {
            throw ValidationException::withMessages(['code' => 'The code is invalid. You have at most five attempts.']);
        }
    }
}
