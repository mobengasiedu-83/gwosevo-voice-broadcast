<?php

namespace Tests\Feature;

use App\Models\AudioFile;
use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\ContactGroup;
use App\Models\TrunkProfile;
use App\Models\User;
use App\Services\Broadcast\AudioLibrary;
use App\Services\Campaigns\Workspace;
use App\Services\Foundation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CampaignModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $approver;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        $this->manager = $this->user('Campaign Manager');
        $this->approver = $this->user('Approver');
        $this->operator = $this->user('Operator');
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    private function draft(): Campaign
    {
        return app(Foundation::class)->createCampaign($this->manager, ['name' => 'Private updates', 'purpose' => 'Updates', 'type' => 'one_way']);
    }

    private function data(): array
    {
        return ['name' => 'New updates', 'purpose' => 'Updates', 'type' => 'one_way', 'dialing_strategy' => 'direct', 'timezone' => 'Africa/Accra', 'window_start' => '08:00', 'window_end' => '18:00', 'concurrency' => 2, 'cps' => 1, 'priority' => 3, 'max_attempts' => 2, 'retry_delay_seconds' => 300];
    }

    private function ready(): Campaign
    {
        config()->set('broadcasting_discovery.trunk', 'module-test');
        $t = TrunkProfile::create(['name' => 'module-test', 'caller_id' => '0596921082', 'max_channels' => 10, 'reserved_channels' => 2, 'safe_channels' => 5, 'max_cps' => 2, 'number_format' => 'national', 'active' => true, 'verified_at' => now(), 'verified_by' => $this->manager->id, 'evidence' => 'verified']);
        Storage::disk('local')->put('module.wav', 'fixture');
        $a = AudioFile::create(['uuid' => (string) Str::uuid(), 'name' => 'Module message', 'storage_key' => 'module.wav', 'checksum' => hash('sha256', 'fixture'), 'status' => 'approved', 'uploaded_by' => $this->manager->id, 'approved_by' => $this->approver->id, 'approved_at' => now(), 'deployed_at' => now()]);
        $c = app(Foundation::class)->createContact($this->manager, ['name' => 'Recipient', 'phone' => '0241000100', 'source' => 'Form']);
        $c->consents()->create(['purpose' => 'Updates', 'legal_basis' => 'Consent', 'source' => 'Form', 'evidence_ref' => 'Form1', 'granted_at' => now()->subDay()]);

        return app(Workspace::class)->save($this->manager, null, $this->data() + ['audio_id' => $a->id, 'contact_ids' => (string) $c->id]);
    }

    public function test_access_scoped_search_filters_and_draft_editor(): void
    {
        $c = $this->draft();
        $other = $this->user('Campaign Manager');
        $this->get('/campaigns')->assertRedirect('/login');
        $this->actingAs($other)->get('/campaigns')->assertOk()->assertDontSee('Private updates');
        foreach (['show', 'edit'] as $route) {
            $this->get(route('campaigns.'.$route, $c))->assertForbidden();
        }
        $this->post(route('campaigns.duplicate', $c), ['name' => 'Stolen'])->assertForbidden();
        $this->actingAs($this->manager)->get('/campaigns?q=Private&status=draft')->assertOk()->assertSee('Private updates');
        $this->get('/campaigns?q=Missing')->assertDontSee('Private updates');
        $this->get('/campaigns/create')->assertOk()->assertSee('Direct automated broadcast');
        $this->actingAs($this->approver)->post('/campaigns/create', $this->data())->assertForbidden();
    }

    public function test_save_incomplete_draft_and_reject_unsupported_strategy(): void
    {
        $this->actingAs($this->manager)->post('/campaigns/create', $this->data())->assertRedirect();
        $c = Campaign::firstOrFail();
        $this->get(route('campaigns.show', $c))->assertOk()->assertSee('Resolve before launch');
        $this->post(route('campaigns.module_review', $c), ['decision' => 'submit', 'reason' => 'ready'])->assertSessionHasErrors();
        $this->post('/campaigns/create', array_replace($this->data(), ['dialing_strategy' => 'predictive']))->assertSessionHasErrors('dialing_strategy');
        $this->post('/campaigns/create', array_replace($this->data(), ['window_end' => '07:00']))->assertSessionHasErrors('window_end');
        $this->assertSame('draft', $c->fresh()->status);
    }

    public function test_group_membership_is_frozen_on_save_and_foreign_groups_denied(): void
    {
        $c = $this->ready();
        $id = $c->configuration['contact_ids'][0];
        $g = ContactGroup::create(['owner_id' => $this->manager->id, 'name' => 'Audience']);
        $g->contacts()->attach($id);
        $d = $this->data() + ['source_group_id' => $g->id];
        $this->actingAs($this->manager)->post('/campaigns/create', $d)->assertRedirect();
        $new = Campaign::latest('id')->first();
        $this->assertEquals([$id], $new->configuration['contact_ids']);
        $g->contacts()->detach();
        $this->assertEquals([$id], $new->fresh()->configuration['contact_ids']);
        $this->actingAs($this->user('Campaign Manager'))->post('/campaigns/create', $d)->assertNotFound();
    }

    public function test_ready_campaign_starts_directly_and_creates_an_immutable_run_snapshot(): void
    {
        $c = $this->ready();
        $this->assertSame('ready', $c->status);
        $this->actingAs($this->manager)->get(route('campaigns.show', $c))->assertOk()->assertSee('Campaign controls')->assertSee('Edit campaign')->assertSee('Start');
        $this->actingAs($this->manager)->post(route('campaigns.control', $c), ['action' => 'start', 'reason' => 'Authorized'])->assertRedirect();
        $run = CampaignRun::firstOrFail();
        $this->assertEquals($c->id, $run->campaign_id);
        $this->assertSame('0596921082', $run->snapshot['broadcast']['caller_id']);
        $this->post(route('campaigns.control', $c), ['action' => 'start', 'reason' => 'Again'])->assertSessionHasErrors();
        $this->assertDatabaseCount('campaign_runs', 1);
        $this->get('/campaigns/runs')->assertOk()->assertSee('New updates');
        $this->assertDatabaseHas('audit_logs', ['action' => 'campaign.control_reason']);
    }

    public function test_audio_approval_moves_linked_draft_campaign_to_ready(): void
    {
        config()->set('broadcasting_discovery.trunk', 'configured');
        $t = TrunkProfile::create(['name' => 'configured', 'caller_id' => '0596921082', 'max_channels' => 10, 'reserved_channels' => 2, 'safe_channels' => 5, 'max_cps' => 2, 'number_format' => 'national', 'active' => true, 'verified_at' => now(), 'verified_by' => $this->manager->id, 'evidence' => 'verified']);
        Storage::disk('local')->put('pending.wav', 'fixture');
        $audio = AudioFile::create(['uuid' => (string) Str::uuid(), 'name' => 'Pending message', 'storage_key' => 'pending.wav', 'checksum' => hash('sha256', 'fixture'), 'status' => 'pending', 'uploaded_by' => $this->manager->id]);
        $contact = app(Foundation::class)->createContact($this->manager, ['name' => 'Recipient', 'phone' => '0241000101', 'source' => 'Form']);
        $campaign = app(Workspace::class)->save($this->manager, null, $this->data() + ['audio_id' => $audio->id, 'contact_ids' => (string) $contact->id]);
        $this->assertSame('draft', $campaign->status);
        app(AudioLibrary::class)->approve($this->approver, $audio);
        $this->assertSame('ready', $campaign->fresh()->status);
        $this->assertSame($t->id, $campaign->fresh()->configuration['trunk_id']);
    }

    public function test_duplicate_removes_execution_state_and_schedule(): void
    {
        $c = $this->draft();
        $c->update(['status' => 'completed', 'configuration' => ['scheduled_at' => '2030-01-01T12:00', 'priority' => 3], 'stop_requested' => true]);
        $this->actingAs($this->manager)->post(route('campaigns.duplicate', $c), ['name' => 'New run'])->assertRedirect();
        $new = Campaign::latest('id')->first();
        $this->assertSame('draft', $new->status);
        $this->assertFalse((bool) $new->stop_requested);
        $this->assertNull($new->active_version_id);
        $this->assertArrayNotHasKey('scheduled_at', $new->configuration);
        $this->assertSame($c->id, $new->source_campaign_id);
        $this->assertDatabaseCount('campaign_versions', 0);
    }

    public function test_template_versions_immutable_and_do_not_carry_audience_or_approval(): void
    {
        $c = $this->ready();
        $w = app(Workspace::class);
        $t = $w->template($this->manager, $c, 'Updates', null);
        $v2 = $w->template($this->manager, $c, 'Updates v2', $t);
        $this->assertSame(2, $v2->version);
        $this->assertSame($t->family_uuid, $v2->family_uuid);
        $this->assertArrayNotHasKey('contact_ids', $t->blueprint['configuration']);
        $this->actingAs($this->manager)->get('/campaigns/templates')->assertOk()->assertSee('Updates v2');
        $this->post(route('campaigns.instantiate', $t), ['name' => 'From template'])->assertRedirect();
        $new = Campaign::latest('id')->first();
        $this->assertSame('draft', $new->status);
        $this->assertSame([], $new->configuration['contact_ids']);
        $this->actingAs($this->user('Campaign Manager'))->post(route('campaigns.instantiate', $t), ['name' => 'Steal'])->assertForbidden();
        $this->expectException(\LogicException::class);
        $t->update(['name' => 'Tampered']);
    }

    public function test_archive_is_reversible_blocks_edit_and_cannot_hide_active_run(): void
    {
        $c = $this->draft();
        $this->actingAs($this->manager)->post(route('campaigns.archive', $c), ['action' => 'archive'])->assertRedirect();
        $this->get('/campaigns')->assertDontSee('Private updates');
        $this->get('/campaigns?archived=1')->assertSee('Private updates');
        $this->get(route('campaigns.edit', $c))->assertUnprocessable();
        $this->post(route('campaigns.archive', $c), ['action' => 'restore'])->assertRedirect();
        $c->update(['status' => 'running']);
        $this->post(route('campaigns.archive', $c), ['action' => 'archive'])->assertUnprocessable();
        $this->assertNull($c->fresh()->archived_at);
    }

    public function test_schedules_allocation_and_control_permissions(): void
    {
        $c = $this->ready();
        $c->update(['status' => 'scheduled', 'scheduled_at' => now()->addDay()]);
        $this->actingAs($this->operator)->get('/campaigns?view=scheduled')->assertOk()->assertSee('Schedule calendar');
        $this->get('/campaigns?view=allocation')->assertOk()->assertSee('relative scheduling shares');
        $this->actingAs($this->user('Campaign Manager'))->post(route('campaigns.control', $c), ['action' => 'cancel', 'reason' => 'No'])->assertForbidden();
        $this->actingAs($this->user('Super Administrator'))->post(route('campaigns.control', $c), ['action' => 'cancel', 'reason' => 'Withdrawn'])->assertRedirect();
        $this->assertSame('cancelled', $c->fresh()->status);
    }

    public function test_both_layouts_offer_persistent_theme_switch_and_escape_names(): void
    {
        $this->get('/login')->assertOk()->assertSee('data-theme-toggle', false)->assertSee('js/theme.js');
        $c = $this->draft();
        $c->update(['name' => '<script>alert(1)</script>']);
        $this->actingAs($this->manager)->get('/campaigns')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertSee('data-theme-toggle', false);
    }
}
