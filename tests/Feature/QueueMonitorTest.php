<?php

namespace Tests\Feature;

use App\Services\Broadcast\AmiClient;
use App\Services\Dashboard\QueueMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueueMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_queue_snapshot_is_published_on_its_own_connection(): void
    {
        $ami = new class extends AmiClient
        {
            public array $sent = [];

            public array $pending = [];

            public function connect(): void {}

            public function send(array $headers): void
            {
                $this->sent[] = $headers;
                $this->pending = [
                    ['Event' => 'QueueParams', 'ActionID' => $headers['ActionID'], 'Queue' => 'support', 'Calls' => '2'],
                    ['Event' => 'QueueStatusComplete', 'ActionID' => $headers['ActionID']],
                ];
            }

            public function poll(): array
            {
                $frames = $this->pending;
                $this->pending = [];

                return $frames;
            }
        };
        $iterations = 0;
        app(QueueMonitor::class)->session($ami, function () use (&$iterations) {
            return ++$iterations > 3;
        });
        $this->assertSame(['QueueStatus'], array_column($ami->sent, 'Action'));
        $this->assertDatabaseHas('dashboard_queues', ['queue' => 'support', 'waiting' => 2]);
        $this->assertDatabaseHas('dashboard_telemetry', ['source' => 'queues', 'status' => 'healthy']);
    }

    public function test_incomplete_inventory_does_not_replace_last_complete_snapshot(): void
    {
        $ami = new class extends AmiClient
        {
            public string $action = '';

            public function connect(): void {}

            public function send(array $headers): void
            {
                $this->action = $headers['ActionID'];
            }

            public function poll(): array
            {
                return $this->action === '' ? [] : [['Event' => 'QueueParams', 'ActionID' => $this->action, 'Queue' => 'incomplete', 'Calls' => '9']];
            }
        };
        $iterations = 0;
        app(QueueMonitor::class)->session($ami, function () use (&$iterations) {
            return ++$iterations > 3;
        });
        $this->assertSame(0, DB::table('dashboard_queues')->count());
        $this->assertDatabaseMissing('dashboard_telemetry', ['source' => 'queues', 'status' => 'healthy']);
    }
}
