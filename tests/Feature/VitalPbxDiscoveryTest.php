<?php

namespace Tests\Feature;

use Tests\TestCase;

class VitalPbxDiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.vitalpbx', ['host' => '127.0.0.1', 'username' => 'test', 'secret' => 'private-test-secret', 'port' => 5038]);
        config()->set('broadcasting_discovery', [
            'vitalpbx_version' => 'verified', 'asterisk_version' => 'verified',
            'trunk' => 'approved', 'caller_id' => 'approved', 'dial_pattern' => 'verified',
            'custom_context' => 'gwosevo-broadcast', 'evidence_reference' => 'private/inventory',
            'carrier_channels' => 100, 'reserved_channels' => 30,
            'safe_pbx_channels' => 60, 'broadcast_cap' => 80, 'carrier_cps' => 2,
        ]);
    }

    public function test_ceiling_preserves_reservations_and_pbx_limit(): void
    {
        $this->artisan('vitalpbx:discover')->expectsOutputToContain('60 channels')->assertExitCode(0);
        config()->set('broadcasting_discovery.reserved_channels', 90);
        $this->artisan('vitalpbx:discover')->expectsOutputToContain('10 channels')->assertExitCode(0);
    }

    public function test_unknown_capacity_fails_closed(): void
    {
        config()->set('broadcasting_discovery.carrier_channels', null);
        $this->artisan('vitalpbx:discover')->expectsOutputToContain('unverified (0 admitted)')->assertExitCode(1);
    }

    public function test_no_available_channels_and_invalid_cps_fail(): void
    {
        config()->set('broadcasting_discovery.reserved_channels', 100);
        config()->set('broadcasting_discovery.carrier_cps', -1);
        $this->artisan('vitalpbx:discover')->expectsOutputToContain('No capacity remains')->assertExitCode(1);
    }

    public function test_missing_inventory_fails(): void
    {
        config()->set('broadcasting_discovery.trunk', '');
        $this->artisan('vitalpbx:discover')->expectsOutputToContain('Missing inventory: trunk')->assertExitCode(1);
    }

    public function test_ami_header_injection_is_rejected_before_connecting(): void
    {
        config()->set('services.vitalpbx.username', "test\r\nAction: Originate");
        $this->artisan('vitalpbx:test')->expectsOutputToContain('Invalid VitalPBX AMI connection configuration.')->assertExitCode(1);
    }

    public function test_live_check_failure_propagates(): void
    {
        config()->set('services.vitalpbx.secret', '');
        $this->artisan('vitalpbx:discover --live')->expectsOutputToContain('VitalPBX AMI configuration is incomplete.')->assertExitCode(1);
    }
}
