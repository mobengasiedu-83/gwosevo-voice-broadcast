<?php

namespace App\Http\Controllers;

use App\Models\Wallet;
use App\Services\Clients\Portal;
use App\Services\Foundation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientPortalController extends Controller
{
    private function shared(Request $request, Portal $portal): array
    {
        $portal->authorize($request->user());

        return ['account' => $request->user()->clientAccount()->firstOrFail(), 'unread' => $portal->unread($request->user())];
    }

    public function dashboard(Request $request, Portal $portal)
    {
        return view('clients.dashboard', $this->shared($request, $portal) + [
            'summary' => $portal->summary($request->user()),
            'campaigns' => $portal->campaigns($request->user())->latest()->limit(5)->get(['id', 'name', 'status', 'scheduled_at', 'timezone', 'created_at']),
            'notices' => $portal->notices($request->user())->orderByDesc('occurred_at')->orderByDesc('id')->limit(4)->get(),
        ]);
    }

    public function campaigns(Request $request, Portal $portal)
    {
        $data = $request->validate(['status' => 'nullable|in:draft,ready,scheduled,running,paused,completed,cancelled', 'q' => 'nullable|string|max:100']);
        $query = $portal->campaigns($request->user());
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['q'])) {
            $query->where('name', 'like', '%'.addcslashes($data['q'], '%_\\').'%');
        }

        return view('clients.campaigns', $this->shared($request, $portal) + ['campaigns' => $query->latest()->paginate(15, ['id', 'name', 'status', 'scheduled_at', 'timezone', 'created_at'])->withQueryString()]);
    }

    public function notifications(Request $request, Portal $portal)
    {
        $shared = $this->shared($request, $portal);
        $filters = $request->validate(['category' => 'nullable|in:audio,campaign,wallet,report', 'unread' => 'nullable|in:1']);
        $query = $portal->notices($request->user());
        if (! empty($filters['category'])) {
            $query->where('kind', 'like', $filters['category'].'.%');
        }
        if (! empty($filters['unread'])) {
            $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('client_notice_reads')->whereColumn('client_notice_id', 'client_notices.id')->where('user_id', $request->user()->id));
        }
        $wallet = Wallet::where('client_account_id', $request->user()->client_account_id)->first();

        return view('clients.notifications', $shared + [
            'notices' => $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate(20)->withQueryString(),
            'preferences' => DB::table('notification_preferences')->where('user_id', $request->user()->id)->first(),
            'wallet' => $wallet,
            'deliveries' => DB::table('notification_deliveries')->where('user_id', $request->user()->id)->latest('id')->limit(20)->get(),
        ]);
    }

    public function count(Request $request, Portal $portal)
    {
        return response()->json(['unread' => $portal->unread($request->user())])->header('Cache-Control', 'private, no-store');
    }

    public function preferences(Request $request, Portal $portal)
    {
        $portal->authorize($request->user());
        $data = $request->validate(['email_enabled' => 'nullable|boolean', 'sms_enabled' => 'nullable|boolean', 'low_balance' => 'nullable|string|max:20']);
        $email = (bool) ($data['email_enabled'] ?? false);
        $sms = (bool) ($data['sms_enabled'] ?? false);
        if (($email && ! $request->user()->email_verified_at) || ($sms && ! $request->user()->phone_verified_at)) {
            throw ValidationException::withMessages(['channels' => 'Verify the selected email address or phone before enabling alerts.']);
        }
        $wallet = Wallet::where('client_account_id', $request->user()->client_account_id)->first();
        $amount = trim($data['low_balance'] ?? '0');
        $digits = (int) ($wallet?->minor_digits ?? 0);
        if (! preg_match('/\A([0-9]{1,9})(?:\.([0-9]{1,6}))?\z/', $amount, $m) || strlen($m[2] ?? '') > $digits) {
            throw ValidationException::withMessages(['low_balance' => 'Enter a nonnegative threshold within your wallet precision.']);
        }
        $minor = (int) $m[1] * 10 ** $digits + (int) str_pad($m[2] ?? '', $digits, '0');
        if (! $wallet && $minor > 0) {
            throw ValidationException::withMessages(['low_balance' => 'A configured wallet is required for credit alerts.']);
        }
        DB::transaction(function () use ($request, $email, $sms, $minor) {
            $pref = DB::table('notification_preferences')->where('user_id', $request->user()->id)->lockForUpdate()->first();
            $enabledAt = $pref?->channels_enabled_at;
            if (($email && ! $pref?->email_enabled) || ($sms && ! $pref?->sms_enabled)) {
                $enabledAt = now();
            }
            DB::table('notification_preferences')->updateOrInsert(['user_id' => $request->user()->id], [
                'email_enabled' => $email, 'sms_enabled' => $sms, 'low_balance_minor' => $minor,
                'channels_enabled_at' => $enabledAt, 'created_at' => $pref?->created_at ?? now(), 'updated_at' => now(),
            ]);
        });
        app(Foundation::class)->audit($request->user(), 'notifications.preferences_updated', $request->user(), ['email_enabled' => $email, 'sms_enabled' => $sms, 'low_balance_minor' => $minor]);

        return redirect()->route('client.notifications')->with('status', 'Notification preferences saved. Channel delivery also requires configured services.');
    }

    public function read(Request $request, Portal $portal, int $notice)
    {
        $portal->markRead($request->user(), $notice);

        return back()->with('status', 'Notification marked as read.');
    }

    public function readAll(Request $request, Portal $portal)
    {
        $portal->markAllRead($request->user());

        return back()->with('status', 'Notifications marked as read.');
    }
}
