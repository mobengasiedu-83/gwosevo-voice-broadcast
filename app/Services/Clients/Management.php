<?php

namespace App\Services\Clients;

use App\Models\Campaign;
use App\Models\ClientAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Alerts\Notifications;
use App\Services\Broadcast\Engine;
use App\Services\Foundation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Management
{
    public function staff(User $actor, string $permission = 'clients.view'): void
    {
        abort_if($actor->isClient(), 403);
        // Existing managers retain directory access; new sensitive actions require explicit grants.
        abort_unless($actor->can($permission) || ($permission === 'clients.view' && $actor->can('clients.manage')), 403);
    }

    public function manage(User $actor, ClientAccount $account, string $permission, string $ability): void
    {
        if ($actor->isClient()) {
            abort_unless((int) $actor->client_account_id === (int) $account->id && $actor->clientCan($ability), 403);
        } else {
            $this->staff($actor, $permission);
        }
    }

    public function directory(array $filters)
    {
        $query = ClientAccount::query()->withCount('users');
        foreach (['name' => 'primary_contact', 'email' => 'primary_email', 'phone_e164' => 'primary_phone', 'email_verified_at' => 'primary_email_verified', 'phone_verified_at' => 'primary_phone_verified'] as $field => $alias) {
            $query->selectSub(User::whereColumn('client_account_id', 'client_accounts.id')->orderBy('id')->select($field)->limit(1), $alias);
        }
        if (auth()->user()?->can('billing.view')) {
            $query->selectSub(Wallet::whereColumn('client_account_id', 'client_accounts.id')->select('balance_minor')->limit(1), 'wallet_balance');
            $query->selectSub(Wallet::whereColumn('client_account_id', 'client_accounts.id')->select('currency')->limit(1), 'wallet_currency');
            $query->selectSub(Wallet::whereColumn('client_account_id', 'client_accounts.id')->select('minor_digits')->limit(1), 'wallet_digits');
        }
        if (! empty($filters['q'])) {
            $q = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
            $query->where(fn ($w) => $w->where('name', 'like', $q)->orWhere('uuid', 'like', $q)->orWhere('id', $filters['q'])->orWhereHas('users', fn ($u) => $u->where('name', 'like', $q)->orWhere('email', 'like', $q)->orWhere('phone_e164', 'like', $q)));
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['to'], 'UTC')->addDay());
        }
        if (! empty($filters['plan_id'])) {
            $query->where('commercial_plan_id', $filters['plan_id']);
        }
        if (($filters['verification'] ?? '') === 'incomplete') {
            $query->whereHas('users', fn ($u) => $u->where(fn ($v) => $v->whereNull('email_verified_at')->orWhereNull('phone_verified_at')));
        }
        if (($filters['verification'] ?? '') === 'business_pending') {
            $query->where('business_verification', 'pending');
        }

        return $query;
    }

    public function profile(User $actor, ClientAccount $account, array $data): void
    {
        $this->manage($actor, $account, 'clients.profile', 'profile.manage');
        DB::transaction(function () use ($actor, $account, $data) {
            $a = ClientAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $before = $a->profile;
            $a->name = $data['name'];
            $a->profile = collect($data)->except(['name', 'reason'])->all();
            // Editing business identity invalidates prior business verification.
            if ($before !== $a->profile && $a->business_verification === 'verified') {
                $a->business_verification = 'pending';
                $a->business_verified_at = null;
            }
            $a->save();
            app(Foundation::class)->audit($actor, 'client.profile_updated', $a, ['fields' => array_keys($data), 'reason' => $data['reason']]);
        });
    }

    public function restrictions(User $actor, ClientAccount $account, array $data): void
    {
        $this->staff($actor, 'clients.restrict');
        DB::transaction(function () use ($actor, $account, $data) {
            app(Engine::class)->mutex();
            $a = ClientAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $changes = collect($data)->only(['launch_blocked', 'dispatch_blocked', 'require_both_contacts', 'require_business_verification', 'max_active_campaigns'])->all();
            if (array_key_exists('daily_spend_limit', $data)) {
                Gate::forUser($actor)->authorize('billing.manage');
                $wallet = Wallet::where('client_account_id', $a->id)->first();
                if ($data['daily_spend_limit'] !== null) {
                    abort_unless($wallet, 422, 'Assign a wallet before setting a spending limit.');
                    $value = $data['daily_spend_limit'];
                    $digits = (int) $wallet->minor_digits;
                    if (! preg_match('/\A(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,6}))?\z/', $value, $m) || strlen($m[2] ?? '') > $digits) {
                        throw ValidationException::withMessages(['daily_spend_limit' => 'Use an exact non-negative amount within the wallet precision.']);
                    }
                    $changes['daily_spend_limit_minor'] = (int) $m[1] * 10 ** $digits + (int) str_pad($m[2] ?? '', $digits, '0');
                } else {
                    $changes['daily_spend_limit_minor'] = null;
                }
            }
            $a->fill($changes + ['restriction_reason' => $data['reason']])->save();
            app(Foundation::class)->audit($actor, 'client.restrictions_changed', $a, $changes + ['reason' => $data['reason']]);
            app(Notifications::class)->notice($a->id, 'restriction:'.Str::uuid(), 'account.restriction', 'Account restrictions updated', 'Your account operating restrictions changed. Contact support for details.', 'warning');
        });
    }

    private function ready(ClientAccount $a, ?User $owner): bool
    {
        if (! $owner || ! $owner->clientCan('campaigns.operate')) {
            return false;
        }
        if ($a->require_both_contacts && (! $owner->email_verified_at || ! $owner->phone_verified_at)) {
            return false;
        }
        if ($a->require_business_verification && ($a->business_verification !== 'verified' || ! $this->validDocument($a->id))) {
            return false;
        }

        return true;
    }

    public function validDocument(int $account): bool
    {
        return DB::table('client_documents')->where('client_account_id', $account)->where('status', 'approved')->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', now('UTC')->toDateString()))->exists();
    }

    public function dispatchAllowed(Campaign $campaign): bool
    {
        $a = ClientAccount::find($campaign->client_account_id);

        return $a && $a->status === 'active' && ! $a->dispatch_blocked && $this->ready($a, User::find($campaign->owner_id));
    }

    public function launch(Campaign $campaign): void
    {
        if ($campaign->client_account_id === null) {
            return;
        }
        $a = ClientAccount::whereKey($campaign->client_account_id)->lockForUpdate()->firstOrFail();
        abort_unless($a->status === 'active' && ! $a->launch_blocked && ! $a->dispatch_blocked && $this->ready($a, User::find($campaign->owner_id)), 422, 'Account launch/readiness restrictions block this campaign.');
        if ($a->max_active_campaigns !== null) {
            $active = Campaign::where('client_account_id', $a->id)->whereKeyNot($campaign->id)->whereIn('status', ['running', 'scheduled', 'paused'])->count();
            abort_if($active >= $a->max_active_campaigns, 422, 'Account active campaign limit reached.');
        }
    }

    public function spendAllowed(int $account, Wallet $wallet, int $amount, ?int $exceptAttempt = null): bool
    {
        $a = ClientAccount::findOrFail($account);
        if ($a->daily_spend_limit_minor === null) {
            return true;
        }
        $spent = -(int) DB::table('wallet_ledger_entries')->where('wallet_id', $wallet->id)->where('kind', 'call_charge')->where('created_at', '>=', now('UTC')->startOfDay())->sum('amount_minor');
        $held = DB::table('wallet_reservations')->where('wallet_id', $wallet->id)->where('status', 'held');
        if ($exceptAttempt) {
            $held->where('call_attempt_id', '!=', $exceptAttempt);
        }

        return $spent + (int) $held->sum('held_minor') + $amount <= $a->daily_spend_limit_minor;
    }

    public function user(User $actor, ClientAccount $account, User $member, string $role, bool $enabled, string $reason): void
    {
        $this->manage($actor, $account, 'clients.users', 'users.manage');
        abort_unless(in_array($role, Capabilities::ROLES, true), 422);
        DB::transaction(function () use ($actor, $account, $member, $role, $enabled, $reason) {
            app(Engine::class)->mutex();
            ClientAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $u = User::whereKey($member->id)->where('client_account_id', $account->id)->lockForUpdate()->firstOrFail();
            if ($actor->isClient() && ($role === 'owner' || $u->client_role === 'owner')) {
                abort_unless($actor->client_role === 'owner', 403);
            }
            if ($u->client_role === 'owner' && ($role !== 'owner' || ! $enabled)) {
                abort_unless(User::where('client_account_id', $account->id)->where('client_role', 'owner')->where('client_access_enabled', true)->whereKeyNot($u->id)->exists(), 422, 'Keep at least one enabled account owner.');
            }
            $u->forceFill(['client_role' => $role, 'client_access_enabled' => $enabled, 'remember_token' => null])->save();
            $this->revokeSessions($u);
            DB::table('api_credentials')->where('user_id', $u->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            app(Foundation::class)->audit($actor, 'client.user_access_changed', $account, ['user_id' => $u->id, 'role' => $role, 'enabled' => $enabled, 'reason' => $reason]);
        });
    }

    public function revokeSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => null])->save();
    }
}
