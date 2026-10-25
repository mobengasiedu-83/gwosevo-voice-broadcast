<?php

namespace App\Http\Controllers;

use App\Services\Clients\Tenancy;
use App\Services\Foundation;
use App\Services\Identity\Challenges;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function create()
    {
        return view('foundation.login');
    }

    public function store(Request $request)
    {
        $data = $request->validate(['identifier' => 'nullable|string|max:254', 'email' => 'nullable|string|max:254', 'password' => 'required|string|max:128']);
        $raw = $data['identifier'] ?? $data['email'] ?? '';
        $channel = str_contains($raw, '@') ? 'email' : 'sms';
        $identifier = app(Challenges::class)->normalize($channel, $raw);
        $key = 'identity-login:'.hash('sha256', $identifier);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many sign-in attempts. Try again later.']);
        }
        $user = app(Challenges::class)->user($channel, $identifier);
        $verified = $user && ($channel === 'sms' ? $user->phone_verified_at !== null : (! $user->clientMembership()->exists() || $user->email_verified_at !== null));
        $valid = $user ? Auth::validate(['id' => $user->id, 'password' => $data['password']]) : false;
        if (! $user) {
            Hash::check($data['password'], '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        }
        if (! $valid) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['email' => 'The supplied credentials are invalid.']);
        }
        if (! app(Tenancy::class)->usable($user)) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['email' => 'This account is unavailable. Contact your administrator.']);
        }
        if (! $verified) {
            if ($user->email_verified_at || $user->phone_verified_at) {
                RateLimiter::hit($key, 900);
                throw ValidationException::withMessages(['email' => 'The supplied credentials are invalid.']);
            }
            app(Challenges::class)->start($request, 'activate', $channel, $identifier, []);
            RateLimiter::clear($key);

            return redirect()->route('identity.verify')->with('status', 'Verify your contact to finish your first sign-in.');
        }
        RateLimiter::clear($key);
        Auth::login($user);
        app(Foundation::class)->audit($user, 'identity.signed_in', $user, ['channel' => $channel]);
        $request->session()->regenerate();
        $request->session()->put('auth_version', $request->user()->auth_version ?? 0);

        return redirect()->intended('/dashboard');
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
