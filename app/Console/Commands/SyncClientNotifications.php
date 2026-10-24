<?php

namespace App\Console\Commands;

use App\Services\Alerts\Notifications;
use Illuminate\Console\Command;

class SyncClientNotifications extends Command
{
    protected $signature = 'client:sync-notifications';

    protected $description = 'Generate account alerts and queue bounded opt-in channel deliveries';

    public function handle(Notifications $notifications): int
    {
        $notifications->scan();
        $this->info('Client notifications synchronized. External delivery follows opt-in preferences and the delivery switch.');

        return self::SUCCESS;
    }
}
