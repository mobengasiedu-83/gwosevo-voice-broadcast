<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Clients\Accounts;
use App\Services\StaffRoles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffUserTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
    }

    public function test_super_administrator_can_create_every_internal_role_with_hashed_password_and_audit(): void
    {
        $this->actingAs($this->admin)->get(route('staff.index'))->assertOk()->assertSee('Configured roles &amp; permissions', false);
        foreach (array_keys(StaffRoles::DESCRIPTIONS) as $index => $role) {
            $email = 'staff'.$index.'@example.com';
            $this->post(route('staff.store'), ['name' => 'Staff '.$index, 'email' => $email,
                'role' => $role, 'password' => 'StrongPassword123!', 'password_confirmation' => 'StrongPassword123!',
            ])->assertRedirect(route('staff.index'));
            $user = User::where('email', $email)->firstOrFail();
            $this->assertTrue($user->hasRole($role));
            $this->assertTrue(Hash::check('StrongPassword123!', $user->password));
            $this->assertFalse($user->clientMembership()->exists());
            $this->assertDatabaseHas('audit_logs', ['actor_id' => $this->admin->id, 'action' => 'staff.created', 'entity_id' => $user->id]);
        }
    }

    public function test_staff_management_is_permissioned_and_does_not_modify_client_users(): void
    {
        $ordinary = User::factory()->create();
        $ordinary->assignRole('Administrator');
        $this->actingAs($ordinary)->get(route('staff.index'))->assertForbidden();
        $this->post(route('staff.store'), [])->assertForbidden();
        $this->post(route('staff.role', $this->admin), ['role' => 'Analyst'])->assertForbidden();
        $client = app(Accounts::class)->create($this->admin, 'Client account');
        app(Accounts::class)->status($this->admin, $client, 'active', 'Ready');
        $member = User::factory()->create(['name' => 'Private client member']);
        app(Accounts::class)->member($this->admin, $client, $member);
        $this->actingAs($member)->get(route('staff.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('staff.index'))->assertOk()->assertDontSee('Private client member');
        $this->post(route('staff.role', $member), ['role' => 'Analyst'])->assertNotFound();
    }

    public function test_invalid_roles_duplicate_email_and_weak_password_are_rejected(): void
    {
        $this->actingAs($this->admin)->postJson(route('staff.store'), ['role' => 'Client Member'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postJson(route('staff.store'), ['role' => 'Analyst', 'name' => 'Test', 'email' => $this->admin->email,
            'password' => 'weak', 'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_role_changes_are_audited_revoke_sessions_and_preserve_final_super_administrator(): void
    {
        $staff = User::factory()->create();
        $staff->assignRole('Operator');
        $this->actingAs($this->admin)->post(route('staff.role', $staff), ['role' => 'Analyst'])->assertRedirect(route('staff.index'));
        $this->assertTrue($staff->fresh()->hasRole('Analyst'));
        $this->assertFalse($staff->fresh()->hasRole('Operator'));
        $this->assertSame(1, $staff->fresh()->auth_version);
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.role_changed', 'actor_id' => $this->admin->id]);
        $this->postJson(route('staff.role', $this->admin), ['role' => 'Administrator'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertTrue($this->admin->fresh()->isSuperAdministrator());
    }

    public function test_delegated_user_manager_cannot_grant_or_modify_super_administrator(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Administrator');
        $manager->givePermissionTo('users.manage');
        $this->actingAs($manager)->post(route('staff.store'), ['role' => 'Super Administrator'])->assertForbidden();
        $this->post(route('staff.role', $this->admin), ['role' => 'Analyst'])->assertForbidden();
    }
}
