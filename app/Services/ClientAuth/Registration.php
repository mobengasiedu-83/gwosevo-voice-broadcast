<?php

namespace App\Services\ClientAuth;

use App\Models\ClientAccount;
use App\Models\ClientAuthChallenge;
use App\Models\User;
use App\Services\Foundation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Registration
{
    public function start(array $data): array
    {
        abort_unless(config('client_auth.registration_enabled'), 404);
        [$channel, $identifier] = Identity::parse($data['identifier']);
        $key = 'registration:'.hash('sha256', $identifier);
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages(['identifier' => 'Please wait before requesting another code.']);
        }
        RateLimiter::hit($key, 3600);
        if (User::where(Identity::column($channel), $identifier)->exists()) {
            throw ValidationException::withMessages(['identifier' => 'Unable to register this address or number. Try signing in or recovering your password.']);
        }
        $secret = Str::random(64);
        $code = (string) random_int(100000, 999999);
        $challenge = DB::transaction(function () use ($data, $channel, $identifier, $secret, $code) {
            $challenge = ClientAuthChallenge::create([
                'id' => (string) Str::uuid(), 'purpose' => 'registration', 'channel' => $channel, 'identifier' => $identifier,
                'secret_hash' => hash('sha256', $secret), 'code_hash' => Hash::make($code),
                'details' => ['name' => $data['name'], 'organization' => $data['organization']],
                'sent_at' => now(), 'expires_at' => now()->addMinutes(10),
            ]);
            app(Delivery::class)->send($channel, $identifier, config('app.name').": Your registration code is {$code}. It expires in 10 minutes. Do not share this code.");

            return $challenge;
        });

        return [$challenge->id, $secret];
    }

    public function challenge(string $id, string $secret, bool $lock = false): ClientAuthChallenge
    {
        $query = ClientAuthChallenge::whereKey($id)->where('purpose', 'registration');
        $c = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $c || ! hash_equals($c->secret_hash, hash('sha256', $secret)) || $c->consumed_at || $c->expires_at->isPast() || $c->attempts >= 5) {
            throw ValidationException::withMessages(['code' => 'This verification has expired or is unavailable. Start registration again.']);
        }

        return $c;
    }

    public function verify(string $id, string $secret, string $code): void
    {
        $valid = DB::transaction(function () use ($id, $secret, $code) {
            $c = $this->challenge($id, $secret, true);
            if ($c->verified_at) {
                return true;
            }
            $c->attempts++;
            $valid = Hash::check($code, $c->code_hash);
            if ($valid) {
                $c->verified_at = now();
                $c->code_hash = null;
            }
            $c->save();

            return $valid;
        });
        if (! $valid) {
            throw ValidationException::withMessages(['code' => 'The code is invalid. You have at most five attempts.']);
        }
    }

    public function resend(string $id, string $secret): void
    {
        DB::transaction(function () use ($id, $secret) {
            $c = $this->challenge($id, $secret, true);
            abort_if($c->verified_at !== null, 422, 'Already verified. Create your password.');
            if ($c->sent_at->greaterThan(now()->subMinute())) {
                throw ValidationException::withMessages(['code' => 'Wait 60 seconds before requesting a new code.']);
            }
            $key = 'registration:'.hash('sha256', $c->identifier);
            if (RateLimiter::tooManyAttempts($key, 3)) {
                throw ValidationException::withMessages(['code' => 'Please wait before requesting another code.']);
            }
            RateLimiter::hit($key, 3600);
            $code = (string) random_int(100000, 999999);
            $c->update(['code_hash' => Hash::make($code), 'sent_at' => now()]);
            app(Delivery::class)->send($c->channel, $c->identifier, config('app.name').": Your registration code is {$code}. It expires at ".$c->expires_at->toIso8601String().'.');
        });
    }

    public function finish(string $id, string $secret, string $password): User
    {
        return DB::transaction(function () use ($id, $secret, $password) {
            $c = $this->challenge($id, $secret, true);
            abort_unless($c->verified_at, 403, 'Verify your address or number first.');
            $column = Identity::column($c->channel);
            if (User::where($column, $c->identifier)->exists()) {
                throw ValidationException::withMessages(['identifier' => 'This address or number is already registered. Sign in instead.']);
            }
            $user = new User(['name' => $c->details['name'], 'password' => $password]);
            $user->$column = $c->identifier;
            $verified = $c->channel === 'email' ? 'email_verified_at' : 'phone_verified_at';
            $user->$verified = now();
            $user->save();
            $account = ClientAccount::create(['name' => $c->details['organization'], 'created_by' => $user->id]);
            $user->forceFill(['client_account_id' => $account->id])->save();
            $user->assignRole('Client');
            app(Foundation::class)->audit($user, 'client.registered', $account);
            $c->update(['consumed_at' => now(), 'secret_hash' => hash('sha256', Str::random(64))]);

            return $user;
        });
    }
}
