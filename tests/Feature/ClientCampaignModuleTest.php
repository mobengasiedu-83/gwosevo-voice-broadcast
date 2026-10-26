<?php

namespace Tests\Feature;

use App\Models\AudioFile;
use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\IvrFlow;
use App\Models\TrunkProfile;
use App\Models\User;
use App\Services\Broadcast\CampaignPolicy;
use App\Services\Broadcast\IvrDesigner;
use App\Services\Campaigns\Workspace;
use App\Services\Clients\Accounts;
use App\Services\Clients\Tenancy;
use App\Services\Foundation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientCampaignModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private User $member;

    private User $foreign;

    private Client $client;

    private ContactGroup $group;

    private Contact $contact;

    private AudioFile $audio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        config()->set('voice_broadcast.media_mode', 'url');
        config()->set('voice_broadcast.media_base_url', 'https://media.example.test');
        config()->set('broadcasting_discovery.trunk', 'client-module-11');
        config()->set('broadcasting_discovery.caller_id', null);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $accounts = app(Accounts::class);
        $this->client = $accounts->create($this->admin, 'Campaign customer');
        $other = $accounts->create($this->admin, 'Other customer');
        foreach ([$this->client, $other] as $client) {
            $accounts->status($this->admin, $client, 'active', 'Ready');
        }
        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->foreign = User::factory()->create();
        $accounts->member($this->admin, $this->client, $this->owner, 'owner');
        $accounts->member($this->admin, $this->client, $this->member);
        $accounts->member($this->admin, $other, $this->foreign, 'owner');
        TrunkProfile::create(['name' => 'client-module-11', 'caller_id' => '0241000100', 'max_channels' => 10, 'reserved_channels' => 2, 'safe_channels' => 5, 'max_cps' => 2, 'number_format' => 'national', 'active' => true, 'verified_at' => now(), 'verified_by' => $this->admin->id, 'evidence' => 'Test capacity']);
        Storage::disk('local')->put('client-11.wav', 'fixture');
        $this->audio = AudioFile::create(['client_id' => $this->client->id, 'uploaded_by' => $this->owner->id, 'uuid' => (string) Str::uuid(), 'name' => 'Approved URL prompt', 'status' => 'approved', 'storage_key' => 'client-11.wav', 'checksum' => hash('sha256', 'fixture'), 'approved_by' => $this->admin->id, 'approved_at' => now()]);
        $this->contact = app(Tenancy::class)->run($this->client->id, fn () => app(Foundation::class)->createContact($this->owner, ['name' => 'Customer', 'phone' => '0241234567', 'source' => 'Form']));
        $this->group = ContactGroup::create(['client_id' => $this->client->id, 'owner_id' => $this->owner->id, 'name' => 'Colleague audience']);
        $this->group->contacts()->attach($this->contact);
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['name' => 'Client campaign', 'purpose' => 'Updates', 'type' => 'one_way', 'dialing_strategy' => 'direct', 'timezone' => 'Africa/Accra', 'audio_id' => $this->audio->id, 'source_group_id' => $this->group->id, 'window_start' => '00:00', 'window_end' => '23:59', 'concurrency' => 1, 'cps' => 1, 'priority' => 1, 'max_attempts' => 1, 'retry_delay_seconds' => 300], $overrides);
    }

    private function campaign(array $overrides = []): Campaign
    {
        return app(Workspace::class)->save($this->owner, null, $this->data($overrides));
    }

    public function test_colleague_can_save_shared_group_and_operate_scheduled_frozen_execution(): void
    {
        $this->actingAs($this->member)->get('/campaigns/create')->assertOk()->assertSee('Colleague audience')->assertDontSee('name="caller_id"', false)->assertDontSee('preview or approve audio');
        $this->post('/campaigns/create', $this->data(['caller_id' => 'malformed forged value', 'scheduled_at' => now()->addHour()->format('Y-m-d H:i:s')]))->assertRedirect()->assertSessionHasNoErrors();
        $campaign = Campaign::latest('id')->firstOrFail();
        $this->assertArrayNotHasKey('caller_id', $campaign->configuration);
        $this->group->contacts()->detach();
        $this->actingAs($this->owner)->get('/campaigns/'.$campaign->id)->assertOk()->assertSee('Edit campaign')->assertSee('Start schedule')->assertSee('Save immutable template version');
        $this->post('/campaigns/'.$campaign->id.'/control', ['action' => 'start', 'reason' => 'Arm shared schedule'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('scheduled', $campaign->fresh()->status);
        $run = CampaignRun::where('campaign_id', $campaign->id)->firstOrFail();
        $this->assertSame($this->owner->id, $run->started_by);
        $this->assertCount(1, $run->snapshot['broadcast']['targets']);
        $this->assertSame($this->contact->id, $run->snapshot['broadcast']['targets'][0]['id']);
        $this->assertSame('0241000100', $run->snapshot['broadcast']['caller_id']);
        foreach (['pause' => 'paused', 'resume' => 'scheduled', 'cancel' => 'cancelled'] as $action => $status) {
            $this->post('/campaigns/'.$campaign->id.'/control', ['action' => $action, 'reason' => 'Shared control'])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($status, $campaign->fresh()->status);
        }
        $this->assertSame(1, CampaignRun::where('campaign_id', $campaign->id)->count());
        $this->actingAs($this->foreign)->post('/campaigns/'.$campaign->id.'/control', ['action' => 'start', 'reason' => 'Foreign'])->assertNotFound();
    }

    public function test_templates_share_lineage_but_preserve_account_for_internal_staff(): void
    {
        $campaign = $this->campaign();
        $w = app(Workspace::class);
        $parent = $w->template($this->owner, $campaign, 'Family', null);
        $next = $w->template($this->member, $campaign, 'Shared next version', $parent);
        $this->assertSame($parent->family_uuid, $next->family_uuid);
        $this->assertSame(2, $next->version);
        $this->assertArrayNotHasKey('contact_ids', $next->blueprint['configuration']);
        $duplicate = $w->duplicate($this->admin, $campaign, 'Staff duplicate');
        $this->assertSame($this->client->id, $duplicate->client_id);
        $template = $w->template($this->admin, $campaign, 'Staff template', null);
        $this->assertSame($this->client->id, $template->client_id);
        $instance = $w->instantiate($this->admin, $template, 'Staff instance');
        $this->assertSame($this->client->id, $instance->client_id);
        $this->assertSame([], $instance->configuration['contact_ids']);
        $this->actingAs($this->member)->get('/campaigns/templates')->assertSee('Staff template');
    }

    public function test_url_prompts_and_shared_ivr_cloning_preserve_approval_and_ownership(): void
    {
        $graph = ['start' => 'prompt', 'nodes' => [['id' => 'prompt', 'type' => 'playback', 'audio_id' => $this->audio->id, 'next' => 'done'], ['id' => 'done', 'type' => 'end']]];
        $this->actingAs($this->member)->get('/interactive')->assertOk()->assertSee('Approved URL prompt')->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/interactive/flows', ['name' => 'Customer IVR', 'graph' => json_encode($graph)])->assertRedirect()->assertSessionHasNoErrors();
        $flow = IvrFlow::latest('id')->firstOrFail();
        $this->post('/interactive/flows/'.$flow->id.'/approve')->assertForbidden();
        $this->actingAs($this->owner)->get('/interactive')->assertSee('Clone into builder');
        $this->post('/interactive/flows', ['name' => 'Next version', 'graph' => json_encode($graph), 'source_id' => $flow->id])->assertRedirect()->assertSessionHasNoErrors();
        $clone = IvrFlow::latest('id')->firstOrFail();
        $this->assertSame('draft', $clone->status);
        $this->assertSame(2, $clone->version);
        $this->actingAs($this->admin);
        $staffClone = app(IvrDesigner::class)->save($this->admin, 'Staff clone', $graph, $flow);
        $this->assertSame($this->client->id, $staffClone->client_id);
        $this->assertSame(3, $staffClone->version);
        $this->actingAs($this->foreign)->post('/interactive/flows', ['name' => 'Stolen', 'graph' => json_encode($graph), 'source_id' => $flow->id])->assertNotFound();
    }

    public function test_pending_audio_and_fully_suppressed_audiences_cannot_launch(): void
    {
        $campaign = $this->campaign();
        $this->actingAs($this->member)->post('/contacts/'.$this->contact->id.'/suppress', ['reason' => 'Opted out'])->assertRedirect();
        $this->post('/campaigns/'.$campaign->id.'/control', ['action' => 'start', 'reason' => 'Attempt'])->assertSessionHasErrors('broadcast');
        $this->assertSame('ready', $campaign->fresh()->status);
        $this->assertDatabaseCount('campaign_runs', 0);
        $this->assertDatabaseCount('campaign_versions', 0);
        $this->audio->update(['status' => 'pending']);
        $campaign->update(['status' => 'ready']);
        $this->post('/campaigns/'.$campaign->id.'/control', ['action' => 'start', 'reason' => 'Attempt'])->assertSessionHasErrors('broadcast');
        $this->assertDatabaseCount('campaign_runs', 0);
    }

    public function test_ivr_must_be_internally_approved_and_deployed_before_launch(): void
    {
        $graph = ['start' => 'prompt', 'nodes' => [['id' => 'prompt', 'type' => 'playback', 'audio_id' => $this->audio->id, 'next' => 'done'], ['id' => 'done', 'type' => 'end']]];
        $flow = app(IvrDesigner::class)->save($this->owner, 'Needs review', $graph);
        $campaign = $this->campaign(['type' => 'interactive', 'ivr_flow_id' => $flow->id]);
        $this->actingAs($this->member)->post('/campaigns/'.$campaign->id.'/control', ['action' => 'start', 'reason' => 'Attempt'])->assertSessionHasErrors('broadcast');
        $this->post('/interactive/flows/'.$flow->id.'/deploy', ['checksum' => str_repeat('0', 64), 'evidence' => 'Forged'])->assertForbidden();
        $this->actingAs($this->admin);
        app(IvrDesigner::class)->approve($this->admin, $flow);
        $this->actingAs($this->member)->post('/campaigns/'.$campaign->id.'/control', ['action' => 'start', 'reason' => 'Not deployed'])->assertSessionHasErrors('broadcast');
        $this->assertDatabaseCount('campaign_runs', 0);
        $this->assertDatabaseCount('campaign_versions', 0);
    }

    public function test_suspended_accounts_do_not_activate_due_schedules_and_changed_media_blocks_resume(): void
    {
        $campaign = $this->campaign(['scheduled_at' => now()->addHour()->format('Y-m-d H:i:s')]);
        $this->actingAs($this->member)->post('/campaigns/'.$campaign->id.'/control', ['action' => 'start', 'reason' => 'Arm'])->assertRedirect();
        $this->actingAs($this->admin);
        app(Accounts::class)->status($this->admin, $this->client, 'suspended', 'Review');
        $this->travel(2)->hours();
        app(CampaignPolicy::class)->activateDue();
        $this->assertSame('scheduled', $campaign->fresh()->status);
        app(Accounts::class)->status($this->admin, $this->client, 'active', 'Review finished');
        $this->actingAs($this->member)->post('/campaigns/'.$campaign->id.'/control', ['action' => 'pause', 'reason' => 'Pause'])->assertRedirect();
        Storage::disk('local')->delete($this->audio->storage_key);
        $this->post('/campaigns/'.$campaign->id.'/control', ['action' => 'resume', 'reason' => 'Unsafe resume'])->assertSessionHasErrors('broadcast');
        $this->assertSame('paused', $campaign->fresh()->status);
        $this->assertSame(1, CampaignRun::where('campaign_id', $campaign->id)->count());
    }
}
