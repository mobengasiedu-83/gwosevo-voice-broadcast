<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Foundation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class CreateFoundationUser extends Command
{
    protected $signature = 'foundation:user {email} {--name=} {--role=Campaign Manager}';

    protected $description = 'Create a foundation user with an interactively entered password';

    public function handle(Foundation $foundation): int
    {
        $this->call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
        $email = $this->argument('email');
        $name = $this->option('name') ?: $this->ask('Name');
        $role = $this->option('role');
        if (! Role::where('name', $role)->where('guard_name', 'web')->exists()) {
            $this->error('Unknown role.');

            return self::FAILURE;
        }
        $password = $this->secret('Password (at least 12 characters)');
        $validator = Validator::make(compact('email', 'name', 'password'), ['email' => 'required|email|unique:users,email', 'name' => 'required|string|max:255', 'password' => ['required', Password::min(12)]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        DB::transaction(function () use ($email, $name, $password, $role, $foundation) {
            $user = User::create(compact('email', 'name', 'password'));
            $user->assignRole($role);
            $foundation->audit($user, 'user.provisioned', $user, ['role' => $role, 'source' => 'console']);
        });
        $this->info('User created.');

        return self::SUCCESS;
    }
}
