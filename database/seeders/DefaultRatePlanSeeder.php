<?php

namespace Database\Seeders;

use App\Models\RateVersion;
use App\Models\User;
use App\Services\Billing\Ledger;
use Illuminate\Database\Seeder;

class DefaultRatePlanSeeder extends Seeder
{
    public function run(): void
    {
        // Reference policy confirmed by the owner. Never assigns wallets or grants credit.
        $actor = User::role('Super Administrator')->first();
        if (! $actor) {
            $this->command?->warn('Create a Super Administrator before seeding the reference price.');

            return;
        }
        if (! RateVersion::where('plan_code', 'ghs-recording')->exists()) {
            app(Ledger::class)->price($actor, ['plan_code' => 'ghs-recording', 'currency' => 'GHS', 'minor_digits' => 3, 'rate_minor_per_minute' => 3, 'evidence' => 'Owner confirmed 2026-10-24: 0.30 pesewas = GHS 0.003 per minute; recording duration rounded up to whole minute for all answered calls including playback failures; audited refunds.']);
        }
    }
}
