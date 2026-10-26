<?php

namespace Tests\Feature;

use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\User;
use App\Services\Clients\Accounts;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $this->client = app(Accounts::class)->create($this->admin, 'Alpha account');
        app(Accounts::class)->status($this->admin, $this->client, 'active', 'Ready');
        $this->member = User::factory()->create();
        app(Accounts::class)->member($this->admin, $this->client, $this->member, 'owner');
    }

    public function test_client_lands_on_account_dashboard_with_useful_empty_state_and_client_navigation(): void
    {
        $this->actingAs($this->member)->get('/dashboard')->assertOk()->assertSee('Alpha account')->assertSee('Get started')->assertSee('Client portal')->assertSee('No campaigns yet')->assertDontSee('System Health')->assertDontSee('Telephony')->assertDontSee('Gateway offline')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/dashboard/live')->assertOk()->assertSee('Client portal')->assertDontSee('PBX telemetry')->assertDontSee('Live dispatch is disabled');
        $this->get('/contacts')->assertOk()->assertSee('Client portal')->assertDontSee('System Health');
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertSee('System Health')->assertDontSee('Get started');
    }

    public function test_shared_account_campaigns_and_daily_metrics_exclude_other_accounts(): void
    {
        $colleague = User::factory()->create();
        app(Accounts::class)->member($this->admin, $this->client, $colleague);
        $own = Campaign::create(['owner_id' => $colleague->id, 'name' => 'Shared campaign', 'purpose' => 'Test', 'status' => 'running']);
        $otherClient = app(Accounts::class)->create($this->admin, 'Secret account');
        $foreign = Campaign::create(['client_id' => $otherClient->id, 'owner_id' => $this->admin->id, 'name' => 'Secret campaign', 'purpose' => 'Test']);
        foreach ([$own, $foreign] as $campaign) {
            $recipient = CampaignRecipient::create(['campaign_id' => $campaign->id]);
            $uuid = (string) Str::uuid();
            CallAttempt::create(['uuid' => $uuid, 'uniqueid' => $uuid, 'campaign_recipient_id' => $recipient->id, 'sent_at' => '2026-10-25 23:30:00', 'answered_at' => '2026-10-25 23:31:00', 'ended_at' => '2026-10-25 23:32:00', 'playback_completed_at' => '2026-10-25 23:32:00', 'state' => 'terminal', 'outcome' => 'completed']);
        }
        $this->actingAs($this->member)->get('/dashboard?date=2026-10-26&timezone=Africa/Accra')->assertOk()->assertViewHas('calls_today', 0)->assertSee('Shared campaign')->assertDontSee('Secret campaign')->assertDontSee('Secret account');
        $this->get('/dashboard?date=2026-10-26&timezone=Africa/Nairobi')->assertOk()->assertViewHas('calls_today', 1)->assertViewHas('completed', 1)->assertViewHas('answer_rate', 100.0);
        $this->getJson('/dashboard?date=2026-10-25')->assertOk()->assertJsonStructure(['html', 'generated_at'])->assertSee('Shared campaign')->assertDontSee('Secret campaign')->assertDontSee('PBX');
        $this->get('/dashboard?campaign_id='.$foreign->id)->assertForbidden();
        $this->getJson('/dashboard?campaign_id='.$foreign->id)->assertForbidden();
    }

    public function test_invalid_filters_inactive_memberships_and_unassigned_client_logins_are_denied(): void
    {
        $this->actingAs($this->member)->getJson('/dashboard?date=invalid&timezone=invalid')->assertUnprocessable()->assertJsonValidationErrors(['date', 'timezone']);
        $this->actingAs($this->admin);
        app(Accounts::class)->member($this->admin, $this->client, $this->member, 'owner', 'revoked');
        $this->actingAs($this->member)->get('/dashboard')->assertForbidden();
        $this->actingAs($this->admin);
        app(Accounts::class)->member($this->admin, $this->client, $this->member, 'owner');
        app(Accounts::class)->status($this->admin, $this->client, 'suspended', 'Review');
        $this->actingAs($this->member)->getJson('/dashboard')->assertForbidden();
        $unassigned = User::factory()->create();
        $unassigned->assignRole('Client Member');
        $this->actingAs($unassigned)->get('/dashboard')->assertForbidden();
    }
}
