<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientMembership;
use App\Models\User;
use App\Services\Broadcast\Engine;
use App\Services\Foundation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class Accounts
{
    public function create(User $actor, string $name): Client
    {
        Gate::forUser($actor)->authorize('clients.manage');
        Validator::make(['name' => $name], ['name' => 'required|string|max:255'])->validate();

        return DB::transaction(function () use ($actor, $name) {
            $client = Client::create(['name' => $name, 'status' => 'pending']);
            app(Foundation::class)->audit($actor, 'client.created', $client);

            return $client;
        });
    }

    public function status(User $actor, Client $client, string $status, string $reason): void
    {
        Gate::forUser($actor)->authorize('clients.manage');
        Validator::make(compact('status', 'reason'), ['status' => 'required|in:pending,active,suspended,closed', 'reason' => 'required|string|max:2000'])->validate();
        DB::transaction(function () use ($actor, $client, $status, $reason) {
            app(Engine::class)->mutex();
            $client = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $before = $client->status;
            $client->update(['status' => $status, 'status_reason' => $reason]);
            app(Foundation::class)->audit($actor, 'client.status_changed', $client, compact('before', 'status', 'reason'));
        });
    }

    public function profile(User $actor, Client $client, array $data): void
    {
        Gate::forUser($actor)->authorize('clients.manage');
        $validated = Validator::make($data, [
            'name' => 'required|string|max:255', 'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:254', 'contact_phone' => 'nullable|string|max:40',
            'country' => 'nullable|string|max:80', 'address' => 'nullable|string|max:1000',
            'broadcasting_purpose' => 'nullable|string|max:1000',
        ])->validate();
        DB::transaction(function () use ($actor, $client, $validated) {
            $client = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $profile = array_merge($client->profile ?? [], collect($validated)->except('name')->all());
            $client->update(['name' => $validated['name'], 'profile' => $profile]);
            app(Foundation::class)->audit($actor, 'client.profile_updated', $client, ['changed_fields' => array_keys($validated)]);
        });
    }

    public function createUser(User $actor, Client $client, array $data): User
    {
        Gate::forUser($actor)->authorize('clients.manage');
        $data['email'] = empty($data['email']) ? null : mb_strtolower(trim($data['email']));
        $data['phone_e164'] = empty($data['phone']) ? null : app(\App\Services\Identity\Challenges::class)->normalize('sms', $data['phone']);
        $validated = Validator::make($data, [
            'name' => 'required|string|max:255', 'email' => 'nullable|required_without:phone_e164|email|max:254|unique:users,email',
            'phone_e164' => 'nullable|required_without:email|string|max:16|unique:users,phone_e164',
            'password' => ['required', 'confirmed', 'max:128', \Illuminate\Validation\Rules\Password::min(12)->mixedCase()->numbers()->symbols()],
            'role' => 'required|in:owner,member',
        ])->validate();
        return DB::transaction(function () use ($actor, $client, $validated) {
            $user = new User;
            $user->forceFill(collect($validated)->only(['name', 'email', 'phone_e164', 'password'])->all())->save();
            $this->member($actor, $client, $user, $validated['role'], 'active', 'Created by authorized administrator');
            app(Foundation::class)->audit($actor, 'client.user_created', $client, ['user_id' => $user->id, 'role' => $validated['role']]);
            return $user;
        });
    }

    public function member(User $actor, Client $client, User $user, string $role = 'member', string $status = 'active', string $reason = ''): ClientMembership
    {
        Gate::forUser($actor)->authorize('clients.manage');
        Validator::make(compact('role', 'status'), ['role' => 'required|in:owner,member', 'status' => 'required|in:active,revoked'])->validate();

        return DB::transaction(function () use ($actor, $client, $user, $role, $status, $reason) {
            app(Engine::class)->mutex();
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $membership = ClientMembership::where('user_id', $user->id)->first();
            abort_if($membership && $membership->client_id !== $client->id, 422, 'Use a separate login for another client account.');
            // Do not retroactively give an internal identity access to a tenant.
            if (! $membership) {
                abort_if($user->roles()->where('name', '!=', 'Client Member')->exists() || DB::table('model_has_permissions')->where('model_id', $user->id)->where('model_type', User::class)->exists(), 422, 'Use a new user without internal privileges.');
                foreach (['contacts' => 'created_by', 'contact_groups' => 'owner_id', 'contact_imports' => 'owner_id', 'campaigns' => 'owner_id', 'audio_files' => 'uploaded_by', 'ivr_flows' => 'owner_id', 'campaign_templates' => 'owner_id'] as $table => $field) {
                    abort_if(DB::table($table)->where($field, $user->id)->exists(), 422, 'Existing internal records require a separate reviewed migration.');
                }
            }
            $m = ClientMembership::updateOrCreate(['user_id' => $user->id], ['client_id' => $client->id, 'role' => $role, 'status' => $status]);
            $user->syncRoles(['Client Member']);
            app(Foundation::class)->audit($actor, 'client.membership_changed', $client, ['user_id' => $user->id, 'role' => $role, 'status' => $status, 'reason' => $reason]);

            return $m;
        });
    }
}
