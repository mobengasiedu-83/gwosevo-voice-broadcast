<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use App\Models\WalletAccount;
use App\Services\Billing\Wallet;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class AdjustWallet extends Command
{
    protected $signature = 'billing:wallet-adjust {client : Client id or UUID} {--kind=adjustment} {--amount=} {--reason=} {--approval=} {--key=} {--actor=}';

    protected $description = 'Post an approved funding or adjustment entry to a client wallet ledger';

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
            $this->error('Client account or wallet not found. Open the wallet first.');

            return self::FAILURE;
        }
        $amount = trim((string) $this->option('amount'));
        $reason = trim((string) $this->option('reason'));
        $approval = trim((string) $this->option('approval'));
        if ($amount === '' || $reason === '' || $approval === '') {
            $this->error('Provide --amount, --reason and --approval.');

            return self::FAILURE;
        }
        try {
            $entry = $wallets->post($actor, $account, (string) $this->option('kind'), $amount, $reason, $approval, $this->option('key') ?: null);
        } catch (ValidationException $e) {
            $this->error('Entry rejected: '.collect($e->errors())->flatten()->first());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Entry rejected. Check the wallet state, amount and approval reference.');

            return self::FAILURE;
        }
        $fresh = WalletAccount::findOrFail($account->id);
        $this->info('Ledger entry #'.$entry->id.' posted. Available: '.Wallet::amount($fresh->available_minor, $fresh->minor_unit_scale)
            .' '.$fresh->currency.' · Reserved: '.Wallet::amount($fresh->reserved_minor, $fresh->minor_unit_scale).' '.$fresh->currency);

        return self::SUCCESS;
    }
}
