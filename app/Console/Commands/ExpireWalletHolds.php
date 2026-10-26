<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Billing\Wallet;
use Illuminate\Console\Command;

class ExpireWalletHolds extends Command
{
    protected $signature = 'billing:wallet-expire {--hours=24} {--limit=500} {--actor=}';

    protected $description = 'Release wallet holds that never received final rating evidence';

    public function handle(Wallet $wallets): int
    {
        $actor = User::find($this->option('actor'));
        if (! $actor || ! $actor->can('billing.manage')) {
            $this->error('A billing administrator is required.');

            return self::FAILURE;
        }
        $hours = max(1, min(24 * 30, (int) $this->option('hours')));
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $released = $wallets->expire($hours, $limit);
        $this->info('Released '.$released.' stale wallet hold(s) older than '.$hours.' hour(s). Settlement still charges available credit; reconciled histories are unchanged.');

        return self::SUCCESS;
    }
}
