<?php

namespace App\Services\Identity;

use App\Models\User;
use App\Services\Clients\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Challenges
{
    public function normalize(string $channel, string $identifier): string
    {
        if ($channel === 'email') {
            $identifier = mb_strtolower(trim($identifier));
            validator(['identifier' => $identifier], ['identifier' => 'required|email:rfc|max:254'])->validate();

            return $identifier;
        }
        $identifier = preg_replace('/[\s().-]/', '', trim($identifier));
        if (preg_match('/^0[235][0-9]{8}$/', $identifier)) {
            $identifier = '+233'.substr($identifier, 1);
        } elseif (preg_match('/^233[235][0-9]{8}$/', $identifier)) {
            $identifier = '+'.$identifier;
        }
        validator(['identifier' => $identifier], ['identifier' => ['required', 'regex:/^\+[1-9][0-9]{7,14}$/']])->validate();

        return $identifier;
    }

    public function user(string $channel, string $identifier): ?User
    {
        return $channel === 'email' ? User::whereRaw('LOWER(email) = ?', [$identifier])->first() : User::where('phone_e164', $identifier)->first();
    }

    public function start(Request $request, string $purpose, string $channel, string $identifier, array $details): void
    {
        $identifier = $this->normalize($channel, $identifier);
        $lock = Cache::lock('identity-issue:'.hash('sha256', $identifier), 30);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['identifier' => 'A verification request is already in progress. Please wait.']);
        }
        try {
            $this->issue($request, $purpose, $channel, $identifier, $details);
        } finally {
            $lock->release();
        }
    }

    private function issue(Request $request, string $purpose, string $channel, string $identifier, array $details): void
    {
        abort_unless(in_array($purpose, ['register', 'recover', 'change', 'activate'], true), 422);
        $identifier = $this->normalize($channel, $identifier);
        $key = 'identity-send:'.hash('sha256', $identifier);
        if (RateLimiter::tooManyAttempts($key, 3) || RateLimiter::tooManyAttempts('identity-cooldown:'.$key, 1) || RateLimiter::tooManyAttempts('identity-ip:'.$request->ip(), 10)) {
            throw ValidationException::withMessages(['identifier' => 'Please wait before requesting another verification code.']);
        }
        RateLimiter::hit($key, 900);
        RateLimiter::hit('identity-cooldown:'.$key, 60);
        RateLimiter::hit('identity-ip:'.$request->ip(), 3600);
        $user = $this->user($channel, $identifier);
        $eligible = match ($purpose) {
            'register', 'change' => $user === null,
            'activate' => $user && app(Tenancy::class)->usable($user) && $user->clientMembership()->exists() && ! $user->email_verified_at && ! $user->phone_verified_at,
            'recover' => $user && app(Tenancy::class)->usable($user) && ($channel === 'email' ? ($user->email_verified_at || ! $user->clientMembership()->exists()) : $user->phone_verified_at),
        };
        $details['eligible'] = (bool) $eligible;
        $details['user_id'] = $purpose === 'change' ? $request->user()->id : (in_array($purpose, ['recover', 'activate'], true) ? $user?->id : null);
        $id = (string) Str::uuid();
        $secret = bin2hex(random_bytes(32));
        $code = (string) random_int(100000, 999999);
        $minutes = max(1, min(30, config('client_auth.otp_minutes')));
        DB::transaction(function () use ($request, $id, $purpose, $channel, $identifier, $code, $secret, $details, $minutes) {
            $old = $request->session()->get('identity.id');
            if ($old) {
                DB::table('identity_challenges')->where('id', $old)->update(['consumed_at' => now()]);
            }
            DB::table('identity_challenges')->insert([
                'id' => $id, 'purpose' => $purpose, 'channel' => $channel, 'identifier' => Crypt::encryptString($identifier),
                'code_hash' => Hash::make($code), 'session_hash' => hash('sha256', $secret),
                'details' => Crypt::encryptString(json_encode($details, JSON_THROW_ON_ERROR)),
                'expires_at' => now()->addMinutes($minutes), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $request->session()->put('identity', ['id' => $id, 'secret' => $secret, 'purpose' => $purpose]);
        if ($eligible) {
            try {
                app(Delivery::class)->send($channel, $identifier, $code, $minutes);
            } catch (\Throwable $e) {
                DB::table('identity_challenges')->where('id', $id)->update(['consumed_at' => now()]);
                $request->session()->forget('identity');
                throw ValidationException::withMessages(['identifier' => 'Verification delivery is unavailable. Please try again later.']);
            }
        }
    }

    public function resend(Request $request): void
    {
        $challenge = DB::transaction(fn () => $this->locked($request));
        $details = json_decode(Crypt::decryptString($challenge->details), true, 512, JSON_THROW_ON_ERROR);
        if ($challenge->purpose === 'change') {
            abort_unless($details['user_id'] === $request->user()?->id, 403);
        }
        if ($challenge->purpose === 'register') {
            abort_unless(config('client_auth.registration_enabled'), 404);
        }
        $this->start($request, $challenge->purpose, $challenge->channel, $challenge->identifier, $details);
    }

    private function locked(Request $request)
    {
        $challenge = DB::table('identity_challenges')->where('id', $request->session()->get('identity.id', ''))->lockForUpdate()->first();
        if (! $challenge || ! hash_equals($challenge->session_hash, hash('sha256', (string) $request->session()->get('identity.secret'))) || $challenge->purpose !== $request->session()->get('identity.purpose') || $challenge->consumed_at || CarbonImmutable::parse($challenge->expires_at)->isPast()) {
            throw ValidationException::withMessages(['code' => 'Verification expired or unavailable. Start again.']);
        }
        $challenge->identifier = Crypt::decryptString($challenge->identifier);

        return $challenge;
    }

    public function verify(Request $request, string $code): void
    {
        $valid = DB::transaction(function () use ($request, $code) {
            $challenge = $this->locked($request);
            if ($challenge->verified_at || $challenge->attempts >= max(1, min(10, config('client_auth.otp_attempts')))) {
                return false;
            }
            DB::table('identity_challenges')->where('id', $challenge->id)->increment('attempts');
            $details = json_decode(Crypt::decryptString($challenge->details), true, 512, JSON_THROW_ON_ERROR);
            if (! Hash::check($code, $challenge->code_hash) || ! $details['eligible']) {
                return false;
            }
            if ($challenge->purpose === 'change' && $details['user_id'] !== $request->user()?->id) {
                return false;
            }
            DB::table('identity_challenges')->where('id', $challenge->id)->update(['verified_at' => now()]);

            return true;
        });
        if (! $valid) {
            throw ValidationException::withMessages(['code' => 'Invalid or already used code. Request a new code if needed.']);
        }
    }

    public function ready(Request $request): bool
    {
        try {
            return DB::transaction(fn () => $this->locked($request)->verified_at !== null);
        } catch (ValidationException $e) {
            return false;
        }
    }

    public function consume(Request $request, string $purpose, callable $complete): mixed
    {
        return DB::transaction(function () use ($request, $purpose, $complete) {
            $challenge = $this->locked($request);
            if ($challenge->purpose !== $purpose || ! $challenge->verified_at) {
                throw ValidationException::withMessages(['code' => 'Verify your contact before continuing.']);
            }
            $details = json_decode(Crypt::decryptString($challenge->details), true, 512, JSON_THROW_ON_ERROR);
            if (! $details['eligible']) {
                abort(422);
            }
            $result = $complete($challenge, $details);
            DB::table('identity_challenges')->where('id', $challenge->id)->update(['consumed_at' => now()]);

            return $result;
        });
    }
}
