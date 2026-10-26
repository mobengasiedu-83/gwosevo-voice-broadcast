<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Clients\Tenancy;
use App\Services\Foundation;
use App\Services\Identity\Challenges;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
        $user = app(Challenges::class)->user($channel, $identifier);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->failed($user, $identifier, 'locked');
            throw ValidationException::withMessages(['email' => 'Too many sign-in attempts. Try again later.']);
        }
        // Registration verifies one contact. Existing account identifiers remain
        // usable as usernames; only verified contacts may receive recovery codes.
        $verified = $user && ($user->clientMembership()->exists()
            ? ($user->email_verified_at !== null || $user->phone_verified_at !== null)
            : ($channel === 'email' || $user->phone_verified_at !== null));
        $valid = $user ? Auth::validate(['id' => $user->id, 'password' => $data['password']]) : false;
        if (! $user) {
            Hash::check($data['password'], '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        }
        if (! $valid) {
            $this->failed($user, $identifier, 'failed');
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['email' => 'The supplied credentials are invalid.']);
        }
        if (! app(Tenancy::class)->usable($user)) {
            $this->failed($user, $identifier, 'failed');
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['email' => 'This account is unavailable. Contact your administrator.']);
        }
        if (! $verified) {
            app(Challenges::class)->start($request, 'activate', $channel, $identifier, []);
            RateLimiter::clear($key);

            return redirect()->route('identity.verify')->with('status', 'Verify this contact to enable sign-in with it.');
        }
        RateLimiter::clear($key);
        Auth::login($user);
        app(Foundation::class)->audit($user, 'identity.signed_in', $user, ['channel' => $channel]);
        $request->session()->regenerate();
        $request->session()->put('auth_version', $request->user()->auth_version ?? 0);

        return redirect()->intended(route('dashboard.overview'));
    }

    private function failed(?User $user, string $identifier, string $event): void
    {
        Log::notice('identity.signin_'.$event, ['identifier_hash' => hash('sha256', $identifier)]);
        if ($user) {
            app(Foundation::class)->audit($user, 'identity.signin_'.$event, $user);
        }
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
