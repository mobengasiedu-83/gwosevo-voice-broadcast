<?php

namespace Tests\Feature;

use App\Models\AudioFile;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ClientAccount;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\TrunkProfile;
use App\Models\User;
use App\Services\Broadcast\AmiClient;
use App\Services\Broadcast\Engine;
use App\Services\Broadcast\Gateway;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientCampaignTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $admin;

    private ContactGroup $group;

    private Contact $contact;

    private AudioFile $audio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        config()->set('voice_broadcast.client_execution_enabled', false);
        config()->set('broadcasting_discovery.trunk', 'client-test');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        foreach (['alice', 'bob'] as $name) {
            $account = ClientAccount::create(['name' => $name, 'created_by' => $this->admin->id]);
            $this->$name = User::factory()->create();
            $this->$name->forceFill(['client_account_id' => $account->id])->save();
            $this->$name->assignRole('Client');
        }
        $this->group = ContactGroup::create(['name' => 'My audience', 'owner_id' => $this->alice->id]);
        $this->contact = Contact::create(['name' => 'Test person', 'phone_e164' => '+233241234567', 'created_by' => $this->alice->id]);
        $this->group->contacts()->attach($this->contact);
        Storage::disk('local')->put('client.wav', 'audio fixture');
        $this->audio = AudioFile::create(['uuid' => (string) Str::uuid(), 'name' => 'Approved message', 'uploaded_by' => $this->alice->id, 'status' => 'approved', 'approved_by' => $this->admin->id, 'approved_at' => now(), 'deployed_at' => now(), 'storage_key' => 'client.wav', 'checksum' => hash('sha256', 'audio fixture')]);
        TrunkProfile::create(['name' => 'client-test', 'caller_id' => '0241234567', 'max_channels' => 10, 'reserved_channels' => 2, 'safe_channels' => 2, 'max_cps' => 1, 'number_format' => 'national', 'active' => true, 'verified_at' => now(), 'verified_by' => $this->admin->id, 'evidence' => 'test fixture']);
    }

    private function data(array $overrides = []): array
    {
        return array_replace(['name' => 'Client announcement', 'purpose' => 'Updates', 'source_group_id' => $this->group->id, 'audio_id' => $this->audio->id, 'timezone' => 'Africa/Accra', 'scheduled_at' => '', 'window_start' => '08:00', 'window_end' => '18:00', 'max_attempts' => 2, 'retry_delay_seconds' => 300], $overrides);
    }

    private function create(array $overrides = []): Campaign
    {
        $this->actingAs($this->alice)->post(route('client.campaigns.store'), $this->data($overrides))->assertRedirect();

        return Campaign::latest('id')->firstOrFail();
    }

    public function test_wizard_saves_only_account_assets_and_server_controlled_settings(): void
    {
        $this->actingAs($this->alice)->get(route('client.campaigns.create'))->assertOk()->assertSee('Approved message')->assertDontSee('name="caller_id"', false);
        $campaign = $this->create(['caller_id' => '999999', 'trunk_id' => 500, 'client_account_id' => $this->bob->client_account_id, 'owner_id' => $this->bob->id, 'type' => 'interactive', 'status' => 'running', 'concurrency' => 100, 'contact_ids' => '9999']);
        $this->assertSame($this->alice->client_account_id, $campaign->client_account_id);
        $this->assertSame($this->alice->id, $campaign->owner_id);
        $this->assertSame('one_way', $campaign->type);
        $this->assertSame('ready', $campaign->status);
        $this->assertArrayNotHasKey('caller_id', $campaign->configuration);
        $this->assertSame(1, $campaign->configuration['concurrency']);
        $this->assertSame([$this->contact->id], $campaign->configuration['contact_ids']);
        $this->get(route('client.campaigns.show', $campaign))->assertOk()->assertSee('Campaign review')->assertSee('Client execution is disabled')->assertDontSee($this->audio->checksum)->assertDontSee('client-test');
        $this->assertFalse($this->alice->can('campaigns.manage'));
        $this->post('/campaigns/create', $this->data())->assertForbidden();
    }

    public function test_foreign_assets_and_campaigns_are_unavailable_and_unapproved_audio_is_rejected(): void
    {
        $campaign = $this->create();
        $this->actingAs($this->bob)->get(route('client.campaigns.show', $campaign))->assertNotFound();
        $this->get(route('client.campaigns.edit', $campaign))->assertNotFound();
        $this->post(route('client.campaigns.save', $campaign), $this->data())->assertNotFound();
        $this->post(route('client.campaigns.control', $campaign), ['action' => 'cancel', 'reason' => 'Unauthorized'])->assertNotFound();
        $this->post(route('client.campaigns.test', $campaign), ['contact_id' => $this->contact->id])->assertNotFound();
        $this->post(route('client.campaigns.store'), $this->data())->assertNotFound();
        $this->actingAs($this->alice);
        $this->audio->update(['status' => 'pending']);
        $this->post(route('client.campaigns.store'), $this->data())->assertNotFound();
        $this->audio->update(['status' => 'approved']);
        $this->group->update(['active' => false]);
        $this->post(route('client.campaigns.store'), $this->data())->assertNotFound();
    }

    public function test_schedule_launch_snapshot_controls_and_edit_lock(): void
    {
        $time = now('Africa/Accra')->addDay()->format('Y-m-d\TH:i');
        $campaign = $this->create(['scheduled_at' => $time]);
        $url = route('client.campaigns.control', $campaign);
        $this->post($url, ['action' => 'start', 'reason' => 'Acceptance'])->assertForbidden();
        config()->set('voice_broadcast.client_execution_enabled', true);
        $this->post($url, ['action' => 'start', 'reason' => 'Acceptance'])->assertRedirect();
        $this->assertSame('scheduled', $campaign->fresh()->status);
        $this->assertSame(1, $campaign->versions()->count());
        $this->assertSame(1, CampaignRecipient::where('campaign_id', $campaign->id)->count());
        $this->post(route('client.campaigns.save', $campaign), $this->data())->assertStatus(422);
        $this->post($url, ['action' => 'pause', 'reason' => 'Hold'])->assertRedirect();
        $this->assertSame('paused', $campaign->fresh()->status);
        $this->post($url, ['action' => 'resume', 'reason' => 'Continue'])->assertRedirect();
        $this->assertSame('scheduled', $campaign->fresh()->status);
        $this->post($url, ['action' => 'cancel', 'reason' => 'End acceptance'])->assertRedirect();
        $this->assertSame('cancelled', $campaign->fresh()->status);
        $this->post($url, ['action' => 'start', 'reason' => 'Replay'])->assertSessionHasErrors();
        $this->assertSame(1, $campaign->versions()->count());
    }

    public function test_launch_rechecks_audio_integrity_dnd_and_failed_runs_are_rolled_back(): void
    {
        $campaign = $this->create();
        config()->set('voice_broadcast.client_execution_enabled', true);
        Storage::disk('local')->put('client.wav', 'tampered');
        $this->post(route('client.campaigns.control', $campaign), ['action' => 'start', 'reason' => 'Test'])->assertSessionHasErrors();
        $this->assertSame(0, $campaign->versions()->count());
        Storage::disk('local')->put('client.wav', 'audio fixture');
        DB::table('suppression_entries')->insert(['phone_e164' => $this->contact->phone_e164, 'reason' => 'DND', 'effective_at' => now()->subMinute(), 'created_by' => $this->admin->id]);
        $this->post(route('client.campaigns.control', $campaign), ['action' => 'start', 'reason' => 'Test'])->assertSessionHasErrors();
        $this->assertSame('ready', $campaign->fresh()->status);
    }

    public function test_test_run_requires_allowlisted_own_recipient_and_preserves_main_campaign(): void
    {
        $campaign = $this->create();
        config()->set('voice_broadcast.client_execution_enabled', true);
        $url = route('client.campaigns.test', $campaign);
        $this->post($url, ['contact_id' => $this->contact->id])->assertStatus(422);
        config()->set('voice_broadcast.client_test_numbers', [$this->contact->phone_e164]);
        $this->post($url, ['contact_id' => $this->contact->id])->assertRedirect()->assertSessionHasNoErrors();
        $test = Campaign::where('source_campaign_id', $campaign->id)->firstOrFail();
        $this->assertSame('ready', $campaign->fresh()->status);
        $this->assertSame('running', $test->status);
        $this->assertSame(1, $test->configuration['max_attempts']);
        $this->assertSame([$this->contact->id], $test->configuration['contact_ids']);
        $this->post($url, ['contact_id' => $this->contact->id])->assertSessionHasErrors('campaign');
        $this->assertSame(1, Campaign::where('source_campaign_id', $campaign->id)->count());
        $this->get(route('client.campaigns.show', $campaign))->assertOk()->assertSee('Test: Client announcement');
        $this->assertNull(app(Engine::class)->reserve('no-gateway'));
    }

    public function test_invalid_dates_empty_groups_colleague_controls_and_suspension(): void
    {
        $this->actingAs($this->alice)->post(route('client.campaigns.store'), $this->data(['scheduled_at' => '2020-01-01T10:00']))->assertSessionHasErrors('scheduled_at');
        $campaign = $this->create();
        $peer = User::factory()->create();
        $peer->forceFill(['client_account_id' => $this->alice->client_account_id])->save();
        $peer->assignRole('Client');
        $this->actingAs($peer)->get(route('client.campaigns.show', $campaign))->assertOk();
        $this->post(route('client.campaigns.control', $campaign), ['action' => 'cancel', 'reason' => 'Peer'])->assertForbidden();
        $this->actingAs($this->alice);
        $this->group->contacts()->detach();
        $this->post(route('client.campaigns.store'), $this->data())->assertSessionHasErrors('source_group_id');
        ClientAccount::find($this->alice->client_account_id)->update(['status' => 'suspended']);
        $this->get(route('client.campaigns.show', $campaign))->assertNotFound();
    }

    public function test_disabled_execution_blocks_an_already_authorized_attempt_without_ami_write(): void
    {
        $campaign = $this->create();
        config()->set('voice_broadcast.client_execution_enabled', true);
        $this->post(route('client.campaigns.control', $campaign), ['action' => 'start', 'reason' => 'Acceptance'])->assertRedirect()->assertSessionHasNoErrors();
        $recipient = CampaignRecipient::where('campaign_id', $campaign->id)->firstOrFail();
        $attempt = CallAttempt::create(['uuid' => (string) Str::uuid(), 'campaign_recipient_id' => $recipient->id, 'campaign_version_id' => $campaign->fresh()->active_version_id, 'state' => 'authorizing', 'token' => str_repeat('a', 64)]);
        $gateway = app(Gateway::class);
        DB::table('broadcast_gateway')->where('id', 1)->update(['owner' => $gateway->owner]);
        config()->set('voice_broadcast.client_execution_enabled', false);
        $ami = new class extends AmiClient
        {
            public array $sent = [];

            public function send(array $headers): void
            {
                $this->sent[] = $headers;
            }
        };
        $gateway->authorized($ami, $attempt->uuid, true);
        $this->assertSame([], $ami->sent);
        $this->assertSame('cancelled_before_send', $attempt->fresh()->outcome);
        $this->assertNull($attempt->fresh()->sent_at);
    }

    public function test_report_exposes_outcomes_without_tokens_channels_or_caller_identity(): void
    {
        $campaign = $this->create();
        config()->set('voice_broadcast.client_execution_enabled', true);
        $this->post(route('client.campaigns.control', $campaign), ['action' => 'start', 'reason' => 'Acceptance'])->assertRedirect();
        $recipient = CampaignRecipient::where('campaign_id', $campaign->id)->firstOrFail();
        CallAttempt::create(['campaign_recipient_id' => $recipient->id, 'attempt_no' => 1, 'state' => 'terminal', 'outcome' => 'playback_failed', 'answered_at' => now(), 'channel' => 'SECRET-CHANNEL', 'token' => 'SECRET-TOKEN']);
        $this->get(route('client.campaigns.show', $campaign))->assertOk()->assertSee('playback_failed')->assertSee('Answered: 1')->assertSee('Playback completed: 0')->assertDontSee('SECRET-CHANNEL')->assertDontSee('SECRET-TOKEN')->assertDontSee($this->audio->checksum)->assertDontSee('client-test');
    }
}
