<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\User;
use App\Services\Billing\Wallet;
use Illuminate\Console\Command;

class OpenWallet extends Command
{
    protected $signature = 'billing:wallet-open {client : Client id or UUID} {--currency=GHS} {--scale=2} {--reason=} {--actor=}';

    protected $description = 'Open a prepaid calling wallet for an existing client account';

    public function handle(Wallet $wallets): int
    {
        $actor = User::find($this->option('actor'));
        if (! $actor || ! $actor->can('billing.manage')) {
            $this->error('A billing administrator is required.');

            return self::FAILURE;
        }
        $client = $this->client((string) $this->argument('client'));
        if (! $client) {
            $this->error('Client account not found.');

            return self::FAILURE;
        }
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('Provide --reason for the audit trail.');

            return self::FAILURE;
        }
        try {
            $wallet = $wallets->open($actor, $client, strtoupper((string) $this->option('currency')), (int) $this->option('scale'), $reason);
        } catch (\Throwable $e) {
            $this->error('Wallet not opened. '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info('Wallet opened: '.$wallet->currency.' scale '.$wallet->minor_unit_scale.'. Post approved funding before launch.');

        return self::SUCCESS;
    }

    private function client(string $reference): ?Client
    {
        return (ctype_digit($reference) ? Client::find((int) $reference) : null) ?? Client::where('uuid', $reference)->first();
    }
}
