<?php

namespace App\Console\Commands;

use App\Services\Broadcast\AmiClient;
use App\Services\Dashboard\QueueMonitor;
use App\Services\Dashboard\QueueTelemetry;
use Illuminate\Console\Command;

class MonitorQueues extends Command
{
    protected $signature = 'dashboard:queues {--seconds=0 : Stop after this many seconds; zero runs continuously}';

    protected $description = 'Monitor PBX queues on an isolated AMI connection';

    public function handle(QueueMonitor $monitor, QueueTelemetry $telemetry): int
    {
        $seconds = filter_var($this->option('seconds'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($seconds === false) {
            $this->error('seconds must be nonnegative.');

            return self::FAILURE;
        }
        $lock = fopen(storage_path('framework/queue-monitor.lock'), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Queue monitor already running or lock unavailable.');
            if (is_resource($lock)) {
                fclose($lock);
            }

            return self::FAILURE;
        }
        $stopping = false;
        $started = microtime(true);
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, function () use (&$stopping) {
                    $stopping = true;
                });
            }
        }
        $stop = function () use (&$stopping, $seconds, $started) {
            return $stopping || ($seconds > 0 && microtime(true) - $started >= $seconds);
        };
        try {
            while (! $stop()) {
                $ami = new AmiClient;
                try {
                    $telemetry->state('unavailable');
                    $monitor->session($ami, $stop);
                } catch (\Throwable $e) {
                    $telemetry->state('unavailable');
                    // No raw AMI frames, credentials or queue member details in logs.
                    $this->warn('Queue telemetry unavailable; reconnecting after cooldown.');
                } finally {
                    $ami->close();
                }
                for ($i = 0; $i < 60 && ! $stop(); $i++) {
                    sleep(1);
                }
            }
        } finally {
            $telemetry->state('unavailable');
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return self::SUCCESS;
    }
}
