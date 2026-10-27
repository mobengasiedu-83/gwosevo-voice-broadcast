<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\WalletReservation;
use App\Services\Billing\Wallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireWalletHolds extends Command
{
    protected $signature = 'billing:wallet-expire {--hours=24} {--limit=500} {--actor=} {--scheduled : Use the configured automatic cleanup policy}';

    protected $description = 'Release wallet holds that never received final rating evidence';

    public function handle(Wallet $wallets): int
    {
        $scheduled = (bool) $this->option('scheduled');
        $hours = max(1, min(720, (int) ($scheduled ? config('billing.hold_cleanup.hours', 24) : $this->option('hours'))));
        $limit = max(1, min(1000, (int) ($scheduled ? config('billing.hold_cleanup.limit', 500) : $this->option('limit'))));
        if ($scheduled && ! config('billing.hold_cleanup.enabled')) {
            $stale = WalletReservation::where('state', 'held')->where('reserved_at', '<=', now()->subHours($hours))->count();
            if ($stale) {
                Log::warning('billing.hold_cleanup_disabled', ['stale_holds' => $stale, 'hours' => $hours]);
            }
            $this->info('Automatic hold cleanup is disabled. Stale holds: '.$stale);

            return self::SUCCESS;
        }
        $actor = User::find($scheduled ? config('billing.hold_cleanup.actor_id') : $this->option('actor'));
        if (! $actor || ! $actor->can('billing.manage')) {
            Log::error('billing.hold_cleanup_invalid_actor', ['scheduled' => $scheduled]);
            $this->error('A billing administrator is required.');

            return self::FAILURE;
        }
        $released = $wallets->expire($hours, $limit, $actor);
        $this->info('Released '.$released.' stale wallet hold(s) older than '.$hours.' hour(s). Settlement still charges available credit; reconciled histories are unchanged.');

        $remaining = WalletReservation::where('state', 'held')->where('reserved_at', '<=', now()->subHours($hours))->count();
        Log::info('billing.hold_cleanup_completed', ['scheduled' => $scheduled, 'actor_id' => $actor->id, 'released' => $released, 'remaining_stale' => $remaining, 'hours' => $hours, 'limit' => $limit]);
        if ($remaining) {
            Log::warning('billing.hold_cleanup_backlog', ['remaining_stale' => $remaining, 'released' => $released, 'limit' => $limit]);
            $this->warn($remaining.' stale hold(s) remain. Review missing ratings, active calls and the batch limit.');
        }

        return self::SUCCESS;
    }
}
