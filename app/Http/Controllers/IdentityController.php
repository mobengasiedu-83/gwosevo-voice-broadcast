<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\User;
use App\Services\Clients\Tenancy;
use App\Services\Foundation;
use App\Services\Identity\Challenges;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class IdentityController extends Controller
{
    public function register()
    {
        abort_unless(config('client_auth.registration_enabled'), 404);

        return view('auth.start', ['purpose' => 'register']);
    }

    public function recovery()
    {
        return view('auth.start', ['purpose' => 'recover']);
    }

    public function startRegistration(Request $request, Challenges $challenges)
    {
        abort_unless(config('client_auth.registration_enabled'), 404);
        $details = $request->validate(['name' => 'required|string|max:255', 'account_name' => 'required|string|max:255', 'country' => 'required|string|max:80', 'broadcasting_purpose' => 'required|string|max:1000', 'terms' => 'required|accepted']);
        $identity = $request->validate(['channel' => ['required', Rule::in($this->channels())], 'email' => 'required|string|max:254', 'phone' => 'required|string|max:32']);
        $details['email'] = $challenges->normalize('email', $identity['email'], 'email');
        $details['phone_e164'] = $challenges->normalize('sms', $identity['phone'], 'phone');
        $details['terms_version'] = config('client_auth.terms_version');
        $details['accepted_at'] = now()->toISOString();
        $challenges->start($request, 'register', $identity['channel'], $identity['channel'] === 'email' ? $details['email'] : $details['phone_e164'], $details);

        return redirect()->route('identity.verify')->with('status', 'If this contact is eligible, a verification code has been sent.');
    }

    public function startRecovery(Request $request, Challenges $challenges)
    {
        $identity = $request->validate(['channel' => ['required', Rule::in($this->channels())], 'identifier' => 'required|string|max:254']);
        $challenges->start($request, 'recover', $identity['channel'], $identity['identifier'], []);

        return redirect()->route('identity.verify')->with('status', 'If this contact is eligible, a verification code has been sent.');
    }

    private function channels(): array
    {
        return config('client_auth.sms_driver') === 'txtconnect' ? ['email', 'sms'] : ['email'];
    }

    public function verification(Request $request)
    {
        abort_unless($request->session()->has('identity.id'), 404);

        return view('auth.verify');
    }

    public function verify(Request $request, Challenges $challenges)
    {
        $data = $request->validate(['code' => 'required|digits:6']);
        $challenges->verify($request, $data['code']);
        if ($request->session()->get('identity.purpose') === 'activate') {
            abort_if($request->user(), 403);
            $user = $challenges->consume($request, 'activate', function ($challenge, $details) {
                $user = User::whereKey($details['user_id'])->lockForUpdate()->firstOrFail();
                abort_unless(app(Tenancy::class)->usable($user), 403);
                $field = $challenge->channel === 'email' ? 'email' : 'phone_e164';
                abort_unless(($field === 'email' ? mb_strtolower($user->$field) : $user->$field) === $challenge->identifier, 422);
                $user->forceFill([$field === 'email' ? 'email_verified_at' : 'phone_verified_at' => now()])->save();
                app(Foundation::class)->audit($user, 'identity.contact_verified', $user, ['channel' => $challenge->channel]);

                return $user;
            });
            $request->session()->forget('identity');
            $request->session()->regenerate();
            Auth::login($user);
            $request->session()->put('auth_version', $user->auth_version ?? 0);

            return redirect()->route('dashboard.overview');
        }
        if ($request->session()->get('identity.purpose') === 'change') {
            $challenges->consume($request, 'change', function ($challenge, $details) use ($request) {
                abort_unless($request->user() && $details['user_id'] === $request->user()->id, 403);
                $user = User::whereKey($details['user_id'])->lockForUpdate()->firstOrFail();
                abort_unless(app(Tenancy::class)->usable($user), 403);
                $field = $challenge->channel === 'email' ? 'email' : 'phone_e164';
                abort_if(User::where($field, $challenge->identifier)->where('id', '!=', $user->id)->exists(), 422);
                $user->forceFill([$field => $challenge->identifier, $field === 'email' ? 'email_verified_at' : 'phone_verified_at' => now(), 'auth_version' => $user->auth_version + 1, 'remember_token' => Str::random(60)])->save();
                app(Foundation::class)->audit($user, 'identity.contact_changed', $user, ['channel' => $challenge->channel]);
            });
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', 'Contact verified and updated. Sign in again.');
        }

        return redirect()->route('identity.password');
    }

    public function resend(Request $request, Challenges $challenges)
    {
        $challenges->resend($request);

        return redirect()->route('identity.verify')->with('status', 'If eligible, a new code has been sent. Earlier codes no longer work.');
    }

    public function password(Request $request, Challenges $challenges)
    {
        abort_unless(in_array($request->session()->get('identity.purpose'), ['register', 'recover'], true), 404);
        if (! $challenges->ready($request)) {
            return redirect()->route('identity.verify')->withErrors(['code' => 'Verify your contact before choosing a password.']);
        }

        return view('auth.password');
    }

    public function setPassword(Request $request, Challenges $challenges)
    {
        abort_if($request->user(), 403);
        $data = $request->validate(['password' => ['required', 'confirmed', 'max:128', Password::min(12)->mixedCase()->numbers()->symbols()]]);
        $purpose = $request->session()->get('identity.purpose');
        abort_unless(in_array($purpose, ['register', 'recover'], true), 422);
        try {
            $user = $challenges->consume($request, $purpose, function ($challenge, $details) use ($purpose, $data) {
                if ($purpose === 'register') {
                    abort_unless(config('client_auth.registration_enabled'), 404);
                    // Challenges issued before both contacts were collected keep the verified identifier only.
                    $email = $details['email'] ?? ($challenge->channel === 'email' ? $challenge->identifier : null);
                    $phone = $details['phone_e164'] ?? ($challenge->channel === 'sms' ? $challenge->identifier : null);
                    $identities = app(Challenges::class);
                    if (($email && $identities->user('email', $email)) || ($phone && $identities->user('sms', $phone))) {
                        throw ValidationException::withMessages(['code' => 'Registration is unavailable. Try signing in or recovering your password.']);
                    }
                    $client = Client::create(['name' => $details['account_name'], 'status' => config('client_auth.auto_activate') ? 'active' : 'pending', 'profile' => ['country' => $details['country'], 'broadcasting_purpose' => $details['broadcasting_purpose']]]);
                    $user = new User;
                    $user->forceFill(['name' => $details['name'], 'password' => $data['password'], 'email' => $email, 'phone_e164' => $phone, 'email_verified_at' => $challenge->channel === 'email' ? now() : null, 'phone_verified_at' => $challenge->channel === 'sms' ? now() : null, 'terms_accepted_at' => $details['accepted_at'], 'terms_version' => $details['terms_version']])->save();
                    ClientMembership::create(['client_id' => $client->id, 'user_id' => $user->id, 'role' => 'owner', 'status' => 'active']);
                    $user->assignRole('Client Member');
                    app(Foundation::class)->audit($user, 'client.registered', $client, ['verified_channel' => $challenge->channel, 'terms_version' => $details['terms_version']]);

                    return $user;
                }
                $user = User::whereKey($details['user_id'])->lockForUpdate()->firstOrFail();
                abort_unless(app(Tenancy::class)->usable($user), 403);
                $field = $challenge->channel === 'email' ? 'email' : 'phone_e164';
                abort_unless(($field === 'email' ? mb_strtolower($user->$field) : $user->$field) === $challenge->identifier, 422);
                $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60), 'auth_version' => $user->auth_version + 1])->save();
                if (config('session.driver') === 'database') {
                    DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
                }
                app(Foundation::class)->audit($user, 'identity.password_reset', $user, ['channel' => $challenge->channel]);

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['code' => 'This contact is unavailable. Start again or sign in.']);
        }
        $request->session()->forget('identity');
        $request->session()->regenerate();
        if ($purpose === 'register' && app(Tenancy::class)->usable($user)) {
            Auth::login($user);
            $request->session()->put('auth_version', $user->auth_version ?? 0);

            return redirect()->route('dashboard.overview');
        }

        return redirect()->route('login')->with('status', $purpose === 'register' ? 'Registration complete. Your account is awaiting activation.' : 'Password updated. Sign in with your new password.');
    }

    public function security(Request $request)
    {
        abort_unless($request->user()->clientMembership()->exists(), 403);

        return response()->view('auth.security')->header('Cache-Control', 'private, no-store');
    }

    public function changeContact(Request $request, Challenges $challenges)
    {
        abort_unless($request->user()->clientMembership()->exists(), 403);
        $data = $request->validate(['channel' => ['required', Rule::in($this->channels())], 'identifier' => 'required|string|max:254', 'current_password' => 'required|current_password:web']);
        $challenges->start($request, 'change', $data['channel'], $data['identifier'], []);

        return redirect()->route('identity.verify')->with('status', 'If eligible, a code has been sent to your new contact.');
    }
}
