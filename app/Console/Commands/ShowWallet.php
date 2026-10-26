<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Services\Billing\Wallet;
use Illuminate\Console\Command;

class ShowWallet extends Command
{
    protected $signature = 'billing:wallet-show {client : Client id or UUID} {--actor=}';

    protected $description = 'Show a client wallet balance, held credit and ledger reconciliation';

    public function handle(Wallet $wallets): int
    {
        $actor = User::find($this->option('actor'));
        if (! $actor || ! $actor->can('billing.manage')) {
            $this->error('A billing administrator is required.');

            return self::FAILURE;
        }
        $client = (ctype_digit((string) $this->argument('client')) ? Client::find((int) $this->argument('client')) : null)
            ?? Client::where('uuid', (string) $this->argument('client'))->first();
        $account = $client?->wallet;
        if (! $account) {
            $this->error('Client account or wallet not found.');

            return self::FAILURE;
        }
        $check = $wallets->verify($account);
        $this->table(['Field', 'Value'], [
            ['Client', $client->name],
            ['Currency / scale', $account->currency.' / '.$account->minor_unit_scale],
            ['Available', Wallet::amount($account->available_minor, $account->minor_unit_scale)],
            ['Reserved', Wallet::amount($account->reserved_minor, $account->minor_unit_scale)],
            ['Ledger available', Wallet::amount($check['ledger_available_minor'], $account->minor_unit_scale)],
            ['Ledger reserved', Wallet::amount($check['ledger_reserved_minor'], $account->minor_unit_scale)],
            ['Held reservations', Wallet::amount($check['held_minor'], $account->minor_unit_scale)],
            ['Reconciled', $check['available_match'] && $check['reserved_match'] && $check['held_match'] ? 'yes' : 'NO — finance review required'],
        ]);
        $entries = WalletLedgerEntry::where('wallet_id', $account->id)->latest('id')->limit(10)->get()
            ->map(fn ($entry) => [
                $entry->id, $entry->type,
                Wallet::amount($entry->available_delta_minor, $account->minor_unit_scale),
                Wallet::amount($entry->reserved_delta_minor, $account->minor_unit_scale),
                Wallet::amount($entry->available_after_minor, $account->minor_unit_scale),
                $entry->created_at?->toDateTimeString(),
            ])->all();
        $this->table(['#', 'Type', 'Available Δ', 'Reserved Δ', 'Available after', 'Posted (UTC)'], $entries);

        return $check['available_match'] && $check['reserved_match'] && $check['held_match'] ? self::SUCCESS : self::FAILURE;
    }
}
