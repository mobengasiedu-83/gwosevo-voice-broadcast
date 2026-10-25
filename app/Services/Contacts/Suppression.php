<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\User;
use App\Services\Clients\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class Suppression
{
    public function forAccount(?int $clientId)
    {
        return DB::table('suppression_entries')->where(fn ($q) => $q->whereNull('client_id')->when($clientId !== null, fn ($q) => $q->orWhere('client_id', $clientId)));
    }

    public function visible()
    {
        $id = app(Tenancy::class)->current();
        if ($id === null) {
            return DB::table('suppression_entries');
        }

        return $this->forAccount($id)->whereIn('phone_e164', Contact::select('phone_e164'))->select([
            'id', 'phone_e164', 'client_id', 'effective_at',
            DB::raw("CASE WHEN client_id IS NULL THEN 'Platform exclusion' ELSE reason END AS reason"),
            DB::raw('CASE WHEN client_id IS NULL THEN NULL ELSE created_by END AS created_by'),
        ]);
    }

    // Caller holds the admission mutex; a later request must never postpone an exclusion.
    public function add(string $phone, string $reason, int $actor, $effective): void
    {
        $clientId = app(Tenancy::class)->clientId(User::findOrFail($actor));
        $at = CarbonImmutable::parse($effective);
        $old = DB::table('suppression_entries')->where('ownership_key', $clientId ?? 0)->where('phone_e164', $phone)->first();
        if (! $old) {
            DB::table('suppression_entries')->insert(['client_id' => $clientId, 'ownership_key' => $clientId ?? 0, 'phone_e164' => $phone, 'reason' => $reason, 'created_by' => $actor, 'effective_at' => $at]);
        } elseif ($at->lt(CarbonImmutable::parse($old->effective_at))) {
            DB::table('suppression_entries')->where('id', $old->id)->update(['effective_at' => $at, 'reason' => $reason]);
        }
    }
}
