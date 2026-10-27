<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Recreates the local development accounts and their roles.
 *
 * The local database was rebuilt with `migrate:fresh`, which removed every
 * user, client and wallet. The rows below are the exact values recovered from
 * the MySQL binary log (including the original bcrypt hashes, so passwords
 * saved in the browser keep working). Re-running it is safe and idempotent:
 * it re-creates anything missing and restores the original passwords, but it
 * never overwrites an existing wallet balance.
 *
 * Usage: php artisan db:seed --class=DevAccountsSeeder
 */
class DevAccountsSeeder extends Seeder
{
    /** Password hashes recovered from the binary log; plain text is unchanged. */
    private const ACCOUNTS = [
        'maxwell.asiedu@gwosevo.com' => [
            'name' => 'Maxwell Obeng-Asiedu',
            'password' => '$2y$12$n5OBGXXYlauaoNiiwA3HhO92whvNCQz8aQptbxq7iEZYp8oKlyjbS',
            'phone_e164' => '+233531005130',
            'phone_verified_at' => '2026-10-25 11:58:10',
            'terms_accepted_at' => '2026-10-25 11:57:31',
            'terms_version' => '2026-10-24',
            'created_at' => '2026-10-26 09:56:02',
            'role' => 'Client Member',
        ],
        'techmgmt@gwosevo.com' => [
            'name' => 'Administrator',
            'password' => '$2y$12$jP3vQTfs0wUgKqQR0IILT.TOwKRTFMGdEyk0H6prpU19BquI2IhYK',
            'phone_e164' => null,
            'phone_verified_at' => null,
            'terms_accepted_at' => null,
            'terms_version' => null,
            'created_at' => '2026-10-26 10:01:15',
            'role' => 'Super Administrator',
        ],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            throw new RuntimeException('DevAccountsSeeder restores known development credentials and must not run outside a local environment.');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->call(RolesAndPermissionsSeeder::class);

        $now = now();
        DB::table('clients')->updateOrInsert(
            ['uuid' => '8a4dcb97-9cad-4e48-a7eb-6dd58dfb7fdd'],
            ['name' => 'Test Account', 'status' => 'active', 'status_reason' => null, 'profile' => json_encode(['country' => 'GH']), 'created_at' => '2026-10-26 09:56:02', 'updated_at' => $now],
        );
        $clientId = (int) DB::table('clients')->where('uuid', '8a4dcb97-9cad-4e48-a7eb-6dd58dfb7fdd')->value('id');

        foreach (self::ACCOUNTS as $email => $account) {
            DB::table('users')->updateOrInsert(
                ['email' => $email],
                [
                    'name' => $account['name'],
                    'password' => $account['password'],
                    'email_verified_at' => null,
                    'phone_e164' => $account['phone_e164'],
                    'phone_verified_at' => $account['phone_verified_at'],
                    'terms_accepted_at' => $account['terms_accepted_at'],
                    'terms_version' => $account['terms_version'],
                    'created_at' => $account['created_at'],
                    'updated_at' => $now,
                ],
            );

            $user = User::where('email', $email)->firstOrFail();
            $role = Role::where('name', $account['role'])->where('guard_name', 'web')->firstOrFail();
            $user->syncRoles([$role]);

            // Every client login is resolved through client_memberships, not a role.
            if ($role->name === 'Client Member') {
                DB::table('client_memberships')->updateOrInsert(
                    ['user_id' => $user->id],
                    ['client_id' => $clientId, 'role' => 'owner', 'status' => 'active', 'created_at' => '2026-10-26 09:56:02', 'updated_at' => $now],
                );
            }
        }

        $this->restoreClientBilling($clientId);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Restores the wallet, funding entry and tariff that belonged to the client. */
    private function restoreClientBilling(int $clientId): void
    {
        // Never reset a balance that has since been spent on re-runs.
        if (! DB::table('wallets')->where('client_id', $clientId)->exists()) {
            DB::table('wallets')->insert(['client_id' => $clientId, 'currency' => 'GHS', 'minor_unit_scale' => 2, 'available_minor' => 1000, 'reserved_minor' => 0, 'created_at' => '2026-10-26 16:18:24', 'updated_at' => now()]);
        }
        $walletId = (int) DB::table('wallets')->where('client_id', $clientId)->value('id');
        $adminId = (int) DB::table('users')->where('email', 'techmgmt@gwosevo.com')->value('id');

        DB::table('wallet_ledger_entries')->updateOrInsert(
            ['idempotency_key' => '66c7437b-1636-4b91-8646-97d380a57e62'],
            [
                'wallet_id' => $walletId,
                'client_id' => $clientId,
                'call_attempt_id' => null,
                'wallet_reservation_id' => null,
                'type' => 'funding',
                'available_delta_minor' => 1000,
                'reserved_delta_minor' => 0,
                'available_after_minor' => 1000,
                'reserved_after_minor' => 0,
                'currency' => 'GHS',
                'minor_unit_scale' => 2,
                'reason' => 'Testing',
                'approval_reference' => 'Manual credit by Administrator (user #'.$adminId.') on 2026-10-26T16:18:51+00:00',
                'actor_id' => $adminId,
                'reversal_of_id' => null,
                'created_at' => '2026-10-26 16:18:51',
            ],
        );

        if (! DB::table('call_tariffs')->where('code', 'ghs-standard')->where('version', 1)->exists()) {
            DB::table('call_tariffs')->insert(['code' => 'ghs-standard', 'version' => 1, 'currency' => 'GHS', 'minor_unit_scale' => 2, 'rate_minor_per_minute' => 30, 'playback_policy' => 'charge_answered', 'created_by' => $adminId, 'evidence' => 'Sandard Rate', 'created_at' => '2026-10-26 16:30:25']);
        }
        DB::table('client_tariffs')->updateOrInsert(
            ['client_id' => $clientId],
            ['call_tariff_id' => DB::table('call_tariffs')->where('code', 'ghs-standard')->where('version', 1)->value('id'), 'updated_at' => '2026-10-26 16:30:48'],
        );
    }
}
