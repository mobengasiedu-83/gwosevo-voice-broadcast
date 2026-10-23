<?php

namespace App\Console\Commands;

use App\Models\CallAttempt;
use App\Models\CallEvent;
use Illuminate\Console\Command;

class PruneBroadcastEvents extends Command
{
    protected $signature = 'broadcast:prune-events';

    protected $description = 'Prune processed raw AMI events after the configured retention period';

    public function handle(): int
    {
        $days = max(1, (int) config('voice_broadcast.event_retention_days'));
        // Keep uncertain and active attempts for reconciliation; retain attempt milestones and audit history.
        $total = 0;
        do {
            $ids = CallEvent::whereNotNull('processed_at')->where('created_at', '<', now()->subDays($days))->whereIn('call_attempt_id', CallAttempt::whereNotNull('ended_at')->select('id'))->limit(1000)->pluck('id');
            $total += CallEvent::whereIn('id', $ids)->delete();
        } while ($ids->isNotEmpty());
        $this->info("Pruned {$total} processed events.");

        return self::SUCCESS;
    }
}
