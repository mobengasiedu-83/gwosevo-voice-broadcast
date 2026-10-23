<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class DiscoverVitalPbx extends Command
{
    protected $signature = 'vitalpbx:discover {--live : Also run the read-only AMI authentication and Ping check}';

    protected $description = 'Review Phase 1 inventory and configured capacity without placing calls';

    public function handle(): int
    {
        $inventory = config('broadcasting_discovery');
        $issues = [];

        foreach (['vitalpbx_version', 'asterisk_version', 'trunk', 'caller_id', 'dial_pattern', 'custom_context', 'evidence_reference'] as $key) {
            if (! is_string($inventory[$key] ?? null) || trim($inventory[$key]) === '') {
                $issues[] = "Missing inventory: {$key}";
            }
        }

        foreach (['host', 'username', 'secret'] as $key) {
            $value = config("services.vitalpbx.{$key}");
            if (! is_string($value) || trim($value) === '') {
                $issues[] = "Missing AMI configuration: {$key}";
            }
        }

        $limits = [];
        foreach (['carrier_channels', 'reserved_channels', 'safe_pbx_channels', 'broadcast_cap'] as $key) {
            $value = filter_var($inventory[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $key === 'reserved_channels' ? 0 : 1]]);
            if ($value === false) {
                $issues[] = "Invalid or unknown capacity: {$key}";
            } else {
                $limits[$key] = $value;
            }
        }

        $cps = filter_var($inventory['carrier_cps'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($cps === false || ! is_finite($cps) || $cps <= 0) {
            $issues[] = 'Invalid or unknown capacity: carrier_cps';
        }

        $capacity = 0;
        if (count($limits) === 4) {
            $capacity = max(0, min($limits['broadcast_cap'], $limits['carrier_channels'] - $limits['reserved_channels'], $limits['safe_pbx_channels']));
            if ($capacity === 0) {
                $issues[] = 'No capacity remains after reserving normal PBX traffic.';
            }
        }

        $this->info('Phase 1 discovery — configured inventory review');
        $this->line('Capacity ceiling from supplied limits: '.($issues ? 'unverified (0 admitted)' : "{$capacity} channels"));
        $this->line('Configuration is operator-supplied; carrier approval, PBX measurements and sign-off require evidence.');
        foreach ($issues as $issue) {
            $this->warn($issue);
        }

        $liveStatus = self::SUCCESS;
        if ($this->option('live')) {
            $liveStatus = $this->call('vitalpbx:test');
        } else {
            $this->line('AMI connectivity not tested. Use --live to authenticate and Ping without originating calls.');
        }

        $this->line('Record findings and approvals in docs/discovery/phase-1.md. This command does not authorise launch.');

        return $issues || $liveStatus !== self::SUCCESS ? self::FAILURE : self::SUCCESS;
    }
}
