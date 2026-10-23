<?php

namespace App\Console\Commands;

use App\Services\Broadcast\AmiClient;
use Illuminate\Console\Command;

class BroadcastInventory extends Command
{
    protected $signature = 'broadcast:inventory';

    protected $description = 'Read Asterisk version and PJSIP endpoint names using the configured private AMI connection';

    public function handle(): int
    {
        $ami = new AmiClient;
        $version = null;
        $names = [];
        $done = false;
        $responses = [];
        try {
            $ami->connect();
            $ami->send(['Action' => 'CoreSettings', 'ActionID' => 'inventory-settings']);
            $ami->send(['Action' => 'PJSIPShowEndpoints', 'ActionID' => 'inventory-endpoints']);
            $deadline = microtime(true) + 8;
            while (microtime(true) < $deadline && (! $done || ! $version)) {
                foreach ($ami->poll() as $frame) {
                    if (isset($frame['Response'])) {
                        $responses[$frame['ActionID'] ?? 'unlabelled'] = in_array($frame['Response'], ['Success', 'Error', 'Follows'], true) ? $frame['Response'] : 'unexpected';
                    }
                    if (($frame['ActionID'] ?? '') === 'inventory-settings' && isset($frame['AsteriskVersion'])) {
                        $version = $frame['AsteriskVersion'];
                    }
                    if (($frame['ActionID'] ?? '') === 'inventory-endpoints' && ($frame['Event'] ?? '') === 'EndpointList') {
                        $name = $frame['ObjectName'] ?? '';
                        if (preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/', $name)) {
                            $names[$name] = true;
                        }
                    }
                    if (($frame['ActionID'] ?? '') === 'inventory-endpoints' && ($frame['Event'] ?? '') === 'EndpointListComplete') {
                        $done = true;
                    }
                }
            }
            foreach ($responses as $id => $response) {
                if (in_array($id, ['inventory-settings', 'inventory-endpoints', 'unlabelled'], true)) {
                    $this->line($id.' response: '.$response);
                }
            }
            $this->line('Asterisk version: '.($version && preg_match('/\A[A-Za-z0-9_.+ -]{1,100}\z/', $version) ? $version : 'unavailable'));
            $this->line('PJSIP endpoint names (includes extensions; approval as a broadcast trunk is not inferred):');
            foreach (array_keys($names) as $name) {
                $this->line($name);
            }
            $this->line('Carrier-authorised channels, CPS, caller ID and reserved capacity require separate evidence.');
            if (! $done) {
                $this->warn('Endpoint inventory incomplete or permission denied.');

                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('AMI inventory unavailable. Check connection and read permissions.');

            return self::FAILURE;
        } finally {
            $ami->close();
        }
    }
}
