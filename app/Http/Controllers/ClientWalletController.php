<?php

namespace App\Http\Controllers;

use App\Models\WalletLedgerEntry;
use App\Models\WalletReservation;
use App\Services\Billing\Tariffs;
use App\Services\Billing\Wallet;
use App\Services\Clients\Tenancy;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\Payments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientWalletController extends Controller
{
    /**
     * Read-only client view of its own prepaid credit, with online top-ups when
     * a verified payment provider is configured.
     */
    public function __invoke(Request $request, Tenancy $tenancy, Wallet $wallets, Tariffs $tariffs, PaymentGateway $gateway, Payments $payments)
    {
        Gate::authorize('dashboard.view');
        $membership = $tenancy->membership($request->user());
        abort_unless($membership && $tenancy->usable($request->user()), 403);

        $filters = $request->validate([
            'type' => 'nullable|in:'.implode(',', Wallet::TYPES),
            'state' => 'nullable|in:held,settled,released',
            'entries_page' => 'nullable|integer|min:1|max:100000',
            'reservations_page' => 'nullable|integer|min:1|max:100000',
        ]);
        $wallet = $membership->client->wallet;
        $entries = $reservations = null;
        if ($wallet) {
            $entries = WalletLedgerEntry::where('wallet_id', $wallet->id)
                ->when($filters['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
                ->orderByDesc('id')
                ->paginate(25, ['id', 'type', 'available_delta_minor', 'reserved_delta_minor', 'available_after_minor',
                    'reserved_after_minor', 'currency', 'minor_unit_scale', 'reason', 'created_at',
                    'wallet_reservation_id', 'reversal_of_id'], 'entries_page')
                ->withQueryString();
            $reservations = WalletReservation::where('wallet_id', $wallet->id)
                ->when($filters['state'] ?? null, fn ($query, $state) => $query->where('state', $state))
                ->orderByDesc('id')
                ->paginate(15, ['id', 'call_attempt_id', 'amount_minor', 'currency', 'minor_unit_scale', 'state',
                    'settled_minor', 'shortfall_minor', 'reserved_at', 'settled_at', 'released_at'], 'reservations_page')
                ->withQueryString();
        }
        $tariff = $tariffs->snapshot($membership->client_id);
        $summary = $wallet ? $wallets->summary($wallet) : null;
        $affordability = $wallet ? $wallets->affordability($wallet, $tariff) : null;

        return response()->view('client-dashboard.wallet', [
            'client' => $membership->client, 'membership' => $membership, 'filters' => $filters,
            'wallet' => $wallet, 'entries' => $entries, 'reservations' => $reservations,
            'tariff' => $tariff, 'summary' => $summary, 'affordability' => $affordability,
            'onlineEnabled' => $gateway->enabled(), 'limits' => $payments->limits(),
        ])->header('Cache-Control', 'private, no-store');
    }
}