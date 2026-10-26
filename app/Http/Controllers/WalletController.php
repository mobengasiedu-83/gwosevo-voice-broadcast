<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\WalletAccount;
use App\Models\WalletLedgerEntry;
use App\Models\WalletReservation;
use App\Services\Billing\Tariffs;
use App\Services\Billing\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WalletController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('billing.manage');
        $filters = $request->validate([
            'q' => 'nullable|string|max:80',
            'state' => 'nullable|in:all,funded,unfunded,held',
        ]);
        $clients = Client::with('wallet')
            ->withCount(['walletReservations as held_reservations_count' => fn ($query) => $query->where('state', 'held')])
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->when(($filters['state'] ?? 'all') === 'funded', fn ($query) => $query->whereHas('wallet'))
            ->when(($filters['state'] ?? 'all') === 'unfunded', fn ($query) => $query->whereDoesntHave('wallet'))
            ->when(($filters['state'] ?? 'all') === 'held', fn ($query) => $query->whereHas('walletReservations', fn ($hold) => $hold->where('state', 'held')))
            ->orderBy('name')->paginate(50, ['id', 'uuid', 'name', 'status'])->withQueryString();
        $summary = [
            'configured' => WalletAccount::count(),
            'no_wallet' => Client::whereDoesntHave('wallet')->count(),
            'held_count' => WalletReservation::where('state', 'held')->count(),
            'stale_hold_count' => WalletReservation::where('state', 'held')->where('reserved_at', '<=', now()->subDay())->count(),
            'held_by_currency' => WalletReservation::query()
                ->join('wallets', 'wallets.id', '=', 'wallet_reservations.wallet_id')
                ->where('wallet_reservations.state', 'held')
                ->selectRaw('wallets.currency as currency, wallets.minor_unit_scale as minor_unit_scale, COALESCE(SUM(wallet_reservations.amount_minor), 0) as held_minor')
                ->groupBy('wallets.currency', 'wallets.minor_unit_scale')->get(),
        ];

        return response()->view('billing.wallets', compact('clients', 'filters', 'summary'))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Client $client, Wallet $wallet)
    {
        Gate::authorize('billing.manage');
        $filters = $request->validate([
            'type' => 'nullable|in:'.implode(',', Wallet::TYPES),
            'state' => 'nullable|in:held,settled,released,shortfall',
        ]);
        $account = $client->wallet;
        $entries = $reservations = null;
        if ($account) {
            $entries = WalletLedgerEntry::where('wallet_id', $account->id)
                ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
                ->orderByDesc('id')->paginate(50, ['*'], 'entries')->withQueryString();
            $reservations = WalletReservation::where('wallet_id', $account->id)
                ->when(($filters['state'] ?? null) === 'shortfall', fn ($query) => $query->where('state', 'settled')->where('shortfall_minor', '>', 0))
                ->when(in_array($filters['state'] ?? null, ['held', 'settled', 'released'], true), fn ($query) => $query->where('state', $filters['state']))
                ->orderByDesc('id')->paginate(25, ['*'], 'reservations')->withQueryString();
        }
        $reversed = $account ? WalletLedgerEntry::where('wallet_id', $account->id)->whereNotNull('reversal_of_id')->pluck('reversal_of_id')->map(fn ($id) => (int) $id)->all() : [];
        $verification = $account ? $wallet->verify($account) : null;
        $summary = $account ? $wallet->summary($account) : null;
        $tariff = app(Tariffs::class)->snapshot($client->id);
        $affordability = $account ? $wallet->affordability($account, $tariff) : null;

        return response()->view('billing.wallet', compact('client', 'account', 'entries', 'reservations', 'reversed', 'verification', 'summary', 'tariff', 'affordability', 'filters'))->header('Cache-Control', 'private, no-store');
    }

    public function open(Request $request, Client $client, Wallet $wallet)
    {
        Gate::authorize('billing.manage');
        $data = $request->validate([
            'currency' => 'required|string|regex:/\A[A-Z]{3}\z/', 'minor_unit_scale' => 'required|integer|min:0|max:6',
            'reason' => 'required|string|min:3|max:255',
        ]);
        $wallet->open($request->user(), $client, strtoupper($data['currency']), (int) $data['minor_unit_scale'], $data['reason']);

        return back()->with('status', 'Wallet opened. Post approved funding before launching paid campaigns.');
    }

    public function post(Request $request, Client $client, Wallet $wallet)
    {
        Gate::authorize('billing.manage');
        $data = $request->validate([
            'kind' => 'required|in:funding,adjustment', 'amount' => 'required|string|max:32',
            'reason' => 'required|string|min:3|max:255', 'approval' => 'required|string|min:3|max:255',
            'key' => 'nullable|string|max:120',
        ]);
        $account = $client->wallet;
        abort_unless($account, 404);
        $wallet->post($request->user(), $account, $data['kind'], $data['amount'], $data['reason'], $data['approval'], $data['key'] ?? null);

        return back()->with('status', 'Ledger entry posted. Historical entries remain unchanged.');
    }

    public function reverse(Request $request, Client $client, WalletLedgerEntry $entry, Wallet $wallet)
    {
        Gate::authorize('billing.manage');
        $data = $request->validate(['reason' => 'required|string|min:3|max:255', 'approval' => 'required|string|min:3|max:255']);
        $account = $client->wallet;
        abort_unless($account && (int) $entry->wallet_id === (int) $account->id, 404);
        $wallet->reverse($request->user(), $account, $entry, $data['reason'], $data['approval']);

        return back()->with('status', 'Reversing entry posted. The original entry is unchanged.');
    }

    /** Administrator / Super Administrator manual credit path. */
    public function credit(Request $request, Client $client, Wallet $wallet)
    {
        Gate::authorize('billing.manage');
        $data = $request->validate([
            'amount' => 'required|string|max:32',
            'reason' => 'required|string|min:3|max:255',
            'approval' => 'nullable|string|max:255',
            'credit_key' => 'nullable|uuid',
        ]);
        $account = $client->wallet;
        abort_unless($account, 404);
        $entry = $wallet->manualCredit($request->user(), $account, $data['amount'], $data['reason'], $data['approval'] ?? null, $data['credit_key'] ?? null);
        $account->refresh();

        return back()->with('status', 'Manual credit posted: '.Wallet::amount($entry->available_delta_minor, $account->minor_unit_scale)
            .' '.$account->currency.'. Available credit is now '.Wallet::amount($account->available_minor, $account->minor_unit_scale).' '.$account->currency.'.');
    }

    /** Full append-only ledger export for Finance review. */
    public function statement(Request $request, Client $client)
    {
        Gate::authorize('billing.manage');
        $account = $client->wallet;
        abort_unless($account, 404);
        $filename = 'wallet-statement-client-'.$client->id.'.csv';

        return response()->streamDownload(function () use ($account) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['entry_id', 'created_at_utc', 'type', 'available_delta', 'reserved_delta',
                'available_after', 'reserved_after', 'currency', 'reason', 'approval_reference',
                'attempt_id', 'wallet_reservation_id', 'reversal_of_id', 'actor_id'], ',', '"', '');
            WalletLedgerEntry::where('wallet_id', $account->id)->orderBy('id')->chunk(500, function ($entries) use ($out, $account) {
                foreach ($entries as $entry) {
                    fputcsv($out, [
                        $entry->id, $entry->created_at?->utc()->format('Y-m-d H:i:s'), $entry->type,
                        Wallet::amount($entry->available_delta_minor, $account->minor_unit_scale),
                        Wallet::amount($entry->reserved_delta_minor, $account->minor_unit_scale),
                        Wallet::amount($entry->available_after_minor, $account->minor_unit_scale),
                        Wallet::amount($entry->reserved_after_minor, $account->minor_unit_scale),
                        $account->currency, $this->csvCell($entry->reason), $this->csvCell($entry->approval_reference),
                        $entry->call_attempt_id ?? '', $entry->wallet_reservation_id ?? '', $entry->reversal_of_id ?? '', $entry->actor_id ?? '',
                    ], ',', '"', '');
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function csvCell($value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/\A[=+\-@]/', $value) ? "'".$value : $value;
    }
}
