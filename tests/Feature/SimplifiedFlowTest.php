<?php

namespace Tests\Feature;

use App\Models\AudioFile;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRun;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ContactImport;
use App\Models\TrunkProfile;
use App\Models\User;
use App\Services\Broadcast\AudioLibrary;
use App\Services\Broadcast\CampaignPolicy;
use App\Services\Broadcast\Engine;
use App\Services\Broadcast\Readiness;
use App\Services\Campaigns\Workspace;
use App\Services\Foundation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SimplifiedFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $admin;

    private AudioFile $audio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        $this->manager = $this->user('Campaign Manager');
        $this->admin = $this->user('Super Administrator');
        config()->set('broadcasting_discovery.trunk', 'configured');
        $trunk = TrunkProfile::create(['name' => 'configured', 'caller_id' => '0596921082', 'allowed_caller_ids' => ['0244215189'], 'active' => true, 'verified_by' => $this->admin->id, 'verified_at' => now(), 'evidence' => 'Carrier', 'number_format' => 'national', 'max_channels' => 10, 'reserved_channels' => 2, 'safe_channels' => 3, 'max_cps' => 1]);
        $pcm = str_repeat("\0", 1600);
        $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
        Storage::disk('local')->put('message.wav', $wav);
        $this->audio = AudioFile::create(['uuid' => (string) Str::uuid(), 'name' => 'Pending message', 'status' => 'pending', 'storage_key' => 'message.wav', 'checksum' => hash('sha256', $wav), 'duration' => 0.1, 'uploaded_by' => $this->admin->id]);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    private function campaign(array $extra = []): Campaign
    {
        $contact = app(Foundation::class)->createContact($this->manager, ['name' => 'Recipient', 'phone' => '024'.str_pad((string) (Contact::count() + 1), 7, '0', STR_PAD_LEFT), 'source' => 'Form']);
        $contact->consents()->create(['purpose' => 'Updates', 'legal_basis' => 'Consent', 'source' => 'Form', 'evidence_ref' => 'Form1', 'granted_at' => now()->subDay()]);

        return app(Workspace::class)->save($this->manager, null, array_replace(['name' => 'Simple campaign', 'purpose' => 'Updates', 'type' => 'one_way', 'dialing_strategy' => 'direct', 'audio_id' => $this->audio->id, 'caller_id' => '0244215189', 'timezone' => 'Africa/Accra', 'scheduled_at' => null, 'contact_ids' => (string) $contact->id, 'concurrency' => 1, 'cps' => 1, 'window_start' => '00:00', 'window_end' => '23:59', 'max_attempts' => 1, 'retry_delay_seconds' => 300, 'priority' => 1], $extra));
    }

    private function approve(): void
    {
        app(AudioLibrary::class)->approve($this->admin, $this->audio);
        app(AudioLibrary::class)->deployed($this->admin, $this->audio->fresh(), $this->audio->checksum, 'Installed file checksum verified');
    }

    public function test_sidebar_destinations_and_active_sections_are_independent(): void
    {
        $this->actingAs($this->admin);
        foreach ([
            '/audio' => 'Audio',
            '/interactive' => 'Voice & IVR',
            '/telephony' => 'Telephony',
            '/broadcast' => 'Dialing Operations',
            '/campaigns' => 'Campaigns',
            '/campaigns?view=scheduled' => 'Scheduling',
            '/campaigns/runs' => 'Reports & Analytics',
        ] as $url => $section) {
            $response = $this->get($url)->assertOk();
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);
            $active = $xpath->query('//nav[@aria-label="Main navigation"]//*[contains(concat(" ", normalize-space(@class), " "), " nav-item ") and contains(concat(" ", normalize-space(@class), " "), " active ")]');
            $this->assertCount(1, $active, $url);
            $this->assertCount(1, $xpath->query('//nav[@aria-label="Main navigation"]//details[@open]'), $url);
            $this->assertCount(1, $xpath->query('//nav[@aria-label="Main navigation"]//details[@open]//a[@aria-current="page"]'), $url);
            $this->assertStringContainsString($section, $active->item(0)->textContent);
            $audio = $xpath->query('//details[@data-nav-group="voice"]//a')->item(0);
            $this->assertSame(route('audio.index'), $audio->getAttribute('href'));
            $this->assertSame('Audio Library', trim($audio->textContent));
            $schedule = $xpath->query('//nav[@aria-label="Main navigation"]//a[contains(., "Scheduling")]')->item(0);
            $this->assertSame(route('campaigns.index', ['view' => 'scheduled']), $schedule->getAttribute('href'));
        }
    }

    public function test_telephony_and_ivr_show_separate_tools_and_enforce_access(): void
    {
        $this->actingAs($this->admin)->get('/telephony')->assertOk()
            ->assertSee('Outbound caller IDs')->assertSee('Allowlisted agent destinations')
            ->assertDontSee('id="flow-builder"', false)->assertDontSee('<h2>IVR versions</h2>', false);
        $this->get('/interactive')->assertOk()->assertSee('id="flow-builder"', false)->assertSee('Visual IVR builder')->assertSee('Component properties')->assertSee('ivr-connections')->assertSee('Save draft version')
            ->assertSee('<h2>IVR versions</h2>', false)->assertDontSee('Outbound caller IDs');
        $this->actingAs($this->manager)->get('/telephony')->assertForbidden();
        $this->get('/interactive')->assertOk();
        $approver = $this->user('Approver');
        $this->actingAs($approver)->get('/interactive')->assertOk()
            ->assertSee(route('interactive.index'))->assertDontSee('id="flow-builder"', false);
        $this->get('/telephony')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/interactive')->assertForbidden();
        $this->get('/telephony')->assertForbidden();
    }

    public function test_approved_audio_without_pbx_installation_explains_start_blocker(): void
    {
        $campaign = $this->campaign();
        app(AudioLibrary::class)->approve($this->admin, $this->audio);
        $this->actingAs($this->admin)->get(route('campaigns.show', $campaign))->assertOk()
            ->assertSee('Audio is approved, but PBX installation has not been recorded.')
            ->assertSee('Start is unavailable')->assertSee('Download and record PBX installation');
        $this->get('/audio')->assertOk()->assertSee('AMI access alone cannot upload the file.');
        $this->assertNull($this->audio->fresh()->deployed_at);
        $this->assertDatabaseCount('campaign_runs', 0);
    }

    public function test_contacts_without_consent_are_eligible_when_not_suppressed(): void
    {
        $campaign = $this->campaign();
        $this->approve();
        DB::table('contact_consents')->delete();
        $this->actingAs($this->admin)->get(route('campaigns.show', $campaign))->assertOk()
            ->assertDontSee('No eligible contacts.')->assertDontSee('Review consent evidence')
            ->assertDontSee('Start is unavailable');
        $this->assertSame(1, app(Workspace::class)->eligibility($campaign->fresh())['eligible']);
        $contact = Contact::firstOrFail();
        $this->assertNull(app(Readiness::class)->exclusion($contact->id, $contact->phone_e164, $campaign->purpose));
        $this->assertDatabaseCount('campaign_runs', 0);
    }

    public function test_url_media_is_call_scoped_checksum_verified_and_expires(): void
    {
        config()->set('voice_broadcast.media_mode', 'url');
        config()->set('voice_broadcast.media_base_url', 'https://voice.example.test/dialer');
        $campaign = $this->campaign();
        app(AudioLibrary::class)->approve($this->admin, $this->audio);
        app(Engine::class)->control($this->manager, $campaign->fresh(), 'start', 'URL playback test');
        $version = $campaign->versions()->firstOrFail();
        $call = CallAttempt::create([
            'uuid' => (string) Str::uuid(), 'token' => str_repeat('a', 64),
            'campaign_version_id' => $version->id, 'state' => 'sending', 'sent_at' => now(),
            'action_id' => (string) Str::uuid(),
            'campaign_recipient_id' => CampaignRecipient::where('campaign_id', $campaign->id)->firstOrFail()->id,
        ]);
        $url = '/pbx-media/'.$call->uuid.'/'.$call->token.'/'.$this->audio->uuid.'/'.$this->audio->checksum.'.wav';
        $this->assertNull($this->audio->fresh()->deployed_at);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'audio/wav');
        $this->get(str_replace($call->token, str_repeat('b', 64), $url))->assertNotFound();
        $this->get(str_replace($this->audio->checksum, str_repeat('0', 64), $url))->assertNotFound();
        $this->get(str_replace($this->audio->uuid, (string) Str::uuid(), $url))->assertNotFound();
        Storage::disk('local')->put('message.wav', 'tampered');
        $this->get($url)->assertNotFound();
        $call->update(['ended_at' => now()]);
        $this->get($url)->assertNotFound();
        $call->update(['ended_at' => null, 'sent_at' => now()->subMinutes(16)]);
        $this->get($url)->assertNotFound();
        $call->update(['sent_at' => null]);
        $this->get($url)->assertNotFound();
    }

    public function test_url_media_requires_a_pbx_reachable_base_url(): void
    {
        config()->set('voice_broadcast.media_mode', 'url');
        config()->set('voice_broadcast.media_base_url', 'https://localhost');
        $campaign = $this->campaign();
        app(AudioLibrary::class)->approve($this->admin, $this->audio);
        $this->actingAs($this->admin)->get(route('campaigns.show', $campaign))->assertOk()
            ->assertSee('VOICE_BROADCAST_MEDIA_BASE_URL')->assertSee('Start is unavailable');
        $this->assertDatabaseCount('campaign_runs', 0);
    }

    public function test_superadmin_can_approve_own_audio_and_see_library_preview_actions(): void
    {
        $c = $this->campaign();
        $this->actingAs($this->admin)->get('/audio')->assertOk()->assertSee('Approve audio')->assertSee('Reject audio')->assertSee('audio/1/preview');
        $this->get(route('audio.preview', $this->audio))->assertOk()->assertHeader('Content-Type', 'audio/wav');
        $this->get(route('audio.preview', $this->audio), ['Range' => 'bytes=0-43'])->assertStatus(206)->assertHeader('Content-Range');
        $this->post(route('broadcast.audio.approve', $this->audio))->assertRedirect();
        $this->assertSame('ready', $c->fresh()->status);
        $this->assertSame('approved', $this->audio->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'campaign.audio_ready']);
        $this->assertNull($this->audio->fresh()->deployed_at);
        $this->assertDatabaseCount('campaign_runs', 0);
    }

    public function test_audio_reject_requires_reason_and_resubmission_preserves_campaign_draft(): void
    {
        $c = $this->campaign();
        $this->actingAs($this->admin)->post(route('broadcast.audio.reject', $this->audio), [])->assertSessionHasErrors('reason');
        $this->post(route('broadcast.audio.reject', $this->audio), ['reason' => 'Needs revision'])->assertRedirect();
        $this->assertSame('rejected', $this->audio->fresh()->status);
        $this->assertSame('draft', $c->fresh()->status);
        $this->get('/audio?status=rejected')->assertOk()->assertSee('Needs revision');
        $this->post(route('broadcast.audio.resubmit', $this->audio))->assertRedirect();
        $this->assertSame('pending', $this->audio->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'audio.rejected']);
    }

    public function test_non_admin_cannot_self_approve_even_with_approval_permission(): void
    {
        $this->manager->givePermissionTo('audio.approve');
        $this->audio->update(['uploaded_by' => $this->manager->id]);
        $this->actingAs($this->manager)->post(route('broadcast.audio.approve', $this->audio))->assertForbidden();
        $this->post(route('broadcast.audio.reject', $this->audio), ['reason' => 'Self'])->assertForbidden();
        $this->actingAs($this->user('Analyst'))->get('/audio')->assertForbidden();
        $this->get(route('audio.preview', $this->audio))->assertForbidden();
    }

    public function test_ready_owner_start_pause_resume_cancel_and_snapshot_actor(): void
    {
        $c = $this->campaign();
        $this->approve();
        $this->actingAs($this->manager)->post(route('campaigns.control', $c), ['action' => 'start', 'reason' => 'Start'])->assertRedirect();
        $this->assertSame('running', $c->fresh()->status);
        $this->assertSame($this->manager->id, $c->versions()->first()->approved_by);
        $this->assertSame('0244215189', CampaignRun::first()->snapshot['broadcast']['caller_id']);
        foreach (['pause' => 'paused', 'resume' => 'running', 'cancel' => 'cancelled'] as $action => $state) {
            $this->post(route('campaigns.control', $c), ['action' => $action, 'reason' => $action])->assertRedirect();
            $this->assertSame($state, $c->fresh()->status);
        }
    }

    public function test_schedule_countdown_waits_for_start_and_activates_when_due(): void
    {
        $c = $this->campaign(['scheduled_at' => now()->addHour()->format('Y-m-d\TH:i')]);
        $this->approve();
        $this->actingAs($this->manager)->get(route('campaigns.show', $c))->assertOk()->assertSee('data-campaign-countdown', false)->assertSee('Start schedule');
        $this->assertSame('ready', $c->fresh()->status);
        $this->post(route('campaigns.control', $c), ['action' => 'start', 'reason' => 'Arm'])->assertRedirect();
        $this->assertSame('scheduled', $c->fresh()->status);
        app(CampaignPolicy::class)->activateDue();
        $this->assertSame('scheduled', $c->fresh()->status);
        $this->travel(2)->hours();
        app(CampaignPolicy::class)->activateDue();
        $this->assertSame('running', $c->fresh()->status);
    }

    public function test_legacy_campaign_without_trunk_uses_environment_connection_and_default_caller_id(): void
    {
        config()->set('broadcasting_discovery.caller_id', '0591111111');
        $campaign = $this->campaign(['caller_id' => null]);
        $this->approve();
        $configuration = $campaign->fresh()->configuration;
        unset($configuration['trunk_id']);
        $campaign->update(['configuration' => $configuration]);
        $snapshot = app(Readiness::class)->snapshot($campaign->fresh());
        $this->assertSame('configured', $snapshot['trunk_name']);
        $this->assertSame('0591111111', $snapshot['caller_id']);
        $this->actingAs($this->admin)->get(route('campaigns.show', $campaign))->assertOk()
            ->assertDontSee('The trunk id field is required.')->assertDontSee('Start is unavailable');
        app(Engine::class)->control($this->manager, $campaign->fresh(), 'start');
        $this->assertSame('configured', CampaignRun::firstOrFail()->snapshot['broadcast']['trunk_name']);
        $this->assertSame('0591111111', CampaignRun::firstOrFail()->snapshot['broadcast']['caller_id']);
    }

    public function test_custom_caller_id_and_stale_trunk_are_resolved_without_a_trunk_selector(): void
    {
        $campaign = $this->campaign(['caller_id' => '0200000000']);
        $this->approve();
        $configuration = $campaign->fresh()->configuration;
        $configuration['trunk_id'] = 999999;
        $campaign->update(['configuration' => $configuration]);
        $snapshot = app(Readiness::class)->snapshot($campaign->fresh());
        $this->assertSame('configured', $snapshot['trunk_name']);
        $this->assertSame('0200000000', $snapshot['caller_id']);
        $this->actingAs($this->admin)->get(route('campaigns.edit', $campaign))->assertOk()
            ->assertDontSee('name="trunk_id"', false)->assertSee('Default caller ID:');
        $this->get('/broadcast')->assertOk()->assertDontSee('name="trunk_id"', false);
    }

    public function test_exact_configured_trunk_never_falls_back_and_posted_trunk_is_ignored(): void
    {
        TrunkProfile::create(['name' => 'other', 'caller_id' => '0244215189', 'active' => true, 'verified_at' => now(), 'verified_by' => $this->admin->id]);
        $c = $this->campaign(['trunk_id' => 2]);
        $this->assertSame(1, $c->configuration['trunk_id']);
        config()->set('broadcasting_discovery.trunk', 'missing');
        $this->assertNull(app(Workspace::class)->configuredTrunk());
        $this->approve();
        $this->actingAs($this->manager)->post(route('campaigns.control', $c), ['action' => 'start', 'reason' => 'Start'])->assertSessionHasErrors();
        $this->assertDatabaseCount('campaign_runs', 0);
    }

    public function test_superadmin_can_manage_other_owners_groups_campaigns_imports_and_future_permissions(): void
    {
        $c = $this->campaign();
        $g = ContactGroup::create(['name' => 'Private group', 'owner_id' => $this->manager->id]);
        $b = ContactImport::create(['owner_id' => $this->manager->id, 'contact_group_id' => $g->id, 'filename' => 'owned.csv', 'headers' => ['phone'], 'total' => 0]);
        $this->admin->roles()->first()->syncPermissions([]);
        $this->admin->unsetRelation('roles');
        $this->assertTrue(Gate::forUser($this->admin)->allows('future.permission'));
        $this->assertTrue($this->admin->hasAnyPermission(['future.permission']));
        $this->actingAs($this->admin)->get(route('campaigns.edit', $c))->assertOk();
        $this->get(route('contacts.groups.show', $g))->assertOk()->assertSee('Save settings');
        $this->get(route('contacts.import', $b))->assertOk();
        $this->get('/contacts/imports')->assertSee('owned.csv');
        $this->get('/horizon')->assertOk();
    }

    public function test_audio_integrity_and_missing_deployment_still_block_superadmin_launch(): void
    {
        $c = $this->campaign();
        Storage::disk('local')->put('message.wav', 'tampered');
        $this->actingAs($this->admin)->post(route('broadcast.audio.approve', $this->audio))->assertSessionHasErrors();
        $this->assertSame('draft', $c->fresh()->status);
        $this->assertSame('pending', $this->audio->fresh()->status);
    }

    public function test_pausing_a_future_schedule_does_not_resume_it_early(): void
    {
        $c = $this->campaign(['scheduled_at' => now()->addHour()->format('Y-m-d\TH:i')]);
        $this->approve();
        $this->actingAs($this->manager);
        foreach (['start' => 'scheduled', 'pause' => 'paused', 'resume' => 'scheduled'] as $action => $state) {
            $this->post(route('campaigns.control', $c), ['action' => $action, 'reason' => $action])->assertRedirect();
            $this->assertSame($state, $c->fresh()->status);
        }
    }

    public function test_owner_control_flag_cannot_authorize_another_campaign_manager(): void
    {
        $c = $this->campaign();
        $this->approve();
        $other = $this->user('Campaign Manager');
        $this->expectException(HttpException::class);
        app(Engine::class)->control($other, $c, 'start', true);
    }

    public function test_audio_approval_does_not_fabricate_deployment_or_allow_unready_launch(): void
    {
        $c = $this->campaign();
        app(AudioLibrary::class)->approve($this->admin, $this->audio);
        $this->assertSame('ready', $c->fresh()->status);
        $this->actingAs($this->admin)->post(route('campaigns.control', $c), ['action' => 'start', 'reason' => 'Try'])->assertSessionHasErrors();
        $this->assertDatabaseCount('campaign_runs', 0);
        $this->assertNull($this->audio->fresh()->deployed_at);
    }
}
