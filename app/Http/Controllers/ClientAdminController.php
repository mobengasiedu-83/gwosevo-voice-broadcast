<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BillingDispute;
use App\Models\CallTariff;
use App\Models\Campaign;
use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\ContactImport;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Models\WalletReservation;
use App\Services\Billing\Tariffs;
use App\Services\Billing\Wallet;
use App\Services\Clients\Accounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ClientAdminController extends Controller
{
    // Authorize before loading any account or membership, including invalid IDs.
    private function client(int $id): Client
    {
        Gate::authorize('clients.manage');

        return Client::findOrFail($id);
    }

    public function index(Request $request)
    {
        Gate::authorize('clients.manage');
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:pending,active,suspended,closed', 'sort' => 'nullable|in:newest,oldest,name', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);
        $query = Client::query();
        if (! empty($filters['q'])) {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('uuid', 'like', $term)->orWhereHas('memberships.user', fn ($u) => $u->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('phone_e164', 'like', $term)));
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        if (($filters['sort'] ?? '') === 'name') {
            $query->orderBy('name')->orderBy('id');
        } else {
            $query->orderBy('id', ($filters['sort'] ?? '') === 'oldest' ? 'asc' : 'desc');
        }
        $clients = $query->withDisplayEmail()->withCount(['memberships', 'campaigns', 'contacts'])->paginate(20)->withQueryString();
        $counts = Client::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->view('clients.index', compact('clients', 'filters', 'counts'))->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Accounts $accounts)
    {
        Gate::authorize('clients.manage');
        $data = $request->validate(['name' => 'required|string|max:255']);
        $client = $accounts->create($request->user(), $data['name']);

        return redirect()->route('clients.show', [$client->id, 'tab' => 'profile'])->with('status', 'Client created as pending. Add its profile and users, then activate when ready.');
    }

    public function show(Request $request, int $client)
    {
        $client = $this->client($client);
        $filters = $request->validate(['tab' => 'nullable|in:overview,profile,users,campaigns,activity,finance', 'activity' => 'nullable|in:all,logins,account', 'campaign_status' => 'nullable|in:draft,ready,pending_approval,approved,scheduled,running,paused,completed,cancelled,failed', 'ledger_type' => 'nullable|in:'.implode(',', Wallet::TYPES), 'reservation_state' => 'nullable|in:held,settled,released,shortfall']);
        $tab = $filters['tab'] ?? 'overview';
        $client->loadCount(['contacts', 'campaigns', 'imports', 'recordings', 'memberships']);
        $memberships = $campaigns = $imports = $audits = null;
        $usage = $financeWallet = $financeTariff = $financeAffordability = $financeSummary = $financeEntries = $financeReservations = $financeVerification = null;
        $financeTariffs = $financePayments = $financeDisputes = null;
        if (in_array($tab, ['overview', 'users'])) {
            $memberships = $client->memberships()->with('user')->orderBy('id')->paginate(20, ['*'], 'users_page')->withQueryString();
        }
        if (in_array($tab, ['overview', 'campaigns'])) {
            $campaigns = Campaign::where('client_id', $client->id)->when($filters['campaign_status'] ?? null, fn ($q, $status) => $q->where('status', $status))->latest('id')->paginate(15, ['*'], 'campaigns_page')->withQueryString();
        }
        if (in_array($tab, ['overview', 'activity'])) {
            $usage = DB::table('call_attempts as a')->join('campaign_recipients as r', 'r.id', '=', 'a.campaign_recipient_id')->join('campaigns as c', 'c.id', '=', 'r.campaign_id')->where('c.client_id', $client->id)->selectRaw('COUNT(*) as attempts, SUM(CASE WHEN a.sent_at IS NOT NULL THEN 1 ELSE 0 END) as sent, SUM(CASE WHEN a.answered_at IS NOT NULL THEN 1 ELSE 0 END) as answered, SUM(CASE WHEN a.playback_completed_at IS NOT NULL THEN 1 ELSE 0 END) as played')->first();
            $imports = ContactImport::where('client_id', $client->id)->latest('id')->paginate(10, ['*'], 'imports_page')->withQueryString();
            $query = AuditLog::where('client_id', $client->id);
            if (($filters['activity'] ?? '') === 'logins') {
                $query->whereIn('action', ['identity.signed_in', 'identity.signin_failed', 'identity.signin_locked', 'identity.contact_verified', 'identity.sessions_revoked', 'identity.password_changed', 'identity.password_reset', 'client.registered']);
            } elseif (($filters['activity'] ?? '') === 'account') {
                $query->where('action', 'like', 'client.%');
            }
            $audits = $query->latest('id')->paginate(20, ['*'], 'audit_page')->withQueryString();
        }

        if ($tab === 'finance' && $request->user()->can('billing.manage')) {
            $financeWallet = $client->wallet;
            $financeTariff = app(Tariffs::class)->snapshot($client->id);
            $financeTariffs = CallTariff::orderBy('code')->orderByDesc('version')->get();
            $financePayments = PaymentIntent::where('client_id', $client->id)->orderByDesc('id')
                ->limit(10)->get(['public_id', 'amount_minor', 'currency', 'minor_unit_scale', 'status', 'created_at', 'credited_at']);
            $financeDisputes = BillingDispute::where('client_id', $client->id)->orderByDesc('id')
                ->limit(10)->get(['public_id', 'category', 'status', 'subject', 'amount_minor', 'currency', 'minor_unit_scale', 'created_at', 'credit_amount_minor']);
            if ($financeWallet) {
                $wallets = app(Wallet::class);
                $financeSummary = $wallets->summary($financeWallet);
                $financeAffordability = $wallets->affordability($financeWallet, $financeTariff);
                $financeVerification = $wallets->verify($financeWallet);
                $financeEntries = WalletLedgerEntry::where('wallet_id', $financeWallet->id)
                    ->when($filters['ledger_type'] ?? null, fn ($query, $type) => $query->where('type', $type))
                    ->orderByDesc('id')->paginate(10, ['*'], 'finance_entries')->withQueryString();
                $financeReservations = WalletReservation::where('wallet_id', $financeWallet->id)
                    ->when(($filters['reservation_state'] ?? null) === 'shortfall', fn ($query) => $query->where('state', 'settled')->where('shortfall_minor', '>', 0))
                    ->when(in_array($filters['reservation_state'] ?? null, ['held', 'settled', 'released'], true), fn ($query) => $query->where('state', $filters['reservation_state']))
                    ->orderByDesc('id')->paginate(10, ['*'], 'finance_reservations')->withQueryString();
            }
        }

        return response()->view('clients.show', compact('client', 'filters', 'tab', 'memberships', 'campaigns', 'imports', 'audits', 'usage', 'financeWallet', 'financeTariff', 'financeAffordability', 'financeSummary', 'financeEntries', 'financeReservations', 'financeVerification', 'financeTariffs', 'financePayments', 'financeDisputes'))->header('Cache-Control', 'private, no-store');
    }

    public function profile(Request $request, int $client, Accounts $accounts)
    {
        $client = $this->client($client);
        $accounts->profile($request->user(), $client, $request->only(['name', 'contact_name', 'contact_email', 'contact_phone', 'country', 'address', 'broadcasting_purpose', 'account_type', 'business_name']));

        return back()->with('status', 'Client profile updated. Sign-in contacts and verification remain managed through account security.');
    }

    public function status(Request $request, int $client, Accounts $accounts)
    {
        $client = $this->client($client);
        $data = $request->validate(['status' => 'required|in:pending,active,suspended,closed', 'reason' => 'required|string|max:2000']);
        $accounts->status($request->user(), $client, $data['status'], $data['reason']);

        return back()->with('status', 'Client status updated. Existing launch and access checks enforce this status.');
    }

    public function createUser(Request $request, int $client, Accounts $accounts)
    {
        $client = $this->client($client);
        $accounts->createUser($request->user(), $client, $request->only(['name', 'email', 'phone', 'password', 'password_confirmation', 'role']));

        return back()->with('status', 'Client user created. Share the temporary password securely; first sign-in requires contact verification. No notification was sent.');
    }

    public function assign(Request $request, int $client, Accounts $accounts)
    {
        $client = $this->client($client);
        $data = $request->validate(['email' => 'required|email|max:254', 'role' => 'required|in:owner,member', 'reason' => 'required|string|max:2000']);
        $user = User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])->first();
        if (! $user) {
            throw ValidationException::withMessages(['email' => 'No matching login. Create a client user instead.']);
        }
        $accounts->member($request->user(), $client, $user, $data['role'], 'active', $data['reason']);

        return back()->with('status', 'User assigned to this account.');
    }

    public function membership(Request $request, int $client, int $membership, Accounts $accounts)
    {
        $client = $this->client($client);
        $membership = ClientMembership::where('client_id', $client->id)->findOrFail($membership);
        $data = $request->validate(['role' => 'required|in:owner,member', 'status' => 'required|in:active,revoked', 'reason' => 'required|string|max:2000']);
        $accounts->member($request->user(), $client, $membership->user, $data['role'], $data['status'], $data['reason']);

        return back()->with('status', 'Client membership updated.');
    }
}
