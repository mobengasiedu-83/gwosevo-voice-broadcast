<?php

namespace App\Console\Commands;

use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class CreateClientUser extends Command
{
    protected $signature = 'client:user {account : Client account ID} {email} {--name=}';

    protected $description = 'Provision a new client user with an interactive password; no public registration';

    public function handle(): int
    {
        $account = ClientAccount::find($this->argument('account'));
        if (! $account || $account->status !== 'active') {
            $this->error('Choose an active client account.');

            return self::FAILURE;
        }
        $data = ['name' => $this->option('name') ?: $this->ask('Name'), 'email' => $this->argument('email'), 'password' => $this->secret('Strong password')];
        $validator = Validator::make($data, ['name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email', 'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        DB::transaction(function () use ($data, $account) {
            $account = ClientAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            abort_unless($account->status === 'active', 403);
            $user = new User($data);
            $user->client_account_id = $account->id;
            $user->client_role = $account->users()->exists() ? 'administrator' : 'owner';
            $user->save();
            $user->assignRole(Role::findOrCreate('Client', 'web'));
            DB::table('audit_logs')->insert(['actor_id' => null, 'action' => 'client_user.provisioned', 'entity_type' => User::class, 'entity_id' => $user->id, 'client_account_id' => $account->id, 'details' => json_encode(['source' => 'console']), 'created_at' => now()]);
        });
        $this->info('Client user created. Internal operational access remains disabled.');

        return self::SUCCESS;
    }
}
