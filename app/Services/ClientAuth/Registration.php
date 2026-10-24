<?php

namespace App\Services\ClientAuth;

use App\Models\ClientAccount;
use App\Models\ClientAuthChallenge;
use App\Models\User;
use App\Services\Foundation;
use App\Services\GhanaPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Registration
{
    public function start(array $data): array
    {
        abort_unless(config('client_auth.registration_enabled'), 404);
        $data = Validator::make($data, [
            'name' => 'required|string|max:255', 'organization' => 'required|string|max:255',
            'email' => 'required|email:rfc|max:255', 'phone' => 'required|string|max:30',
            'country' => 'required|in:GH', 'verification_channel' => 'required|in:email,phone', 'terms' => 'required|accepted',
        ])->validate();
        $data['email'] = strtolower(trim($data['email']));
        try {
            $data['phone'] = GhanaPhone::normalize($data['phone']);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid Ghana mobile number.']);
        }
        $this->available($data['email'], $data['phone']);
        $channel = $data['verification_channel'];
        $identifier = $channel === 'email' ? $data['email'] : $data['phone'];
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
                'details' => ['name' => $data['name'], 'organization' => $data['organization'], 'email' => $data['email'], 'phone' => $data['phone'], 'country' => $data['country'], 'terms_accepted_at' => now()->toDateTimeString(), 'terms_version' => config('client_auth.terms_version')],
                'sent_at' => now(), 'expires_at' => now()->addMinutes(5),
            ]);
            app(Delivery::class)->send($channel, $identifier, config('app.name').": Your registration code is {$code}. It expires in 5 minutes. Do not share this code.");

            $this->recordOtp($challenge);

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
            DB::table('client_otp_logs')->where('challenge_id', $c->id)->where('status', 'sent')->update([
                'verification_attempts' => $c->attempts, 'verified_at' => $c->verified_at,
                'status' => $valid ? 'verified' : ($c->attempts >= 5 ? 'locked' : 'sent'), 'updated_at' => now(),
            ]);

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
            $c->update(['code_hash' => Hash::make($code), 'sent_at' => now(), 'expires_at' => now()->addMinutes(5)]);
            app(Delivery::class)->send($c->channel, $c->identifier, config('app.name').": Your registration code is {$code}. It expires at ".$c->expires_at->toIso8601String().'.');
            DB::table('client_otp_logs')->where('challenge_id', $c->id)->where('status', 'sent')->update(['status' => 'superseded', 'updated_at' => now()]);
            $this->recordOtp($c);
        });
    }

    private function available(string $email, string $phone): void
    {
        if (User::whereRaw('LOWER(email) = ?', [$email])->orWhere('phone_e164', $phone)->exists()) {
            throw ValidationException::withMessages(['email' => 'This email or mobile number is already registered. Sign in or recover your account.']);
        }
    }

    private function recordOtp(ClientAuthChallenge $challenge): void
    {
        DB::table('client_otp_logs')->insert([
            'challenge_id' => $challenge->id, 'purpose' => $challenge->purpose,
            'channel' => $challenge->channel === 'phone' ? 'sms' : 'email',
            'recipient_hint' => substr($challenge->identifier, -4), 'status' => 'sent',
            'requested_at' => $challenge->sent_at, 'sent_at' => now(), 'expires_at' => $challenge->expires_at,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function finish(string $id, string $secret, string $password): User
    {
        return DB::transaction(function () use ($id, $secret, $password) {
            $c = $this->challenge($id, $secret, true);
            abort_unless($c->verified_at, 403, 'Verify your address or number first.');
            // Challenges issued before the dual-contact policy must restart signup.
            if (empty($c->details['email']) || empty($c->details['phone']) || empty($c->details['terms_accepted_at'])) {
                throw ValidationException::withMessages(['code' => 'Registration requirements changed. Start registration again.']);
            }
            $this->available($c->details['email'], $c->details['phone']);
            $column = Identity::column($c->channel);
            if (User::where($column, $c->identifier)->exists()) {
                throw ValidationException::withMessages(['identifier' => 'This address or number is already registered. Sign in instead.']);
            }
            $user = new User(['name' => $c->details['name'], 'password' => $password]);
            $user->email = $c->details['email'];
            $user->phone_e164 = $c->details['phone'];
            $user->country = $c->details['country'];
            $user->terms_accepted_at = $c->details['terms_accepted_at'];
            $user->terms_version = $c->details['terms_version'];
            $verified = $c->channel === 'email' ? 'email_verified_at' : 'phone_verified_at';
            $user->$verified = now();
            $user->save();
            $account = ClientAccount::create(['name' => $c->details['organization'], 'created_by' => $user->id]);
            $user->forceFill(['client_account_id' => $account->id])->save();
            $user->assignRole('Client');
            app(Foundation::class)->audit($user, 'client.registered', $account);
            DB::table('client_otp_logs')->where('challenge_id', $c->id)->update(['client_account_id' => $account->id, 'user_id' => $user->id, 'updated_at' => now()]);
            DB::table('client_otp_logs')->where('challenge_id', $c->id)->where('status', 'verified')->update(['status' => 'consumed', 'consumed_at' => now()]);
            $c->update(['consumed_at' => now(), 'secret_hash' => hash('sha256', Str::random(64))]);

            return $user;
        });
    }
}
