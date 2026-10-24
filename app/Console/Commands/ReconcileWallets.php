<?php

namespace App\Console\Commands;

use App\Models\Wallet;
use App\Services\Billing\Ledger;
use App\Services\Broadcast\Engine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileWallets extends Command
{
    protected $signature = 'billing:reconcile {--wallet= : Limit the audit to one wallet ID}';

    protected $description = 'Read-only check of ledger balances, reservations and billing reviews';

    public function handle(Ledger $ledger): int
    {
        $failures = 0;
        Wallet::when($this->option('wallet'), fn ($q) => $q->whereKey($this->option('wallet')))->orderBy('id')->chunkById(100, function ($wallets) use ($ledger, &$failures) {
            foreach ($wallets as $wallet) {
                $check = DB::transaction(function () use ($ledger, $wallet) {
                    app(Engine::class)->mutex();

                    return $ledger->reconciliation($wallet->fresh());
                });
                $reviews = DB::table('wallet_reservations')->where('wallet_id', $wallet->id)->where('status', 'review')->count();
                $this->line('Wallet '.$wallet->id.': '.($check['matches'] ? 'MATCH' : 'MISMATCH').' | reviews '.$reviews);
                $failures += $check['matches'] ? 0 : 1;
            }
        });
        if ($this->option('wallet') && ! Wallet::whereKey($this->option('wallet'))->exists()) {
            $this->error('Wallet not found.');

            return self::FAILURE;
        }
        $this->line('Mismatched wallets: '.$failures.'. No financial records were changed.');

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
