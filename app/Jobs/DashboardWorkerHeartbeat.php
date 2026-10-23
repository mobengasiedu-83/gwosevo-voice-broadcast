<?php

namespace App\Jobs;

use App\Services\Dashboard\Health;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DashboardWorkerHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(public float $queuedAt) {}

    public function handle(Health $health): void
    {
        $health->probe('worker', 'healthy', ['lag_seconds' => max(0, round(microtime(true) - $this->queuedAt, 3))]);
    }
}
