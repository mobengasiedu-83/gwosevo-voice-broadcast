<?php

namespace App\Services\Dashboard;

use App\Services\Broadcast\AmiClient;
use Illuminate\Support\Str;
use RuntimeException;

class QueueMonitor
{
    public function __construct(private QueueTelemetry $telemetry) {}

    // This connection never originates calls or owns the broadcast gateway lease.
    public function session(AmiClient $ami, callable $stop): void
    {
        $ami->connect();
        $action = '';
        $requested = 0;
        $frames = [];
        $deltas = [];
        while (! $stop()) {
            foreach ($ami->poll() as $frame) {
                if ($action !== '' && ($frame['ActionID'] ?? '') === $action) {
                    if (($frame['Response'] ?? '') === 'Error') {
                        throw new RuntimeException('Queue inventory rejected.');
                    }
                    if (($frame['Event'] ?? '') === 'QueueStatusComplete') {
                        $this->telemetry->snapshot($frames, $deltas);
                        $action = '';
                    } elseif (in_array($frame['Event'] ?? '', ['QueueParams', 'QueueMember'], true)) {
                        $frames[] = $frame;
                    }
                } elseif (in_array($frame['Event'] ?? '', ['QueueMemberAdded', 'QueueMemberRemoved', 'QueueMemberStatus', 'QueueMemberPause', 'QueueCallerJoin', 'QueueCallerLeave'], true)) {
                    if ($action !== '') {
                        $deltas[] = $frame;
                    } else {
                        $this->telemetry->event($frame);
                    }
                }
                if (count($frames) + count($deltas) > 10000) {
                    throw new RuntimeException('Queue inventory exceeds safety bound.');
                }
            }
            if ($action !== '' && microtime(true) - $requested > 10) {
                throw new RuntimeException('Queue inventory timed out.');
            }
            if ($action === '' && microtime(true) - $requested > 30) {
                $frames = $deltas = [];
                $action = 'queues-'.Str::uuid();
                $requested = microtime(true);
                $ami->send(['Action' => 'QueueStatus', 'ActionID' => $action]);
            }
        }
    }
}
