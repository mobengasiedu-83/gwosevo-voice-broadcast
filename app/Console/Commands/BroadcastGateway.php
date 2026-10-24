<?php

namespace App\Console\Commands;

use App\Services\Broadcast\AmiClient;
use App\Services\Broadcast\Engine;
use App\Services\Broadcast\Gateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BroadcastGateway extends Command
{
    protected $signature = 'broadcast:gateway {--observe : Receive events without placing calls} {--seconds=0 : Stop after this many seconds; zero runs continuously}';

    protected $description = 'Run the persistent AMI broadcast gateway with heartbeat and reconnect';

    public function handle(Gateway $gateway): int
    {
        if ($this->option('observe')) {
            config()->set('voice_broadcast.enabled', false);
            config()->set('voice_broadcast.observe_only', true);
        }
        $seconds = filter_var($this->option('seconds'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($seconds === false) {
            $this->error('seconds must be nonnegative.');

            return self::FAILURE;
        }
        $stop = false;
        $started = microtime(true);
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$stop) {
                $stop = true;
            });
            pcntl_signal(SIGINT, function () use (&$stop) {
                $stop = true;
            });
        }
        try {
            $gateway->claim();
        } catch (\Throwable $e) {
            $this->error('Gateway lease unavailable or database not ready.');

            return self::FAILURE;
        }
        $backoff = 1;
        $failed = false;
        $shouldStop = function () use (&$stop, $started, $seconds) {
            return $stop || ($seconds > 0 && microtime(true) - $started >= $seconds);
        };
        $this->info($this->option('observe') ? 'Gateway observing; origination disabled.' : 'Gateway running; origination requires verified approval and enabled capacity settings.');
        try {
            while (! $shouldStop()) {
                $ami = new AmiClient;
                try {
                    $gateway->session($ami, $shouldStop);
                    if ($gateway->pongVerified && $gateway->snapshotVerified) {
                        $this->info('AMI Pong and complete active-channel snapshot verified.');
                    } else {
                        $this->warn('Session verification: Pong='.($gateway->pongVerified ? 'yes' : 'no').', complete channel snapshot='.($gateway->snapshotVerified ? 'yes' : 'no').'.');
                        $failed = true;
                    }
                    $backoff = 1;
                } catch (\Throwable $e) {
                    $failed = true;
                    if ($e->getMessage() === 'Gateway lease lost.') {
                        $stop = true;
                    }
                    // Never include raw server frames, destinations or connection credentials in logs.
                    Log::channel('broadcast')->warning('Broadcast gateway disconnected; reservations require reconciliation.', ['exception' => get_class($e)]);
                    $known = ['AMI configuration incomplete.', 'AMI connection failed.', 'Unexpected AMI banner.', 'AMI login rejected.', 'AMI login timed out.', 'AMI write timeout; outcome uncertain.', 'AMI write select failed.', 'AMI write failed; outcome uncertain.', 'AMI read failed.', 'AMI disconnected.', 'AMI frame too large.', 'AMI incomplete frame too large.', 'Gateway lease lost.', 'AMI heartbeat expired.', 'Active-channel snapshot timed out.', 'Uncertain originate acknowledgement.', 'AMI channel inventory lacks correlation identifiers.', 'AMI channel inventory count mismatch.', 'Channel inventory exceeds safety bound.', 'AMI channel inventory rejected. Verify AMI read permissions.'];
                    $reason = in_array($e->getMessage(), $known, true) ? $e->getMessage() : get_class($e);
                    $this->warn('AMI session interrupted: '.$reason.' New calls blocked; reconnecting.');
                    app(Engine::class)->disconnected($gateway->owner);
                    for ($i = 0; $i < $backoff && ! $shouldStop(); $i++) {
                        sleep(1);
                    }
                    $backoff = min(30, $backoff * 2);
                } finally {
                    $ami->close();
                }
            }
        } finally {
            $gateway->release();
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
