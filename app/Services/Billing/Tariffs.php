<?php

namespace App\Services\Billing;

use App\Models\CallTariff;
use App\Models\Client;
use App\Models\User;
use App\Models\WalletAccount;
use App\Services\Broadcast\Engine;
use App\Services\Foundation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Tariffs
{
    public function create(User $actor, array $data, string $evidence): CallTariff
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $data['evidence'] = $evidence;
        $data = Validator::make($data, [
            'code' => 'required|regex:/\A[A-Za-z0-9_-]{1,80}\z/', 'version' => 'required|integer|min:1|max:1000000',
            'currency' => 'required|regex:/\A[A-Z]{3}\z/', 'minor_unit_scale' => 'required|integer|min:0|max:6',
            'rate_minor_per_minute' => 'required|integer|min:0|max:1000000000',
            'playback_policy' => 'required|in:charge_answered,hold_on_failure', 'evidence' => 'required|string|max:1000',
        ])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $tariff = CallTariff::create($data + ['created_by' => $actor->id, 'created_at' => now()]);
            app(Foundation::class)->audit($actor, 'billing.tariff_created', $tariff);

            return $tariff;
        });
    }

    public function assign(User $actor, Client $client, CallTariff $tariff, string $reason): void
    {
        Gate::forUser($actor)->authorize('billing.manage');
        Validator::make(compact('reason'), ['reason' => 'required|string|max:1000'])->validate();
        DB::transaction(function () use ($actor, $client, $tariff, $reason) {
            app(Engine::class)->mutex();
            Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            // A funded wallet's denomination is immutable: assigning a tariff in
            // another currency would make every admission fail closed later.
            $wallet = WalletAccount::where('client_id', $client->id)->lockForUpdate()->first();
            if ($wallet && ($wallet->currency !== $tariff->currency || (int) $wallet->minor_unit_scale !== (int) $tariff->minor_unit_scale)) {
                throw ValidationException::withMessages(['tariff_id' => 'The tariff currency and minor-unit scale must match the client wallet ('
                    .$wallet->currency.' / '.$wallet->minor_unit_scale.').']);
            }
            DB::table('client_tariffs')->updateOrInsert(['client_id' => $client->id], ['call_tariff_id' => $tariff->id, 'updated_at' => now()]);
            app(Foundation::class)->audit($actor, 'billing.tariff_assigned', $client, ['tariff_id' => $tariff->id, 'reason' => $reason, 'previous_wallet_currency' => $wallet?->currency]);
        });
    }

    public function snapshot(?int $clientId): ?array
    {
        if ($clientId === null) {
            return null;
        }
        $id = DB::table('client_tariffs')->where('client_id', $clientId)->value('call_tariff_id');
        $tariff = $id ? CallTariff::findOrFail($id) : null;

        return $tariff ? $tariff->only(['id', 'code', 'version', 'currency', 'minor_unit_scale', 'rate_minor_per_minute', 'playback_policy']) + ['policy_version' => 'ceil_minute_v1'] : null;
    }
}
