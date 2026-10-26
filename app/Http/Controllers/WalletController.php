<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\WalletLedgerEntry;
use App\Models\WalletReservation;
use App\Services\Billing\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WalletController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('billing.manage');
        $filters = $request->validate(['q' => 'nullable|string|max:80']);
        $clients = Client::with('wallet')
            ->when($filters['q'] ?? null, fn ($query, $term) => $query->where('name', 'like', '%'.$term.'%'))
            ->orderBy('name')->paginate(50, ['id', 'uuid', 'name', 'status'])->withQueryString();

        return response()->view('billing.wallets', compact('clients', 'filters'))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Client $client, Wallet $wallet)
    {
        Gate::authorize('billing.manage');
        $account = $client->wallet;
        $entries = $account ? WalletLedgerEntry::where('wallet_id', $account->id)->orderByDesc('id')->paginate(50, ['*'], 'entries') : null;
        $reservations = $account ? WalletReservation::where('wallet_id', $account->id)->orderByDesc('id')->paginate(25, ['*'], 'reservations') : null;
        $reversed = $account ? WalletLedgerEntry::where('wallet_id', $account->id)->whereNotNull('reversal_of_id')->pluck('reversal_of_id')->map(fn ($id) => (int) $id)->all() : [];
        $verification = $account ? $wallet->verify($account) : null;

        return response()->view('billing.wallet', compact('client', 'account', 'entries', 'reservations', 'reversed', 'verification'))->header('Cache-Control', 'private, no-store');
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
        ]);
        $account = $client->wallet;
        abort_unless($account, 404);
        $wallet->post($request->user(), $account, $data['kind'], $data['amount'], $data['reason'], $data['approval']);

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
}
