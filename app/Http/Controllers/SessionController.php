<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\ClientAuth\Identity;
use App\Services\Foundation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        // Retain the email field for existing internal integrations.
        $request->merge(['identifier' => $request->input('identifier', $request->input('email'))]);
        $data = $request->validate(['identifier' => 'required|string|max:255', 'password' => 'required|string|max:128']);
        [$channel, $identifier] = Identity::parse($data['identifier']);
        $key = 'login:'.hash('sha256', $identifier);
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['email' => 'Too many sign-in attempts. Please wait a minute.']);
        }
        RateLimiter::hit($key, 60);
        $credentials = [Identity::column($channel) => $identifier, 'password' => $data['password']];
        if (! Auth::attempt($credentials)) {
            $known = User::where(Identity::column($channel), $identifier)->whereNotNull('client_account_id')->first();
            if ($known) {
                AuditLog::create(['actor_id' => null, 'action' => 'client.login_failed', 'entity_type' => User::class, 'entity_id' => $known->id, 'client_account_id' => $known->client_account_id, 'details' => [], 'created_at' => now()]);
            }
            throw ValidationException::withMessages(['email' => 'The supplied credentials are invalid.']);
        }
        if (Auth::user()->isClient() && (! Auth::user()->clientAccountActive() || (! Auth::user()->email_verified_at && ! Auth::user()->phone_verified_at))) {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'The supplied credentials are invalid or the account is unavailable.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        if (Auth::user()->isClient()) {
            Auth::user()->forceFill(['last_login_at' => now()])->save();
            app(Foundation::class)->audit(Auth::user(), 'client.login', Auth::user()->clientAccount);
            $request->session()->forget('url.intended');

            return redirect()->route('client.foundation');
        }

        return redirect()->intended('/dashboard');
    }

    public function destroy(Request $request)
    {
        if ($request->user()?->isClient() && $request->user()->clientAccount) {
            app(Foundation::class)->audit($request->user(), 'client.logout', $request->user()->clientAccount);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
