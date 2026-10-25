<?php

namespace App\Services\Clients;

use App\Models\ClientAccount;
use App\Models\User;
use App\Services\ClientAuth\Delivery;
use App\Services\Foundation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Invitations
{
    public function send(User $actor, ClientAccount $account, array $data): void
    {
        app(Management::class)->manage($actor, $account, 'clients.users', 'users.manage');
        abort_unless(in_array($data['role'], Capabilities::ROLES, true), 422);
        if ($actor->isClient() && $data['role'] === 'owner') {
            abort_unless($actor->client_role === 'owner', 403);
        }
        DB::transaction(function () use ($actor, $account, $data) {
            $a = ClientAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            abort_unless($a->status === 'active', 422);
            $email = strtolower(trim($data['email']));
            abort_if(User::whereRaw('LOWER(email) = ?', [$email])->exists(), 422, 'This address already belongs to a user. No existing user is reassigned.');
            abort_if(DB::table('client_invitations')->where('client_account_id', $a->id)->where('created_at', '>=', now()->subHour())->count() >= 10, 429, 'Account invitation limit reached.');
            DB::table('client_invitations')->where('client_account_id', $a->id)->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            $uuid = (string) Str::uuid();
            $token = Str::random(64);
            DB::table('client_invitations')->insert(['uuid' => $uuid, 'client_account_id' => $a->id, 'invited_by' => $actor->id, 'email' => $email, 'name' => $data['name'], 'role' => $data['role'], 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours(48), 'created_at' => now(), 'updated_at' => now()]);
            app(Delivery::class)->send('email', $email, 'You were invited to join '.$a->name.' on VoiceCast. Set your own password within 48 hours: '.route('client.invitation', ['uuid' => $uuid, 'token' => $token]), 'VoiceCast account invitation');
            app(Foundation::class)->audit($actor, 'client.invitation_sent', $a, ['invitation_id' => $uuid, 'role' => $data['role']]);
        });
    }

    public function valid(string $uuid, string $token): object
    {
        $i = DB::table('client_invitations')->where('uuid', $uuid)->first();
        abort_unless($i && hash_equals($i->token_hash, hash('sha256', $token)) && ! $i->accepted_at && ! $i->revoked_at && now()->lt($i->expires_at) && app(AccountContext::class)->active($i->client_account_id), 410, 'Invitation unavailable or expired.');

        return $i;
    }

    public function accept(string $uuid, string $token, string $password): User
    {
        $candidate = $this->valid($uuid, $token);

        return DB::transaction(function () use ($candidate, $uuid, $token, $password) {
            ClientAccount::whereKey($candidate->client_account_id)->lockForUpdate()->firstOrFail();
            DB::table('client_invitations')->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $i = $this->valid($uuid, $token);
            abort_if(User::whereRaw('LOWER(email) = ?', [$i->email])->exists(), 422, 'This address already belongs to a user.');
            $user = new User(['name' => $i->name, 'email' => $i->email, 'password' => $password]);
            $user->forceFill(['client_account_id' => $i->client_account_id, 'client_role' => $i->role, 'email_verified_at' => now(), 'terms_version' => config('client_auth.terms_version'), 'terms_accepted_at' => now()])->save();
            $user->assignRole('Client');
            DB::table('client_invitations')->where('id', $i->id)->update(['accepted_at' => now(), 'token_hash' => hash('sha256', Str::random(64)), 'updated_at' => now()]);
            app(Foundation::class)->audit($user, 'client.invitation_accepted', $user->clientAccount, ['invitation_id' => $i->uuid]);

            return $user;
        });
    }
}
