<?php

namespace App\Services\Clients;

use App\Models\Campaign;
use App\Models\ClientAccount;
use App\Models\User;
use App\Services\Broadcast\Engine;
use App\Services\Foundation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

class Accounts
{
    public function create(User $actor, string $name): ClientAccount
    {
        Gate::forUser($actor)->authorize('clients.manage');

        return DB::transaction(function () use ($actor, $name) {
            $account = ClientAccount::create(['name' => $name, 'created_by' => $actor->id]);
            app(Foundation::class)->audit($actor, 'client_account.created', $account);

            return $account;
        });
    }

    public function setStatus(User $actor, ClientAccount $account, string $status, string $reason): void
    {
        Gate::forUser($actor)->authorize('clients.manage');
        abort_unless(in_array($status, ['active', 'suspended'], true) && trim($reason) !== '', 422);
        DB::transaction(function () use ($actor, $account, $status, $reason) {
            app(Engine::class)->mutex();
            $account = ClientAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $before = $account->status;
            $account->update(['status' => $status, 'suspended_at' => $status === 'suspended' ? now() : null, 'suspension_reason' => $status === 'suspended' ? $reason : null]);
            if ($status === 'suspended') {
                $ids = $account->users()->pluck('id');
                User::whereIn('id', $ids)->update(['remember_token' => null]);
                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->whereIn('user_id', $ids)->delete();
                }
                Campaign::where('client_account_id', $account->id)->whereIn('status', ['running', 'scheduled'])->update(['status' => 'paused']);
            }
            app(Foundation::class)->audit($actor, 'client_account.status_changed', $account, ['before' => $before, 'after' => $status, 'reason' => $reason]);
        });
    }

    public function campaignActive(Campaign $campaign): bool
    {
        $id = $campaign->client_account_id;

        return $id === null || app(AccountContext::class)->active((int) $id);
    }
}
