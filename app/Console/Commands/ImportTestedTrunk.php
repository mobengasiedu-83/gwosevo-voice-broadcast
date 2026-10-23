<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\TrunkProfile;
use App\Services\Broadcast\Engine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportTestedTrunk extends Command
{
    protected $signature = 'broadcast:import-trunk';

    protected $description = 'Import environment trunk defaults as an inactive, unverified profile without inventing capacity';

    public function handle(Engine $engine): int
    {
        $name = config('broadcasting_discovery.trunk');
        $caller = config('broadcasting_discovery.caller_id');
        $format = config('broadcasting_discovery.number_format');
        if (! is_string($name) || ! preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/', $name) || ! is_string($caller) || ! preg_match('/\A\+?[0-9]{3,15}\z/', $caller) || ! in_array($format, ['national', 'international', 'e164'], true)) {
            $this->error('Valid trunk endpoint, caller ID and number format are required in configuration.');

            return self::FAILURE;
        }
        DB::transaction(function () use ($engine, $name, $caller, $format) {
            $engine->mutex();
            $trunk = TrunkProfile::firstOrCreate(['name' => $name], ['caller_id' => $caller, 'number_format' => $format, 'active' => false, 'max_channels' => 0, 'reserved_channels' => 0, 'safe_channels' => 0, 'max_cps' => 0, 'evidence' => 'Environment defaults imported; carrier capacity remains unverified.']);
            if ($trunk->wasRecentlyCreated) {
                AuditLog::create(['actor_id' => null, 'action' => 'trunk.defaults_imported', 'entity_type' => TrunkProfile::class, 'entity_id' => $trunk->id, 'details' => ['source' => 'console', 'capacity_verified' => false], 'created_at' => now()]);
            }
        });
        $this->info('Trunk defaults available. Existing profiles were preserved. Verify capacity before activating.');

        return self::SUCCESS;
    }
}
