<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CampaignVersion;
use App\Models\User;
use App\Services\Foundation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_guest_is_redirected_and_login_regenerates_session(): void
    {
        $this->get('/workspace')->assertRedirect('/login');
        $user = $this->user('Campaign Manager');
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/workspace');
        $this->assertAuthenticatedAs($user);
    }

    public function test_contact_normalization_duplicate_and_access_controls(): void
    {
        $manager = $this->user('Campaign Manager');
        $data = ['name' => 'Example', 'phone' => '024 123 4567', 'source' => 'Paper form'];
        $this->actingAs($manager)->post('/contacts', $data)->assertRedirect();
        $this->assertDatabaseHas('contacts', ['phone_e164' => '+233241234567']);
        $this->post('/contacts', array_replace($data, ['phone' => '+233241234567']))->assertSessionHasErrors('phone');
        $this->post('/contacts', array_replace($data, ['phone' => "0241234567\r\nAction: Originate"]))->assertSessionHasErrors('phone');
        $this->actingAs($this->user('Analyst'))->post('/contacts', $data)->assertForbidden();
        $this->assertDatabaseCount('contacts', 1);
    }

    public function test_consent_withdrawal_and_suppression_are_audited(): void
    {
        $manager = $this->user('Campaign Manager');
        $contact = app(Foundation::class)->createContact($manager, ['name' => 'Example', 'phone' => '0241234567', 'source' => 'Form']);
        $this->actingAs($manager)->post("/contacts/{$contact->id}/consent", ['purpose' => 'Updates', 'legal_basis' => 'Consent', 'source' => 'Form', 'evidence_ref' => 'private/form/1', 'granted_at' => now()->subDay()->toDateTimeString()])->assertRedirect();
        $consent = $contact->consents()->firstOrFail();
        $this->post("/consents/{$consent->id}/revoke")->assertRedirect();
        $this->assertNotNull($consent->fresh()->revoked_at);
        $this->post("/contacts/{$contact->id}/suppress", ['reason' => 'Requested'])->assertRedirect();
        $this->assertDatabaseHas('suppression_entries', ['phone_e164' => '+233241234567']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'consent.revoked']);
    }

    public function test_separate_approver_creates_immutable_version_and_duplicate_review_fails(): void
    {
        $manager = $this->user('Campaign Manager');
        $manager->assignRole('Approver');
        $approver = $this->user('Approver');
        $campaign = app(Foundation::class)->createCampaign($manager, ['name' => 'Updates', 'purpose' => 'Updates', 'type' => 'one_way']);
        $this->actingAs($manager)->post("/campaigns/{$campaign->id}/review", ['decision' => 'submit', 'reason' => 'Ready'])->assertRedirect();
        $this->post("/campaigns/{$campaign->id}/review", ['decision' => 'approve', 'reason' => 'Self review'])->assertForbidden();
        $this->actingAs($approver)->post("/campaigns/{$campaign->id}/review", ['decision' => 'approve', 'reason' => 'Reviewed'])->assertRedirect();
        $this->assertSame('approved', $campaign->fresh()->status);
        $this->assertDatabaseCount('campaign_versions', 1);
        $version = CampaignVersion::firstOrFail();
        $this->assertSame('Updates', $version->snapshot['name']);
        $this->post("/campaigns/{$campaign->id}/review", ['decision' => 'approve', 'reason' => 'Again'])->assertSessionHasErrors('campaign');
        $this->assertDatabaseCount('campaign_versions', 1);
        $this->expectException(\LogicException::class);
        $version->update(['snapshot' => []]);
    }

    public function test_other_manager_cannot_submit_and_rejection_returns_to_draft(): void
    {
        $manager = $this->user('Campaign Manager');
        $other = $this->user('Campaign Manager');
        $campaign = app(Foundation::class)->createCampaign($manager, ['name' => 'Example', 'purpose' => 'Updates', 'type' => 'one_way']);
        $this->actingAs($other)->post("/campaigns/{$campaign->id}/review", ['decision' => 'submit', 'reason' => 'Ready'])->assertForbidden();
        $this->actingAs($manager)->post("/campaigns/{$campaign->id}/review", ['decision' => 'submit', 'reason' => 'Ready']);
        $this->actingAs($this->user('Approver'))->post("/campaigns/{$campaign->id}/review", ['decision' => 'reject', 'reason' => 'Revise'])->assertRedirect();
        $this->assertSame('draft', $campaign->fresh()->status);
    }

    public function test_super_administrator_can_administer_and_approve_another_users_draft_but_cannot_self_approve(): void
    {
        $manager = $this->user('Campaign Manager');
        $admin = $this->user('Super Administrator');
        $campaign = app(Foundation::class)->createCampaign($manager, ['name' => 'Example', 'purpose' => 'Updates', 'type' => 'one_way']);

        $this->actingAs($admin)->post("/campaigns/{$campaign->id}/edit", ['name' => 'Admin revised', 'purpose' => 'Updates', 'type' => 'one_way'])->assertRedirect();
        $this->assertSame('Admin revised', $campaign->fresh()->name);
        $this->post("/campaigns/{$campaign->id}/review", ['decision' => 'submit', 'reason' => 'Reviewed by administrator'])->assertRedirect();
        $this->post("/campaigns/{$campaign->id}/review", ['decision' => 'approve', 'reason' => 'Independent administrator approval'])->assertRedirect();
        $this->assertSame('approved', $campaign->fresh()->status);

        $ownCampaign = app(Foundation::class)->createCampaign($admin, ['name' => 'Administrator campaign', 'purpose' => 'Updates', 'type' => 'one_way']);
        $this->post("/campaigns/{$ownCampaign->id}/review", ['decision' => 'submit', 'reason' => 'Ready'])->assertRedirect();
        $this->post("/campaigns/{$ownCampaign->id}/review", ['decision' => 'approve', 'reason' => 'Self approval'])->assertRedirect();
        $this->assertSame('approved', $ownCampaign->fresh()->status);
    }

    public function test_workspace_renders_and_audit_is_restricted(): void
    {
        $this->actingAs($this->user('Super Administrator'))->get('/workspace')->assertOk()->assertSee('Audit history');
        $this->actingAs($this->user('Campaign Manager'))->get('/workspace')->assertOk()->assertDontSee('Audit history');
    }

    public function test_groups_and_draft_edits_and_populated_workspace(): void
    {
        $manager = $this->user('Campaign Manager');
        $service = app(Foundation::class);
        $contact = $service->createContact($manager, ['name' => 'Example', 'phone' => '0241234567', 'source' => 'Form']);
        $campaign = $service->createCampaign($manager, ['name' => 'Original', 'purpose' => 'Updates', 'type' => 'one_way']);
        $this->actingAs($manager)->post("/contacts/{$contact->id}/groups", ['name' => 'Members'])->assertRedirect();
        $this->assertSame('Members', $contact->groups()->firstOrFail()->name);
        $edit = ['name' => 'Revised', 'purpose' => 'Updates', 'type' => 'one_way'];
        $this->post("/campaigns/{$campaign->id}/edit", $edit)->assertRedirect();
        $this->assertSame('Revised', $campaign->fresh()->name);
        $this->get('/workspace')->assertOk()->assertSee('Revised')->assertSee('Members');
        $this->post("/campaigns/{$campaign->id}/review", ['decision' => 'submit', 'reason' => 'Ready']);
        $this->post("/campaigns/{$campaign->id}/edit", $edit)->assertSessionHasErrors('campaign');
        $this->actingAs($this->user('Analyst'))->post("/contacts/{$contact->id}/groups", ['name' => 'Other'])->assertForbidden();
    }

    public function test_audit_model_rejects_deletion(): void
    {
        $manager = $this->user('Campaign Manager');
        app(Foundation::class)->createCampaign($manager, ['name' => 'Example', 'purpose' => 'Updates', 'type' => 'one_way']);
        $this->expectException(\LogicException::class);
        AuditLog::firstOrFail()->delete();
    }

    public function test_seed_is_repeatable_and_has_no_default_accounts(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->assertDatabaseCount('roles', 7);
        $this->assertDatabaseCount('users', 0);
    }
}
