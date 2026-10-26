<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Clients\Accounts;
use App\Services\Clients\Tenancy;
use App\Services\Foundation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ClientAccountController extends Controller
{
    private function member(Request $request)
    {
        $tenancy = app(Tenancy::class);
        $membership = $tenancy->membership($request->user());
        abort_unless($membership && $tenancy->usable($request->user()), 403);

        return $membership;
    }

    public function profile(Request $request)
    {
        $membership = $this->member($request);

        return response()->view('client-dashboard.profile', ['client' => $membership->client])->header('Cache-Control', 'private, no-store');
    }

    public function saveProfile(Request $request, Accounts $accounts)
    {
        $this->member($request);
        $accounts->ownProfile($request->user(), $request->only(['name', 'account_type', 'business_name', 'contact_name', 'contact_email', 'contact_phone', 'country', 'address', 'broadcasting_purpose']));

        return back()->with('status', 'Account profile updated. Sign-in contacts remain managed through security verification.');
    }

    public function preferences(Request $request)
    {
        $this->member($request);
        $data = $request->validate(['notification_badge' => 'required|boolean']);
        DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $user->forceFill(['portal_preferences' => array_merge($user->portal_preferences ?? [], ['notification_badge' => (bool) $data['notification_badge']])])->save();
            app(Foundation::class)->audit($user, 'identity.preferences_updated', $user);
        });

        return back()->with('status', 'Notification display preference saved. Notices remain available in your inbox.');
    }

    public function security(Request $request)
    {
        abort_unless(app(Tenancy::class)->usable($request->user()), 403);
        $data = $request->validate([
            'action' => 'required|in:password,sessions',
            'current_password' => 'required|string|max:128',
            'password' => ['required_if:action,password', 'nullable', 'confirmed', 'max:128', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);
        $user = DB::transaction(function () use ($request, $data) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
            }
            $changes = ['auth_version' => $user->auth_version + 1, 'remember_token' => Str::random(60)];
            if ($data['action'] === 'password') {
                $changes['password'] = $data['password'];
            }
            $user->forceFill($changes)->save();
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
            }
            app(Foundation::class)->audit($user, $data['action'] === 'password' ? 'identity.password_changed' : 'identity.sessions_revoked', $user);

            return $user;
        });
        $request->user()->setRawAttributes($user->getAttributes(), true);
        $request->session()->regenerate();
        $request->session()->put('auth_version', $user->auth_version);

        return back()->with('status', $data['action'] === 'password' ? 'Password changed. Other sessions have been signed out.' : 'Other sessions have been signed out.');
    }
}
