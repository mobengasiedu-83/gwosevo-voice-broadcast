<?php

namespace App\Http\Controllers;

use App\Services\ClientAuth\ContactVerification;
use App\Services\ClientAuth\DeliveryException;
use App\Services\ClientAuth\Recovery;
use App\Services\ClientAuth\Registration;
use App\Services\Clients\Portal;
use App\Services\Foundation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ClientAuthController extends Controller
{
    private function passwordRules(): array
    {
        return ['required', 'string', 'max:128', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()];
    }

    public function register()
    {
        abort_unless(config('client_auth.registration_enabled'), 404);

        return view('client-auth.register');
    }

    public function start(Request $request, Registration $registration)
    {
        $data = $request->only(['name', 'organization', 'email', 'phone', 'country', 'verification_channel', 'terms']);
        try {
            [$id, $secret] = $registration->start($data);
        } catch (DeliveryException $e) {
            throw ValidationException::withMessages(['identifier' => 'Unable to send a verification code. Please try again later or use the other verification channel.']);
        }
        $request->session()->put('client.registration', ['id' => $id, 'secret' => $secret]);

        return redirect()->route('client.register.verify')->with('status', 'Verification code sent. It expires in 5 minutes.');
    }

    private function credentials(Request $request): array
    {
        $state = $request->session()->get('client.registration');
        if (! is_array($state) || empty($state['id']) || empty($state['secret'])) {
            throw ValidationException::withMessages(['code' => 'Start registration to request a verification code.']);
        }

        return [$state['id'], $state['secret']];
    }

    public function verification(Request $request, Registration $registration)
    {
        if (! $request->session()->has('client.registration')) {
            return redirect()->route('client.register');
        }
        try {
            $c = $registration->challenge(...$this->credentials($request));
        } catch (ValidationException $e) {
            $request->session()->forget('client.registration');

            return redirect()->route('client.register')->withErrors($e->errors());
        }

        return view('client-auth.verify', ['challenge' => $c]);
    }

    public function verify(Request $request, Registration $registration)
    {
        $data = $request->validate(['code' => 'required|digits:6']);
        [$id, $secret] = $this->credentials($request);
        $registration->verify($id, $secret, $data['code']);

        return redirect()->route('client.register.verify')->with('status', 'Verified. Create a strong password to finish.');
    }

    public function resend(Request $request, Registration $registration)
    {
        try {
            $registration->resend(...$this->credentials($request));
        } catch (DeliveryException $e) {
            throw ValidationException::withMessages(['code' => 'Unable to send a verification code. Please try again later.']);
        }

        return back()->with('status', 'A new code has been sent. The previous code is no longer valid.');
    }

    public function finish(Request $request, Registration $registration)
    {
        $data = $request->validate(['password' => $this->passwordRules()]);
        [$id, $secret] = $this->credentials($request);
        $user = $registration->finish($id, $secret, $data['password']);
        $request->session()->forget('client.registration');
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('client.foundation')->with('status', 'Your account is ready. Use your email or phone and password to sign in.');
    }

    public function forgot()
    {
        return view('client-auth.forgot');
    }

    public function sendRecovery(Request $request, Recovery $recovery)
    {
        $data = $request->validate(['identifier' => 'required|string|max:255']);
        $recovery->send($data['identifier']);

        return back()->with('status', 'If an active client account matches, a recovery link has been sent to its verified address or number.');
    }

    public function resetForm(string $id, string $token, Recovery $recovery)
    {
        try {
            $recovery->valid($id, $token);
        } catch (ValidationException $e) {
            return redirect()->route('client.password.forgot')->withErrors($e->errors());
        }

        return response()->view('client-auth.reset', compact('id', 'token'))->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }

    public function reset(Request $request, string $id, string $token, Recovery $recovery)
    {
        $data = $request->validate(['password' => $this->passwordRules()]);
        $recovery->reset($id, $token, $data['password']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Password reset. Sign in with your new password.');
    }

    public function settings(Request $request)
    {
        abort_unless($request->user()->isClient(), 403);

        return view('client-auth.settings', ['user' => $request->user(), 'account' => $request->user()->clientAccount()->firstOrFail(), 'unread' => app(Portal::class)->unread($request->user())]);
    }

    public function requestContact(Request $request, ContactVerification $verification)
    {
        app(Portal::class)->authorize($request->user());
        $data = $request->validate(['channel' => 'required|in:email,phone', 'contact' => 'required|string|max:255', 'current_password' => 'required|current_password:web']);
        try {
            [$id, $secret] = $verification->start($request->user(), $data['channel'], $data['contact']);
        } catch (DeliveryException $e) {
            throw ValidationException::withMessages(['contact' => 'Unable to send a verification code. Try again later.']);
        }
        $request->session()->put('client.contact_verification', compact('id', 'secret'));

        return back()->with('status', 'Code sent to the selected contact. It expires in five minutes. Your current contact remains active until verification.');
    }

    public function verifyContact(Request $request, ContactVerification $verification)
    {
        $data = $request->validate(['code' => 'required|digits:6']);
        $state = $request->session()->get('client.contact_verification');
        if (! is_array($state) || empty($state['id']) || empty($state['secret'])) {
            throw ValidationException::withMessages(['code' => 'Request a contact verification code first.']);
        }
        $verification->verify($request->user(), $state['id'], $state['secret'], $data['code']);
        $request->session()->forget('client.contact_verification');
        $request->session()->regenerate();

        return back()->with('status', 'Contact verified and saved.');
    }

    public function update(Request $request)
    {
        abort_unless($request->user()->isClient(), 403);
        $data = $request->validate(['name' => 'required|string|max:255', 'current_password' => 'required|current_password:web', 'password' => $request->filled('password') ? $this->passwordRules() : ['nullable']]);
        DB::transaction(function () use ($request, $data) {
            $user = $request->user();
            $user->name = $data['name'];
            if (! empty($data['password'])) {
                $user->password = $data['password'];
                $user->remember_token = Str::random(60);
                DB::table('sessions')->where('user_id', $user->id)->delete();
                DB::table('client_auth_challenges')->where('purpose', 'recovery')->whereJsonContains('details->user_id', $user->id)->update(['consumed_at' => now()]);
            }
            $user->save();
            app(Foundation::class)->audit($user, 'client.settings_updated', $user->clientAccount);
        });
        $request->session()->regenerate();

        return back()->with('status', 'Account settings updated.');
    }
}
