<?php

namespace App\Services\Contacts;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class Suppression
{
    // Caller holds the admission mutex; a later request must never postpone an exclusion.
    public function add(string $phone, string $reason, int $actor, $effective): void
    {
        $at = CarbonImmutable::parse($effective);
        $old = DB::table('suppression_entries')->where('phone_e164', $phone)->first();
        if (! $old) {
            DB::table('suppression_entries')->insert(['phone_e164' => $phone, 'reason' => $reason, 'created_by' => $actor, 'effective_at' => $at]);
        } elseif ($at->lt(CarbonImmutable::parse($old->effective_at))) {
            DB::table('suppression_entries')->where('id', $old->id)->update(['effective_at' => $at, 'reason' => $reason]);
        }
    }
}
