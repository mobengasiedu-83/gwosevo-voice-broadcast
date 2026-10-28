<?php

namespace Tests\Feature;

use App\Models\AudioFile;
use App\Models\Campaign;
use App\Models\CampaignRun;
use App\Models\CampaignVersion;
use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\IvrFlow;
use App\Models\TrunkProfile;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletReservation;
use App\Services\Billing\Tariffs;
use App\Services\Billing\Wallet;
use App\Services\Broadcast\CampaignPolicy;
use App\Services\Broadcast\Engine;
use App\Services\Broadcast\IvrDesigner;
use App\Services\Campaigns\Workspace;
use App\Services\Clients\Accounts;
use App\Services\Clients\Tenancy;
use App\Services\Foundation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $tariffs = app(Tariffs::class);
        $tariff = $tariffs->create($this->admin, ['code' => 'prepaid-fixture', 'version' => 1, 'currency' => 'GHS', 'minor_unit_scale' => 2, 'rate_minor_per_minute' => 30, 'playback_policy' => 'charge_answered'], 'Test prepaid rate');
        $tariffs->assign($this->admin, $this->client, $tariff, 'Test prepaid setup');
        $wallets = app(Wallet::class);
        $wallet = $wallets->open($this->admin, $this->client, 'GHS', 2, 'Test prepaid wallet');
        $wallets->post($this->admin, $wallet, 'funding', '100.00', 'Test prepaid funding', 'TEST-PREPAID', 'client-campaign-prepaid');
        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->foreign = User::factory()->create();
        $accounts->member($this->admin, $this->client, $this->owner, 'owner');
        $accounts->member($this->admin, $this->client, $this->member);
        $accounts->member($this->admin, $other, $this->foreign, 'owner');
        TrunkProfile::create(['name' => 'client-module-11', 'caller_id' => '0241000100', 'max_channels' => 10, 'reserved_channels' => 2, 'safe_channels' => 5, 'max_cps' => 2, 'number_format' => 'national', 'active' => true, 'verified_at' => now(), 'verified_by' => $this->admin->id, 'evidence' => 'Test capacity']);
        Storage::disk('local')->put('client-11.wav', 'fixture');
        $this->audio = AudioFile::create(['client_id' => $this->client->id, 'uploaded_by' => $this->owner->id, 'uuid' => (string) Str::uuid(), 'name' => 'Approved URL prompt', 'status' => 'approved', 'storage_key' => 'client-11.wav', 'checksum' => hash('sha256', 'fixture'), 'duration' => 30, 'approved_by' => $this->admin->id, 'approved_at' => now()]);
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

    public function test_launch_freezes_audio_price_contact_count_and_tariff_for_all_recipients(): void
    {
        $this->audio->update(['duration' => 75]);
        $tariffs = app(Tariffs::class);
        $plan = $tariffs->create($this->admin, ['code' => 'snapshot-20', 'version' => 1, 'currency' => 'GHS', 'minor_unit_scale' => 2, 'rate_minor_per_minute' => 20, 'playback_policy' => 'charge_answered'], 'Snapshot fixture');
        $tariffs->assign($this->admin, $this->client, $plan, 'Snapshot fixture');
        $contact = app(Tenancy::class)->run($this->client->id, fn () => app(Foundation::class)->createContact($this->owner, ['name' => 'Second', 'phone' => '0241234599', 'source' => 'Form']));
        $this->group->contacts()->attach($contact);
        $campaign = $this->campaign();
        $engine = app(Engine::class);
        $engine->control($this->owner, $campaign, 'start');
        $run = CampaignRun::where('campaign_id', $campaign->id)->firstOrFail();
        $pricing = $run->snapshot['broadcast']['billing'];
        $this->assertSame($this->audio->id, $pricing['audio_id']);
        $this->assertEquals(75, $pricing['audio_duration_seconds']);
        $this->assertSame(2, $pricing['billable_minutes']);
        $this->assertSame(20, $pricing['rate_minor_per_minute']);
        $this->assertSame(40, $pricing['rate_per_contact_minor']);
        $this->assertSame(2, $pricing['contact_count']);
        $this->assertSame(80, $pricing['estimated_campaign_amount_minor']);
        $this->assertSame('GHS', $pricing['currency']);
        $this->assertSame($plan->id, $pricing['rate_plan_id']);
        $new = $tariffs->create($this->admin, ['code' => 'snapshot-90', 'version' => 1, 'currency' => 'GHS', 'minor_unit_scale' => 2, 'rate_minor_per_minute' => 90, 'playback_policy' => 'charge_answered'], 'Changed future rate');
        $tariffs->assign($this->admin, $this->client, $new, 'Future campaigns only');
        $this->assertSame($pricing, $run->fresh()->snapshot['broadcast']['billing']);
        $this->actingAs($this->owner)->get(route('campaigns.show', $campaign))->assertOk()->assertSee('Saved campaign pricing')->assertSee('GHS 0.40');
        config()->set('voice_broadcast.enabled', true);
        config()->set('voice_broadcast.global_channels', 3);
        config()->set('voice_broadcast.global_cps', 1);
        $gateway = (string) Str::uuid();
        DB::table('broadcast_gateway')->where('id', 1)->update(['owner' => $gateway, 'healthy' => true, 'snapshot_at' => now(), 'heartbeat_at' => now(), 'channels' => '[]', 'emergency_stop' => false]);
        $attempt = DB::transaction(fn () => $engine->reserve($gateway));
        $this->assertNotNull($attempt);
        $this->assertSame(20, $attempt->tariff_snapshot['rate_minor_per_minute']);
        $this->assertSame(75000, $attempt->recordingDurationMs());
        $amounts = WalletReservation::where('campaign_version_id', $run->campaign_version_id)->pluck('amount_minor')->all();
        $this->assertSame([40, 40], $amounts);
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

    public function test_launch_holds_only_the_recorded_minutes_for_the_campaign_audience(): void
    {
        $second = app(Tenancy::class)->run($this->client->id, fn () => app(Foundation::class)->createContact($this->owner, ['name' => 'Second customer', 'phone' => '0241234568', 'source' => 'Form']));
        $this->group->contacts()->attach($second);
        $campaign = $this->campaign();
        $wallet = WalletAccount::where('client_id', $this->client->id)->firstOrFail();

        // A 30-second recording at 30 minor units per minute rounds up to one
        // billed minute per contact, so two contacts reserve 60 minor units.
        $this->actingAs($this->owner)->post('/campaigns/'.$campaign->id.'/control', ['action' => 'start', 'reason' => 'Reserve the audience'])->assertRedirect()->assertSessionHasNoErrors();

        $wallet->refresh();
        $version = CampaignVersion::where('campaign_id', $campaign->id)->latest('version')->firstOrFail();
        $holds = WalletReservation::where('campaign_version_id', $version->id)->whereNull('call_attempt_id')->get();
        $this->assertCount(2, $holds);
        $this->assertSame(60, $holds->sum('amount_minor'));
        $this->assertSame(60, $wallet->reserved_minor);
        $this->assertSame(10000 - 60, $wallet->available_minor);
        $this->assertTrue(app(Wallet::class)->verify($wallet)['ok']);

        $this->post('/campaigns/'.$campaign->id.'/control', ['action' => 'cancel', 'reason' => 'Operator cancelled'])->assertRedirect()->assertSessionHasNoErrors();

        $wallet->refresh();
        $this->assertSame(0, $wallet->reserved_minor);
        $this->assertSame(10000, $wallet->available_minor);
        $this->assertSame(0, WalletReservation::where('campaign_version_id', $version->id)->where('state', 'held')->count());
        $this->assertTrue(app(Wallet::class)->verify($wallet)['ok']);
    }

    public function test_launch_refuses_when_credit_cannot_hold_the_audience_recording_cost(): void
    {
        $second = app(Tenancy::class)->run($this->client->id, fn () => app(Foundation::class)->createContact($this->owner, ['name' => 'Third customer', 'phone' => '0241234569', 'source' => 'Form']));
        $this->group->contacts()->attach($second);
        $campaign = $this->campaign();
        $wallet = WalletAccount::where('client_id', $this->client->id)->firstOrFail();
        app(Wallet::class)->post($this->admin, $wallet, 'adjustment', '-99.60', 'Reduce test credit', 'FIN-TEST');

        // 0.40 GHS remain; two 30-second contacts need 0.60 GHS held in full.
        $this->actingAs($this->owner)->post('/campaigns/'.$campaign->id.'/control', ['action' => 'start', 'reason' => 'Attempt'])->assertSessionHasErrors('broadcast');

        $this->assertSame('ready', $campaign->fresh()->status);
        $this->assertDatabaseCount('campaign_runs', 0);
        $this->assertSame(40, $wallet->refresh()->available_minor);
        $this->assertSame(0, $wallet->reserved_minor);
        $this->assertSame(0, WalletReservation::where('wallet_id', $wallet->id)->count());
    }

    public function test_launch_with_an_unmeasured_recording_is_refused_without_holding_credit(): void
    {
        $this->audio->update(['duration' => null]);
        $campaign = $this->campaign();

        $this->actingAs($this->owner)->post('/campaigns/'.$campaign->id.'/control', ['action' => 'start', 'reason' => 'Attempt'])->assertSessionHasErrors('broadcast');

        $this->assertSame('ready', $campaign->fresh()->status);
        $this->assertDatabaseCount('campaign_runs', 0);
        $this->assertDatabaseCount('campaign_versions', 0);
        $wallet = WalletAccount::where('client_id', $this->client->id)->firstOrFail();
        $this->assertSame(0, $wallet->reserved_minor);
        $this->assertSame(10000, $wallet->available_minor);
    }

    public function test_real_admission_captures_the_assigned_tariff_before_any_originate(): void
    {
        $tariffs = app(Tariffs::class);
        $tariff = $tariffs->create($this->admin, ['code' => 'admission-test', 'version' => 1, 'currency' => 'GHS', 'minor_unit_scale' => 2, 'rate_minor_per_minute' => 30, 'playback_policy' => 'hold_on_failure'], 'Test-only price');
        $tariffs->assign($this->admin, $this->client, $tariff, 'Test assignment');
        $campaign = $this->campaign();
        $engine = app(Engine::class);
        $engine->control($this->owner, $campaign, 'start');
        config()->set('voice_broadcast.enabled', true);
        config()->set('voice_broadcast.global_channels', 3);
        config()->set('voice_broadcast.global_cps', 1);
        $gateway = (string) Str::uuid();
        DB::table('broadcast_gateway')->where('id', 1)->update(['owner' => $gateway, 'healthy' => true, 'snapshot_at' => now(), 'heartbeat_at' => now(), 'channels' => '[]', 'emergency_stop' => false]);
        $attempt = DB::transaction(fn () => $engine->reserve($gateway));
        $this->assertNotNull($attempt);
        $this->assertSame($tariff->id, $attempt->tariff_snapshot['id']);
        $this->assertSame('ceil_minute_v1', $attempt->tariff_snapshot['policy_version']);
        $this->assertNull($attempt->sent_at);

        // Dispatch consumes the launch hold for this recipient instead of
        // reserving the recording cost a second time.
        $wallet = WalletAccount::where('client_id', $this->client->id)->firstOrFail();
        $this->assertSame(30, $wallet->reserved_minor);
        $this->assertSame(10000 - 30, $wallet->available_minor);
        $this->assertSame(0, WalletReservation::where('wallet_id', $wallet->id)->whereNull('call_attempt_id')->where('state', 'held')->count());
        $this->assertSame($attempt->id, WalletReservation::where('wallet_id', $wallet->id)->where('state', 'held')->firstOrFail()->call_attempt_id);
    }
}
