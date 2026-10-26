<?php

namespace App\Http\Controllers;

use App\Models\WalletLedgerEntry;
use App\Services\Billing\Wallet;
use App\Services\Clients\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Download a client's own append-only wallet statement as CSV. */
class ClientWalletStatementController extends Controller
{
    public function __invoke(Request $request, Tenancy $tenancy)
    {
        Gate::authorize('dashboard.view');
        $membership = $tenancy->membership($request->user());
        abort_unless($membership && $tenancy->usable($request->user()), 403);
        $wallet = $membership->client->wallet;
        abort_unless($wallet, 404);
        $filename = 'wallet-statement-'.$membership->client->uuid.'.csv';

        return response()->streamDownload(function () use ($wallet) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['entry_id', 'created_at_utc', 'type', 'available_delta', 'reserved_delta',
                'available_after', 'reserved_after', 'currency', 'reason', 'approval_reference',
                'attempt_id', 'wallet_reservation_id', 'reversal_of_id'], ',', '"', '');
            WalletLedgerEntry::where('wallet_id', $wallet->id)->orderBy('id')->chunk(500, function ($entries) use ($out, $wallet) {
                foreach ($entries as $entry) {
                    fputcsv($out, [
                        $entry->id, $entry->created_at?->utc()->format('Y-m-d H:i:s'), $entry->type,
                        Wallet::amount($entry->available_delta_minor, $wallet->minor_unit_scale),
                        Wallet::amount($entry->reserved_delta_minor, $wallet->minor_unit_scale),
                        Wallet::amount($entry->available_after_minor, $wallet->minor_unit_scale),
                        Wallet::amount($entry->reserved_after_minor, $wallet->minor_unit_scale),
                        $wallet->currency, $this->cell($entry->reason), $this->cell($entry->approval_reference),
                        $entry->call_attempt_id ?? '', $entry->wallet_reservation_id ?? '', $entry->reversal_of_id ?? '',
                    ], ',', '"', '');
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function cell($value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/\A[=+\-@]/', $value) ? "'".$value : $value;
    }
}
