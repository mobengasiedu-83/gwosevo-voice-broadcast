<?php

namespace App\Services\Integrations;

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\Foundation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Access
{
    public const SCOPES = ['contacts.read', 'contacts.write', 'campaigns.read', 'campaigns.write', 'reports.read'];

    public function owner(User $user): void
    {
        abort_unless($user->isClient() && $user->clientAccountActive() && ($user->email_verified_at || $user->phone_verified_at), 403, 'An active client with a verified contact is required.');
    }

    public function limits(int $account): array
    {
        $plan = DB::table('client_accounts')->where('id', $account)->value('commercial_plan_id');
        $row = $plan ? DB::table('commercial_plans')->where('id', $plan)->first() : null;

        return $row ? (array) $row : config('integrations.defaults');
    }

    public function consume(int $account, string $metric, int $amount = 1): void
    {
        DB::transaction(function () use ($account, $metric, $amount) {
            DB::table('client_accounts')->where('id', $account)->lockForUpdate()->firstOrFail();
            $limits = $this->limits($account);
            $bucket = $metric === 'requests_per_minute' ? now('UTC')->format('Y-m-d H:i') : now('UTC')->format('Y-m-d');
            $row = DB::table('integration_usage')->where(['client_account_id' => $account, 'bucket' => $bucket, 'metric' => $metric])->first();
            abort_if($amount < 1 || ($row->used ?? 0) + $amount > $limits[$metric], 429, 'Integration usage limit reached.');
            if ($row) {
                DB::table('integration_usage')->where('id', $row->id)->increment('used', $amount, ['updated_at' => now()]);
            } else {
                DB::table('integration_usage')->insert(['client_account_id' => $account, 'bucket' => $bucket, 'metric' => $metric, 'used' => $amount, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function issue(User $actor, User $owner, string $name, array $scopes, int $days = 90): array
    {
        $this->owner($owner);
        abort_unless($owner->clientCan('integrations.manage'), 403);
        abort_unless($actor->id === $owner->id || (! $actor->isClient() && $actor->can('clients.manage')), 403);
        if (trim($name) === '' || mb_strlen($name) > 100 || ! $scopes || array_diff($scopes, self::SCOPES) || $days < 1 || $days > 365) {
            throw ValidationException::withMessages(['scopes' => 'Choose valid scopes and an expiry of 1–365 days.']);
        }

        return DB::transaction(function () use ($actor, $owner, $name, $scopes, $days) {
            DB::table('client_accounts')->where('id', $owner->client_account_id)->lockForUpdate()->firstOrFail();
            abort_if(DB::table('api_credentials')->where('client_account_id', $owner->client_account_id)->whereNull('revoked_at')->where('expires_at', '>', now())->count() >= 10, 422, 'At most ten active credentials per account.');
            $uuid = (string) Str::uuid();
            $secret = Str::random(64);
            $id = DB::table('api_credentials')->insertGetId(['uuid' => $uuid, 'client_account_id' => $owner->client_account_id, 'user_id' => $owner->id, 'name' => $name, 'token_hash' => hash('sha256', $secret), 'scopes' => json_encode(array_values(array_unique($scopes))), 'expires_at' => now()->addDays($days), 'created_at' => now(), 'updated_at' => now()]);
            app(Foundation::class)->audit($actor, 'api.credential_issued', ClientAccount::findOrFail($owner->client_account_id), ['credential_id' => $id, 'scopes' => $scopes]);

            return ['id' => $id, 'token' => 'vc_'.$uuid.'.'.$secret];
        });
    }
}
