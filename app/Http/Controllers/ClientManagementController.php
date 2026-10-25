<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ClientAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Broadcast\Engine;
use App\Services\ClientAuth\DeliveryException;
use App\Services\ClientAuth\Recovery;
use App\Services\ClientAuth\Registration;
use App\Services\Clients\Capabilities;
use App\Services\Clients\Invitations;
use App\Services\Clients\Management;
use App\Services\Clients\Portal;
use App\Services\Foundation;
use App\Services\Reports\Exports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ClientManagementController extends Controller
{
    public const SECTIONS = ['overview', 'registrations', 'profiles', 'users', 'verification', 'wallets', 'pricing', 'usage', 'restrictions', 'activity', 'settings'];

    public function organization(Request $r, Management $m)
    {
        return $this->show($r, $r->user()->clientAccount()->firstOrFail(), $m);
    }

    public function team(Request $r, Management $m)
    {
        $r->merge(['tab' => 'users']);

        return $this->organization($r, $m);
    }

    public function resend(Request $r, string $challenge)
    {
        try {
            app(Registration::class)->administrativeResend($r->user(), $challenge);
        } catch (DeliveryException $e) {
            throw ValidationException::withMessages(['registration' => 'Verification delivery unavailable.']);
        }

        return back()->with('status', 'Registration code resent under the existing delivery limits. The original browser verification session is still required.');
    }

    public function filters(Request $r): array
    {
        return $r->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:active,suspended', 'verification' => 'nullable|in:incomplete,business_pending', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'.($r->filled('from') ? '|after_or_equal:from' : ''), 'plan_id' => 'nullable|integer|min:1']);
    }

    public function index(Request $r, Management $m, string $section = 'overview')
    {
        $m->staff($r->user());
        abort_unless(in_array($section, self::SECTIONS, true), 404);
        if ($section === 'activity') {
            $m->staff($r->user(), 'clients.activity');
        }
        if ($section === 'registrations') {
            $m->staff($r->user(), 'clients.users');
        }
        if (in_array($section, ['wallets', 'pricing'], true)) {
            abort_unless($r->user()->can('billing.view'), 403);
        }
        if ($section === 'usage') {
            abort_unless($r->user()->can('reports.view'), 403);
        }
        $filters = $this->filters($r);
        $query = $m->directory($filters);
        $ids = (clone $query)->select('client_accounts.id');
        $stats = ['total' => (clone $query)->count(), 'active' => (clone $query)->where('status', 'active')->count(), 'suspended' => (clone $query)->where('status', 'suspended')->count(), 'new_today' => (clone $query)->where('created_at', '>=', now('UTC')->startOfDay())->count(), 'new_week' => (clone $query)->where('created_at', '>=', now('UTC')->startOfWeek())->count(), 'new_month' => (clone $query)->where('created_at', '>=', now('UTC')->startOfMonth())->count(), 'new_year' => (clone $query)->where('created_at', '>=', now('UTC')->startOfYear())->count(), 'incomplete' => User::whereIn('client_account_id', clone $ids)->where(fn ($q) => $q->whereNull('email_verified_at')->orWhereNull('phone_verified_at'))->distinct()->count('client_account_id')];
        foreach (['running', 'scheduled', 'completed'] as $state) {
            $stats['accounts_'.$state] = DB::table('campaigns')->whereIn('client_account_id', clone $ids)->where('status', $state)->distinct()->count('client_account_id');
        }
        if ($r->user()->can('billing.view')) {
            $stats['zero_available_credit'] = DB::table('wallets')->whereIn('client_account_id', clone $ids)->whereRaw('balance_minor - reserved_minor <= 0')->count();
            $stats['low_credit_alerts'] = DB::table('wallets as w')->join('users as u', 'u.client_account_id', '=', 'w.client_account_id')->join('notification_preferences as p', 'p.user_id', '=', 'u.id')->whereIn('w.client_account_id', clone $ids)->where('u.client_access_enabled', true)->where('p.low_balance_minor', '>', 0)->whereRaw('w.balance_minor - w.reserved_minor <= p.low_balance_minor')->distinct()->count('w.client_account_id');
        }
        $topCalls = DB::table('call_attempts as a')->join('campaign_recipients as r', 'r.id', '=', 'a.campaign_recipient_id')->join('campaigns as c', 'c.id', '=', 'r.campaign_id')->whereIn('c.client_account_id', clone $ids)->selectRaw('c.client_account_id, COUNT(*) as total')->groupBy('c.client_account_id')->orderByDesc('total')->limit(10)->get();
        $topCharges = $r->user()->can('billing.view') ? DB::table('wallet_ledger_entries as l')->join('wallets as w', 'w.id', '=', 'l.wallet_id')->whereIn('w.client_account_id', clone $ids)->where('l.kind', 'call_charge')->selectRaw('w.client_account_id, w.currency, w.minor_digits, -SUM(l.amount_minor) as total')->groupBy('w.client_account_id', 'w.currency', 'w.minor_digits')->orderBy('w.currency')->orderByDesc('total')->limit(50)->get() : collect();
        $counts = DB::table('campaigns')->whereIn('client_account_id', clone $ids)->selectRaw('client_account_id, count(*) as total')->groupBy('client_account_id')->orderByDesc('total')->limit(10)->get();
        $trends = (clone $query)->select([])->selectRaw('DATE(created_at) AS day, COUNT(*) AS total')->groupByRaw('DATE(created_at)')->orderByDesc('day')->limit(31)->get();

        return view('client-management.index', ['section' => $section, 'filters' => $filters, 'stats' => $stats, 'topClients' => $counts, 'topCalls' => $topCalls, 'topCharges' => $topCharges, 'trends' => $trends, 'accounts' => $query->latest('id')->paginate(25)->withQueryString(), 'registrations' => $section === 'registrations' ? DB::table('client_otp_logs')->where('purpose', 'registration')->latest('id')->paginate(25, ['id', 'challenge_id', 'client_account_id', 'channel', 'recipient_hint', 'status', 'verification_attempts', 'sent_at', 'expires_at', 'verified_at', 'consumed_at'], 'registrations_page') : null, 'activity' => $section === 'activity' ? AuditLog::whereIn('client_account_id', $ids)->latest('id')->paginate(50, ['id', 'actor_id', 'client_account_id', 'action', 'entity_type', 'entity_id', 'created_at'], 'activity_page') : null]);
    }

    public function show(Request $r, ClientAccount $account, Management $m)
    {
        if ($r->user()->isClient()) {
            $m->manage($r->user(), $account, 'clients.view', 'profile.manage');
        } else {
            $m->staff($r->user());
        }
        $tab = $r->validate(['tab' => 'nullable|in:overview,profile,users,contacts,recordings,campaigns,wallet,usage,compliance,restrictions,activity,settings,pricing'])['tab'] ?? 'overview';
        $client = $r->user()->isClient();
        if ($client) {
            abort_unless(in_array($tab, ['overview', 'profile', 'users', 'compliance', 'settings'], true), 403);
        }
        if ($tab === 'users') {
            $m->manage($r->user(), $account, 'clients.users', 'users.manage');
        }
        if ($tab === 'activity') {
            $m->staff($r->user(), 'clients.activity');
        }
        if (in_array($tab, ['wallet', 'pricing'], true)) {
            abort_unless($r->user()->can('billing.view'), 403);
        }
        if ($tab === 'usage') {
            abort_unless($r->user()->can('reports.view'), 403);
        }
        $wallet = ! $client && $r->user()->can('billing.view') ? Wallet::where('client_account_id', $account->id)->first() : null;
        $campaigns = DB::table('campaigns')->where('client_account_id', $account->id);
        $calls = DB::table('call_attempts as a')->join('campaign_recipients as r', 'r.id', '=', 'a.campaign_recipient_id')->join('campaigns as c', 'c.id', '=', 'r.campaign_id')->where('c.client_account_id', $account->id);
        $data = ['account' => $account, 'tab' => $tab, 'clientSurface' => $client, 'unread' => $client ? app(Portal::class)->unread($r->user()) : 0, 'members' => $account->users()->orderBy('id')->paginate(25, ['id', 'name', 'email', 'phone_e164', 'email_verified_at', 'phone_verified_at', 'client_role', 'client_access_enabled', 'last_login_at', 'terms_version', 'terms_accepted_at']), 'documents' => DB::table('client_documents')->where('client_account_id', $account->id)->latest('id')->paginate(20, ['*'], 'documents_page'), 'invitations' => $tab === 'users' ? DB::table('client_invitations')->where('client_account_id', $account->id)->latest('id')->paginate(20, ['id', 'email', 'name', 'role', 'expires_at', 'accepted_at', 'revoked_at'], 'invitations_page') : null, 'wallet' => $wallet, 'stats' => ['campaigns' => (clone $campaigns)->count(), 'running' => (clone $campaigns)->where('status', 'running')->count(), 'scheduled' => (clone $campaigns)->where('status', 'scheduled')->count(), 'completed' => (clone $campaigns)->where('status', 'completed')->count(), 'calls' => (clone $calls)->count(), 'answered' => (clone $calls)->whereNotNull('a.answered_at')->count(), 'playback' => (clone $calls)->whereNotNull('a.playback_completed_at')->count()], 'documentsValid' => $m->validDocument($account->id)];
        $data['records'] = match ($tab) {
            'contacts' => DB::table('contacts')->where('client_account_id', $account->id)->latest('id')->paginate(25, ['id', 'name', 'phone_e164', 'created_at'], 'records_page'),
            'recordings' => DB::table('audio_files')->where('client_account_id', $account->id)->latest('id')->paginate(25, ['id', 'name', 'status', 'created_at'], 'records_page'),
            'campaigns' => (clone $campaigns)->latest('id')->paginate(25, ['id', 'name', 'status', 'created_at'], 'records_page'),
            'activity' => AuditLog::where('client_account_id', $account->id)->latest('id')->paginate(50, ['id', 'actor_id', 'action', 'entity_type', 'entity_id', 'created_at'], 'records_page'),
            default => null,
        };

        return response()->view('client-management.show', $data)->header('Cache-Control', 'private, no-store');
    }

    public function profile(Request $r, ClientAccount $account, Management $m)
    {
        $d = $r->validate(['name' => 'required|string|max:255', 'registered_name' => 'nullable|string|max:255', 'trading_name' => 'nullable|string|max:255', 'business_type' => 'nullable|string|max:100', 'contact_name' => 'nullable|string|max:255', 'contact_title' => 'nullable|string|max:100', 'country' => 'required|in:GH', 'region' => 'nullable|string|max:100', 'city' => 'nullable|string|max:100', 'address' => 'nullable|string|max:500', 'registration_number' => 'nullable|string|max:100', 'tax_number' => 'nullable|string|max:100', 'reason' => 'required|string|max:1000']);
        $m->profile($r->user(), $account, $d);

        return back()->with('status', 'Business profile saved. Changed identity requires a new business review.');
    }

    public function settings(Request $r, ClientAccount $account, Management $m)
    {
        $m->manage($r->user(), $account, 'clients.settings', 'profile.manage');
        $d = $r->validate(['timezone' => 'required|timezone', 'language' => 'required|in:en', 'import_country' => 'required|in:GH', 'account_manager_id' => 'nullable|integer', 'reason' => 'required|string|max:1000']);
        DB::transaction(function () use ($r, $account, $d) {
            $a = ClientAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $a->settings = collect($d)->only(['timezone', 'language', 'import_country'])->all();
            if (array_key_exists('account_manager_id', $d)) {
                abort_if($r->user()->isClient(), 403);
                abort_if($d['account_manager_id'] && ! User::whereKey($d['account_manager_id'])->whereNull('client_account_id')->whereDoesntHave('roles', fn ($q) => $q->where('name', 'Client'))->exists(), 422, 'Choose an internal account manager.');
                $a->account_manager_id = $d['account_manager_id'];
            }
            $a->save();
            app(Foundation::class)->audit($r->user(), 'client.settings_changed', $a, ['settings' => $a->settings, 'reason' => $d['reason']]);
        });

        return back()->with('status', 'Organization defaults saved. Existing campaigns and personal preferences are unchanged.');
    }

    public function restrictions(Request $r, ClientAccount $account, Management $m)
    {
        $d = $r->validate(['launch_blocked' => 'required|boolean', 'dispatch_blocked' => 'required|boolean', 'require_both_contacts' => 'required|boolean', 'require_business_verification' => 'required|boolean', 'max_active_campaigns' => 'nullable|integer|min:0|max:100', 'daily_spend_limit' => 'sometimes|nullable|string|max:20', 'reason' => 'required|string|max:1000']);
        $m->restrictions($r->user(), $account, $d);

        return back()->with('status', 'Restrictions saved. Existing calls may finish; changing restrictions never resumes campaigns automatically.');
    }

    public function member(Request $r, ClientAccount $account, User $member, Management $m)
    {
        $d = $r->validate(['role' => ['required', Rule::in(Capabilities::ROLES)], 'enabled' => 'required|boolean', 'reason' => 'required|string|max:1000']);
        $m->user($r->user(), $account, $member, $d['role'], (bool) $d['enabled'], $d['reason']);

        return back()->with('status', 'User access saved; sessions and API credentials revoked.');
    }

    public function sessions(Request $r, ClientAccount $account, User $member, Management $m)
    {
        $m->manage($r->user(), $account, 'clients.users', 'users.manage');
        abort_unless((int) $member->client_account_id === (int) $account->id, 404);
        $r->validate(['reason' => 'required|string|max:1000']);
        if ($r->user()->isClient() && $member->client_role === 'owner') {
            abort_unless($r->user()->client_role === 'owner', 403);
        }
        $m->revokeSessions($member);
        app(Foundation::class)->audit($r->user(), 'client.sessions_revoked', $account, ['user_id' => $member->id, 'reason' => $r->reason]);

        return back()->with('status', 'Database sessions terminated.');
    }

    public function recovery(Request $r, ClientAccount $account, User $member, Management $m)
    {
        $m->manage($r->user(), $account, 'clients.users', 'users.manage');
        abort_unless((int) $member->client_account_id === (int) $account->id, 404);
        if ($r->user()->isClient() && $member->client_role === 'owner') {
            abort_unless($r->user()->client_role === 'owner', 403);
        }
        app(Recovery::class)->send($member->email_verified_at ? $member->email : ($member->phone_e164 ?? ''));
        app(Foundation::class)->audit($r->user(), 'client.recovery_requested', $account, ['user_id' => $member->id]);

        return back()->with('status', 'Recovery requested through the existing verified-contact flow. No password is disclosed.');
    }

    public function invite(Request $r, ClientAccount $account, Invitations $invitations)
    {
        $d = $r->validate(['name' => 'required|string|max:255', 'email' => 'required|email:rfc|max:255', 'role' => ['required', Rule::in(Capabilities::ROLES)]]);
        try {
            $invitations->send($r->user(), $account, $d);
        } catch (DeliveryException $e) {
            throw ValidationException::withMessages(['email' => 'Invitation delivery unavailable. No invitation was committed.']);
        }

        return back()->with('status', 'Invitation sent; it expires in 48 hours.');
    }

    public function revokeInvitation(Request $r, ClientAccount $account, int $invitation, Management $m)
    {
        $m->manage($r->user(), $account, 'clients.users', 'users.manage');
        DB::transaction(function () use ($r, $account, $invitation) {
            $i = DB::table('client_invitations')->where('id', $invitation)->where('client_account_id', $account->id)->lockForUpdate()->firstOrFail();
            if ($r->user()->isClient() && $i->role === 'owner') {
                abort_unless($r->user()->client_role === 'owner', 403);
            }
            abort_if($i->accepted_at, 422);
            DB::table('client_invitations')->where('id', $i->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
            app(Foundation::class)->audit($r->user(), 'client.invitation_revoked', $account, ['invitation_id' => $i->uuid]);
        });

        return back()->with('status', 'Invitation revoked.');
    }

    public function invitation(Request $r, Invitations $invitations, string $uuid, string $token)
    {
        $invitation = $invitations->valid($uuid, $token);
        if ($r->isMethod('POST')) {
            $d = $r->validate(['password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()], 'terms' => 'accepted']);
            $user = $invitations->accept($uuid, $token, $d['password']);

            return redirect()->route('login')->with('status', 'Invitation accepted. Sign in with your verified email and new password.');
        }

        return response()->view('client-management.invitation', compact('invitation', 'uuid', 'token'))->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function upload(Request $r, ClientAccount $account, Management $m)
    {
        $m->manage($r->user(), $account, 'clients.profile', 'profile.manage');
        $d = $r->validate(['name' => 'required|string|max:255', 'document' => 'required|file|max:5120|mimes:pdf,png,jpg,jpeg', 'expires_on' => 'nullable|date_format:Y-m-d|after_or_equal:today']);
        $uuid = (string) Str::uuid();
        $key = 'client-documents/'.$account->uuid.'/'.$uuid;
        $file = $r->file('document');
        Storage::disk('local')->put($key, $file->getContent());
        try {
            DB::transaction(function () use ($r, $account, $d, $file, $uuid, $key) {
                ClientAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
                abort_if(DB::table('client_documents')->where('client_account_id', $account->id)->count() >= 100, 422, 'Account document limit reached.');
                DB::table('client_documents')->insert(['uuid' => $uuid, 'client_account_id' => $account->id, 'uploaded_by' => $r->user()->id, 'name' => $d['name'], 'storage_key' => $key, 'checksum' => hash_file('sha256', $file->getRealPath()), 'mime' => $file->getMimeType(), 'expires_on' => $d['expires_on'] ?? null, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
                app(Foundation::class)->audit($r->user(), 'client.document_uploaded', $account, ['document_id' => $uuid]);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($key);
            throw $e;
        }

        return back()->with('status', 'Document stored privately for business review. Audio approval is separate.');
    }

    public function download(Request $r, ClientAccount $account, string $document, Management $m)
    {
        if ($r->user()->isClient()) {
            $m->manage($r->user(), $account, 'clients.view', 'profile.manage');
        } else {
            $m->staff($r->user(), 'clients.verify');
        }
        $d = DB::table('client_documents')->where('uuid', $document)->where('client_account_id', $account->id)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($d->storage_key) && hash_equals($d->checksum, hash_file('sha256', Storage::disk('local')->path($d->storage_key))), 410);
        app(Foundation::class)->audit($r->user(), 'client.document_downloaded', $account, ['document_id' => $d->uuid]);
        $extension = match ($d->mime) {
            'application/pdf' => 'pdf', 'image/png' => 'png', default => 'jpg'
        };

        return Storage::disk('local')->download($d->storage_key, 'business-document.'.$extension, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => $d->mime]);
    }

    public function review(Request $r, ClientAccount $account, Management $m)
    {
        $m->staff($r->user(), 'clients.verify');
        $d = $r->validate(['document_id' => 'nullable|integer', 'status' => 'required|in:approved,rejected', 'reason' => 'required|string|max:2000']);
        DB::transaction(function () use ($r, $account, $d, $m) {
            app(Engine::class)->mutex();
            $a = ClientAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            if (! empty($d['document_id'])) {
                $doc = DB::table('client_documents')->where('id', $d['document_id'])->where('client_account_id', $a->id)->lockForUpdate()->firstOrFail();
                if ($d['status'] === 'approved') {
                    abort_unless((! $doc->expires_on || $doc->expires_on >= now('UTC')->toDateString()) && Storage::disk('local')->exists($doc->storage_key) && hash_equals($doc->checksum, hash_file('sha256', Storage::disk('local')->path($doc->storage_key))), 422, 'Document is expired, missing or changed.');
                }
                DB::table('client_documents')->where('id', $doc->id)->update(['status' => $d['status'], 'review_note' => $d['reason'], 'reviewed_by' => $r->user()->id, 'reviewed_at' => now(), 'updated_at' => now()]);
            }
            if ($d['status'] === 'approved') {
                abort_unless($m->validDocument($a->id), 422, 'Approve a current supporting document first.');
            }
            $a->update(['business_verification' => $d['status'] === 'approved' ? 'verified' : 'rejected', 'business_verified_at' => $d['status'] === 'approved' ? now() : null, 'verification_note' => $d['reason']]);
            app(Foundation::class)->audit($r->user(), 'client.business_reviewed', $a, $d);
        });

        return back()->with('status', 'Business review recorded. Email, phone, audio and campaign approvals are unchanged.');
    }

    public function export(Request $r, Management $m)
    {
        $m->staff($r->user(), 'clients.export');
        $query = $m->directory($this->filters($r));
        abort_if((clone $query)->count() > 5000, 422, 'Narrow the directory filters to at most 5,000 accounts.');
        app(Foundation::class)->audit($r->user(), 'client.directory_exported', $r->user(), ['rows' => (clone $query)->count()]);

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Client ID', 'Name', 'Status', 'Created UTC', 'Users'], ',', '"', '');
            foreach ($query->orderBy('id')->cursor() as $a) {
                fputcsv($out, array_map([Exports::class, 'csvCell'], [$a->id, $a->name, $a->status, $a->created_at, $a->users_count]), ',', '"', '');
            }
            fclose($out);
        }, 'voicecast-clients.csv', ['Content-Type' => 'text/csv', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
