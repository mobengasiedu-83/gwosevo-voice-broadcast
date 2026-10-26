<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Services\Clients\Accounts;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientAdminTest extends TestCase
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

    public function test_admin_can_create_filter_and_render_every_client_section(): void
    {
        $this->actingAs($this->admin)->post('/clients', ['name' => 'Alpha customer'])->assertRedirect();
        $client = Client::where('name', 'Alpha customer')->firstOrFail();
        Client::create(['name' => 'Hidden customer', 'status' => 'closed']);
        $this->get('/clients?q=Alpha&status=pending')->assertOk()->assertSee('Alpha customer')->assertDontSee('Hidden customer');
        foreach (['overview', 'profile', 'users', 'campaigns', 'activity', 'finance'] as $tab) {
            $this->get('/clients/'.$client->id.'?tab='.$tab)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->post('/clients/'.$client->id.'/profile', ['name' => 'Updated', 'country' => 'Ghana'])->assertRedirect();
        $this->assertSame('Ghana', $client->fresh()->profile['country']);
        $this->post('/clients/'.$client->id.'/status', ['status' => 'active'])->assertSessionHasErrors('reason');
        $this->post('/clients/'.$client->id.'/status', ['status' => 'active', 'reason' => 'Onboarding complete'])->assertRedirect();
        $this->assertSame('active', $client->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['client_id' => $client->id, 'action' => 'client.profile_updated']);
    }

    public function test_user_creation_and_membership_changes_are_scoped_and_audited(): void
    {
        $client = app(Accounts::class)->create($this->admin, 'Customer');
        $other = app(Accounts::class)->create($this->admin, 'Other');
        $base = '/clients/'.$client->id;
        $this->actingAs($this->admin)->post($base.'/users', ['name' => 'New member', 'email' => 'NEW@example.com', 'password' => 'StrongPassword!123', 'password_confirmation' => 'StrongPassword!123', 'role' => 'owner'])->assertRedirect();
        $user = User::where('email', 'new@example.com')->firstOrFail();
        $membership = $user->clientMembership;
        $this->assertNull($user->email_verified_at);
        $this->assertTrue($user->hasRole('Client Member'));
        $this->post('/clients/'.$other->id.'/memberships/'.$membership->id, ['role' => 'member', 'status' => 'revoked', 'reason' => 'Review'])->assertNotFound();
        $this->post($base.'/memberships/'.$membership->id, ['role' => 'member', 'status' => 'revoked', 'reason' => 'Review'])->assertRedirect();
        $this->assertSame('revoked', $membership->fresh()->status);
        $this->post($base.'/assign', ['email' => $this->admin->email, 'role' => 'owner', 'reason' => 'Attempt'])->assertStatus(422);
        $this->assertDatabaseHas('audit_logs', ['client_id' => $client->id, 'action' => 'client.user_created']);
    }

    public function test_clients_and_unprivileged_staff_cannot_read_or_mutate_admin_endpoints(): void
    {
        $client = app(Accounts::class)->create($this->admin, 'Customer');
        $user = User::factory()->create();
        foreach (['unprivileged', 'client'] as $type) {
            if ($type === 'client') {
                $this->actingAs($this->admin);
                app(Accounts::class)->member($this->admin, $client, $user);
                app(Accounts::class)->status($this->admin, $client, 'active', 'Ready');
            }
            $this->actingAs($user)->get('/clients')->assertForbidden();
            $this->get('/clients/999999')->assertForbidden();
            $this->post('/clients', ['name' => 'Forbidden'])->assertForbidden();
            foreach (['profile', 'status', 'users', 'assign', 'memberships/999999'] as $action) {
                $this->post('/clients/'.$client->id.'/'.$action)->assertForbidden();
            }
        }
    }
}
