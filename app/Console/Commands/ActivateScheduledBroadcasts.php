<?php

namespace App\Console\Commands;

use App\Services\Broadcast\CampaignPolicy;
use Illuminate\Console\Command;

class ActivateScheduledBroadcasts extends Command
{
    protected $signature = 'broadcast:activate-scheduled';

    protected $description = 'Activate due, previously approved and operator-armed campaigns';

    public function handle(CampaignPolicy $policy): int
    {
        $policy->activateDue();
        $this->info('Due campaigns activated.');

        return self::SUCCESS;
    }
}
