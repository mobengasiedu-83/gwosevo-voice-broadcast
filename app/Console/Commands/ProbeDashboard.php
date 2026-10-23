<?php

namespace App\Console\Commands;

use App\Jobs\DashboardWorkerHeartbeat;
use App\Services\Dashboard\Health;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class ProbeDashboard extends Command
{
    protected $signature = 'dashboard:probe {--scheduled : Record the scheduler invocation heartbeat}';

    protected $description = 'Collect bounded infrastructure probes for the operations dashboard';

    public function handle(Health $health): int
    {
        if ($this->option('scheduled')) {
            $health->probe('scheduler', 'healthy');
        }
        $health->probe('database', 'healthy');
        $health->storage();
        try {
            config()->set('database.redis.default.timeout', 2);
            config()->set('database.redis.default.read_timeout', 2);
            Redis::connection()->ping();
            $health->probe('redis', 'healthy');
        } catch (\Throwable $e) {
            $health->probe('redis', 'unavailable');
        }
        if (config('queue.default') === 'sync') {
            $health->probe('worker', 'synchronous');
        } else {
            try {
                DashboardWorkerHeartbeat::dispatch(microtime(true))->onQueue('dashboard-health');
            } catch (\Throwable $e) {
                $health->probe('worker', 'unavailable');
            }
        }
        $this->info('Dashboard probes recorded; worker health requires an actual queue consumer.');

        return self::SUCCESS;
    }
}
