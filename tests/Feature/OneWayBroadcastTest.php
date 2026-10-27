<?php

namespace Tests\Feature;

use App\Models\AudioFile;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\TransferRoute;
use App\Models\TrunkProfile;
use App\Models\User;
use App\Services\Broadcast\AmiClient;
use App\Services\Broadcast\AudioLibrary;
use App\Services\Broadcast\CampaignPolicy;
use App\Services\Broadcast\DialNumber;
use App\Services\Broadcast\Engine;
use App\Services\Broadcast\EventProcessor;
use App\Services\Broadcast\Gateway;
use App\Services\Broadcast\IvrCompiler;
use App\Services\Broadcast\IvrDesigner;
use App\Services\Broadcast\Readiness;
use App\Services\Broadcast\Reconciliation;
use App\Services\Foundation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RecordingAmi extends AmiClient
{
    public array $sent = [];

    public bool $fail = false;

    public function send(array $headers): void
    {
        $this->sent[] = $headers;
        if ($this->fail) {
            throw new \RuntimeException('Simulated broken write');
        }
    }
}
class SessionAmi extends RecordingAmi
{
    protected array $pending = [];

    public function connect(): void {}

    public function poll(): array
    {
        $pending = $this->pending;
        $this->pending = [];

        return $pending;
    }

    public function send(array $headers): void
    {
        parent::send($headers);
        if ($headers['Action'] === 'Ping') {
            $this->pending[] = ['Response' => 'Success', 'ActionID' => $headers['ActionID'], 'Ping' => 'Pong'];
        }
        if ($headers['Action'] === 'CoreShowChannels') {
            $this->pending[] = ['Response' => 'Success', 'ActionID' => $headers['ActionID'], 'EventList' => 'start'];
            $this->pending[] = ['Event' => 'CoreShowChannelsComplete', 'ActionID' => $headers['ActionID'], 'ListItems' => '0'];
        }
    }
}

class InventoryFallbackAmi extends SessionAmi
{
    public bool $rejectStatus = false;

    public function send(array $headers): void
    {
        if (in_array($headers['Action'], ['CoreShowChannels', 'Status'], true)) {
            $this->sent[] = $headers;
            $this->pending[] = ($headers['Action'] === 'CoreShowChannels' || $this->rejectStatus)
                ? ['Response' => 'Error', 'ActionID' => $headers['ActionID']]
                : ['Event' => 'StatusComplete', 'ActionID' => $headers['ActionID'], 'Items' => '0'];

            return;
        }
        parent::send($headers);
    }
}

class OneWayBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Gateway $gateway;

    private Engine $engine;

    private User $manager;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        config()->set('voice_broadcast.enabled', true);
        config()->set('voice_broadcast.global_channels', 3);
        config()->set('voice_broadcast.global_cps', 2);
        $this->manager = $this->user('Campaign Manager');
        $this->approver = $this->user('Approver');
        $this->engine = app(Engine::class);
        $this->gateway = app(Gateway::class);
        DB::table('broadcast_gateway')->where('id', 1)->update(['owner' => $this->gateway->owner, 'healthy' => true, 'heartbeat_at' => now(), 'snapshot_at' => now(), 'channels' => '[]']);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function campaign(int $targets = 1, string $format = 'international', string $endpoint = 'approved-trunk', array $overrides = []): Campaign
    {
        config()->set('broadcasting_discovery.trunk', $endpoint);
        $trunk = TrunkProfile::firstOrCreate(['name' => $endpoint], ['caller_id' => $format === 'national' ? '0596921082' : '233302123456', 'max_channels' => 10, 'reserved_channels' => 2, 'safe_channels' => 5, 'max_cps' => 2, 'number_format' => $format, 'active' => true, 'verified_at' => now(), 'verified_by' => $this->manager->id, 'evidence' => 'carrier/confirmation']);
        $uuid = (string) Str::uuid();
        $wav = $this->wav();
        $key = 'broadcast/audio/'.$uuid.'.wav';
        Storage::disk('local')->put($key, $wav);
        $audio = AudioFile::create(['uuid' => $uuid, 'name' => 'Approved message', 'storage_key' => $key, 'checksum' => hash('sha256', $wav), 'duration' => 1, 'status' => 'approved', 'uploaded_by' => $this->manager->id, 'approved_by' => $this->approver->id, 'approved_at' => now(), 'deployed_at' => now()]);
        $ids = [];
        for ($i = 0; $i < $targets; $i++) {
            $phone = '024'.str_pad((string) (Contact::count() + 1), 7, '0', STR_PAD_LEFT);
            $contact = app(Foundation::class)->createContact($this->manager, ['name' => 'Recipient', 'phone' => $phone, 'source' => 'Form']);
            $ids[] = $contact->id;
            $contact->consents()->create(['purpose' => 'Updates', 'legal_basis' => 'Consent', 'source' => 'Form', 'evidence_ref' => 'private/form', 'granted_at' => now()->subDay()]);
        }
        $campaign = app(Foundation::class)->createCampaign($this->manager, ['name' => 'Updates', 'purpose' => 'Updates', 'type' => $overrides['type'] ?? 'one_way']);
        $this->engine->configure($this->manager, $campaign, array_replace(['audio_id' => $audio->id, 'trunk_id' => $trunk->id, 'concurrency' => 2, 'cps' => 1, 'window_start' => '00:00', 'window_end' => '23:59', 'contact_ids' => $ids], array_diff_key($overrides, ['type' => true])));
        app(Foundation::class)->transition($this->manager, $campaign, 'submit', 'Ready');
        app(Foundation::class)->transition($this->approver, $campaign, 'approve', 'Reviewed');
        $this->engine->control($this->user('Operator'), $campaign, 'start');

        return $campaign->fresh();
    }

    private function wav(int $rate = 8000): string
    {
        $data = str_repeat("\x00", 16000);

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('V', 16).pack('vvVVvv', 1, 1, $rate, $rate * 2, 2, 16).'data'.pack('V', strlen($data)).$data;
    }

    private function reserve(): ?CallAttempt
    {
        return DB::transaction(fn () => $this->engine->reserve($this->gateway->owner));
    }

    private function send(CallAttempt $attempt, ?RecordingAmi $ami = null): RecordingAmi
    {
        Campaign::query()->update(['last_dispatched_at' => now()->subSeconds(5)]);
        $ami ??= new RecordingAmi;
        $this->gateway->authorized($ami, $attempt->uuid, true);

        return $ami;
    }

    private function event(CallAttempt $a, string $name, array $extra = []): void
    {
        app(EventProcessor::class)->ingest(['Event' => $name, 'Uniqueid' => $a->uuid, 'Channel' => 'PJSIP/approved-trunk-00000001'] + $extra);
    }

    public function test_url_mode_originates_with_call_scoped_media_prefix(): void
    {
        config()->set('voice_broadcast.media_mode', 'url');
        config()->set('voice_broadcast.media_base_url', 'https://voice.example.test/app');
        $campaign = $this->campaign();
        $attempt = $this->reserve();
        $ami = $this->send($attempt);
        $this->assertSame('gwosevo-broadcast-url', $ami->sent[0]['Context']);
        $this->assertContains('__VB_MEDIA_PREFIX=https://voice.example.test/app/pbx-media/'.$attempt->uuid.'/'.$attempt->token, $ami->sent[0]['Variable']);
        $this->assertSame('url', $campaign->versions()->firstOrFail()->snapshot['broadcast']['media_mode']);
    }

    public function test_full_one_way_call_and_deduplicated_milestones(): void
    {
        $campaign = $this->campaign();
        $a = $this->reserve();
        $ami = $this->send($a);
        $this->assertSame('Originate', $ami->sent[0]['Action']);
        $this->assertSame($a->uuid, $ami->sent[0]['ChannelId']);
        app(EventProcessor::class)->ingest(['Response' => 'Success', 'ActionID' => $a->action_id]);
        $this->assertNull($a->fresh()->answered_at);
        $this->event($a, 'Newstate', ['ChannelStateDesc' => 'Up']);
        $this->event($a, 'UserEvent', ['UserEvent' => 'VBPlaybackStart', 'AttemptID' => $a->uuid]);
        $this->event($a, 'UserEvent', ['UserEvent' => 'VBPlaybackComplete', 'AttemptID' => $a->uuid]);
        $this->event($a, 'UserEvent', ['UserEvent' => 'VBPlaybackComplete', 'AttemptID' => $a->uuid]);
        $this->assertSame(1, DB::table('channel_reservations')->whereNull('released_at')->count());
        $this->event($a, 'Hangup', ['Cause' => '16']);
        $this->assertSame('completed', $a->fresh()->outcome);
        $this->assertSame('completed', $campaign->fresh()->status);
        $this->assertSame(0, DB::table('channel_reservations')->whereNull('released_at')->count());
        $this->assertDatabaseCount('call_events', 5);
    }

    public function test_dnd_is_checked_at_reservation_and_again_before_send(): void
    {
        $campaign = $this->campaign(2);
        $first = CampaignRecipient::where('campaign_id', $campaign->id)->first();
        DB::table('suppression_entries')->insert(['phone_e164' => $first->phone_e164, 'reason' => 'Opted out', 'created_by' => $this->manager->id, 'effective_at' => now()]);
        $a = $this->reserve();
        $this->assertSame('excluded', $first->fresh()->status);
        DB::table('suppression_entries')->insert(['phone_e164' => CampaignRecipient::find($a->campaign_recipient_id)->phone_e164, 'reason' => 'Opted out after reservation', 'created_by' => $this->manager->id, 'effective_at' => now()]);
        $ami = $this->send($a);
        $this->assertCount(0, $ami->sent);
        $this->assertSame('suppressed', $a->fresh()->outcome);
    }

    public function test_admission_enforces_global_cap_campaign_cap_and_reserved_channels(): void
    {
        $campaign = $this->campaign(3);
        config()->set('voice_broadcast.global_channels', 1);
        $a = $this->reserve();
        $this->assertNotNull($a);
        $this->assertNull($this->reserve());
        $this->event($a, 'Hangup', ['Cause' => '17']);
        config()->set('voice_broadcast.global_channels', 3);
        TrunkProfile::query()->update(['reserved_channels' => 9]);
        Campaign::query()->update(['last_dispatched_at' => now()->subSeconds(5)]);
        $this->assertNotNull($this->reserve());
        Campaign::query()->update(['last_dispatched_at' => now()->subSeconds(5)]);
        $this->assertNull($this->reserve());
    }

    public function test_normal_pbx_traffic_can_reduce_broadcast_capacity_to_zero(): void
    {
        $this->campaign();
        $channels = [];
        for ($i = 0; $i < 10; $i++) {
            $channels[] = ['uniqueid' => 'ordinary-'.$i, 'channel' => 'PJSIP/other-'.$i, 'linkedid' => 'normal'];
        }
        DB::table('broadcast_gateway')->where('id', 1)->update(['channels' => json_encode($channels)]);
        $this->assertNull($this->reserve());
    }

    public function test_disabled_stale_and_unknown_gateway_fail_closed(): void
    {
        $this->campaign();
        config()->set('voice_broadcast.enabled', false);
        $this->assertNull($this->reserve());
        config()->set('voice_broadcast.enabled', true);
        DB::table('broadcast_gateway')->where('id', 1)->update(['snapshot_at' => now()->subMinute()]);
        $this->assertNull($this->reserve());
        DB::table('broadcast_gateway')->where('id', 1)->update(['snapshot_at' => now()]);
        $a = $this->reserve();
        $a->update(['state' => 'unknown']);
        $this->assertNull($this->reserve());
    }

    public function test_pause_prevents_pending_originate_and_keeps_other_active_calls(): void
    {
        $campaign = $this->campaign();
        $a = $this->reserve();
        $this->engine->control($this->user('Operator'), $campaign, 'pause');
        $ami = $this->send($a);
        $this->assertCount(0, $ami->sent);
        $this->assertSame('ready', $a->fresh()->state);
        $this->engine->control($this->user('Operator'), $campaign, 'resume');
        $ami = $this->send($a);
        $this->assertCount(1, $ami->sent);
    }

    public function test_actual_originate_cps_is_charged_and_duplicate_auth_never_redials(): void
    {
        $this->campaign(2);
        $a = $this->reserve();
        $ami = $this->send($a);
        $this->gateway->authorized($ami, $a->uuid, true);
        $this->assertCount(1, $ami->sent);
        $this->assertGreaterThan(microtime(true), DB::table('broadcast_gateway')->where('id', 1)->value('next_send_at'));
        $this->assertNull($this->reserve());
    }

    public function test_partial_socket_write_keeps_durable_intent_and_retains_capacity(): void
    {
        $this->campaign();
        $a = $this->reserve();
        $ami = new RecordingAmi;
        $ami->fail = true;
        try {
            $this->send($a, $ami);
            $this->fail('Expected socket failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('sending', $a->fresh()->state);
        }
        $this->engine->disconnected($this->gateway->owner);
        $this->assertSame('unknown', $a->fresh()->state);
        $this->assertSame(1, DB::table('channel_reservations')->whereNull('released_at')->count());
    }

    public function test_disconnect_cancels_definitely_unsent_intents(): void
    {
        $this->campaign();
        $a = $this->reserve();
        $this->engine->disconnected($this->gateway->owner);
        $this->assertSame('cancelled_before_send', $a->fresh()->outcome);
        $this->assertNotNull($a->fresh()->ended_at);
    }

    public function test_early_hangup_and_late_completion_do_not_double_release(): void
    {
        $this->campaign();
        $a = $this->reserve();
        $this->send($a);
        $this->event($a, 'Newstate', ['ChannelStateDesc' => 'Up']);
        $this->event($a, 'Hangup', ['Cause' => '16']);
        $this->assertSame('early_hangup', $a->fresh()->outcome);
        $this->event($a, 'UserEvent', ['UserEvent' => 'VBPlaybackComplete', 'AttemptID' => $a->uuid]);
        $this->assertSame('completed', $a->fresh()->outcome);
        $this->assertNotNull($a->fresh()->playback_started_at);
        $this->event($a, 'Hangup', ['Cause' => '17']);
        $this->assertSame('completed', $a->fresh()->outcome);
    }

    public function test_other_leg_hangup_cannot_release_broadcast_slot(): void
    {
        $this->campaign();
        $a = $this->reserve();
        $this->send($a);
        app(EventProcessor::class)->ingest(['Event' => 'Hangup', 'ActionID' => $a->action_id, 'Uniqueid' => 'other-leg', 'Cause' => '16']);
        $this->assertNull($a->fresh()->ended_at);
    }

    public function test_cdr_reconciliation_requires_snapshot_and_does_not_invent_playback_completion(): void
    {
        $this->campaign();
        $a = $this->reserve();
        $this->send($a);
        $a->update(['sent_at' => now()->subSeconds(10)]);
        $this->engine->disconnected($this->gateway->owner);
        DB::table('broadcast_gateway')->where('id', 1)->update(['healthy' => true, 'snapshot_at' => now(), 'channels' => '[]']);
        app(Reconciliation::class)->resolve($this->user('Telephony Administrator'), ['attempt_uuid' => $a->uuid, 'uniqueid' => $a->uuid, 'ended_at' => now()->subSecond()->toIso8601String(), 'disposition' => 'ANSWERED', 'billable_seconds' => 5], 'private/cdr/1');
        $this->assertSame('answered_unknown', $a->fresh()->outcome);
        $this->assertNull($a->fresh()->playback_completed_at);
        $this->assertNotNull($a->fresh()->ended_at);
    }

    public function test_active_channel_blocks_reconciliation(): void
    {
        $this->campaign();
        $a = $this->reserve();
        $this->send($a);
        $a->update(['state' => 'unknown', 'sent_at' => now()->subSeconds(10)]);
        DB::table('broadcast_gateway')->where('id', 1)->update(['channels' => json_encode([['uniqueid' => $a->uuid, 'channel' => 'PJSIP/trunk-1', 'linkedid' => $a->uuid]])]);
        $this->expectException(ValidationException::class);
        app(Reconciliation::class)->resolve($this->user('Telephony Administrator'), ['attempt_uuid' => $a->uuid, 'uniqueid' => $a->uuid, 'ended_at' => now()->subSecond()->toIso8601String(), 'disposition' => 'FAILED', 'billable_seconds' => 0], 'private/cdr/2');
    }

    public function test_audio_validation_approval_deployment_and_checksum_tampering(): void
    {
        $audio = app(AudioLibrary::class)->upload($this->manager, UploadedFile::fake()->createWithContent('message.wav', $this->wav()), 'Message');
        app(AudioLibrary::class)->approve($this->approver, $audio);
        app(AudioLibrary::class)->deployed($this->user('Telephony Administrator'), $audio, $audio->checksum, 'private/pbx/checksum');
        app(Readiness::class)->audio($audio->fresh());
        Storage::disk('local')->put($audio->storage_key, 'tampered');
        $this->expectException(ValidationException::class);
        app(Readiness::class)->audio($audio->fresh());
    }

    public function test_super_administrator_can_approve_any_pending_audio_including_their_own(): void
    {
        $audio = app(AudioLibrary::class)->upload($this->manager, UploadedFile::fake()->createWithContent('message.wav', $this->wav()), 'Message');
        $admin = $this->user('Super Administrator');

        app(AudioLibrary::class)->approve($admin, $audio);
        $this->assertSame('approved', $audio->fresh()->status);

        $ownAudio = app(AudioLibrary::class)->upload($admin, UploadedFile::fake()->createWithContent('own-message.wav', $this->wav()), 'Own message');
        app(AudioLibrary::class)->approve($admin, $ownAudio);
        $this->assertSame('approved', $ownAudio->fresh()->status);
    }

    public function test_wrong_audio_format_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(AudioLibrary::class)->upload($this->manager, UploadedFile::fake()->createWithContent('message.wav', $this->wav(16000)), 'Message');
    }

    public function test_legacy_approval_cannot_launch_without_full_snapshot(): void
    {
        $c = app(Foundation::class)->createCampaign($this->manager, ['name' => 'Legacy', 'purpose' => 'Updates', 'type' => 'one_way']);
        app(Foundation::class)->transition($this->manager, $c, 'submit', 'Ready');
        app(Foundation::class)->transition($this->approver, $c, 'approve', 'Reviewed');
        $this->expectException(ValidationException::class);
        $this->engine->control($this->user('Operator'), $c, 'start');
    }

    public function test_operations_and_report_authorization_and_populated_views(): void
    {
        $campaign = $this->campaign();
        $a = $this->reserve();
        $this->send($a);
        $this->actingAs($this->manager)->get('/broadcast')->assertOk();
        $this->get('/audio')->assertOk()->assertSee('Approved message');
        $this->get('/broadcast/campaigns/'.$campaign->id.'/report')->assertOk()->assertSee($a->uuid);
        $other = $this->user('Campaign Manager');
        $this->actingAs($other)->get('/broadcast/campaigns/'.$campaign->id.'/report')->assertForbidden();
        $this->post('/broadcast/campaigns/'.$campaign->id.'/control', ['action' => 'pause'])->assertForbidden();
        $this->post('/broadcast/emergency', ['action' => 'stop'])->assertForbidden();
    }

    public function test_global_emergency_stops_campaign_and_blocks_new_calls(): void
    {
        $campaign = $this->campaign(2);
        $this->actingAs($this->user('Super Administrator'))->post('/broadcast/emergency', ['action' => 'stop'])->assertRedirect();
        $this->assertSame('cancelled', $campaign->fresh()->status);
        $this->assertNull($this->reserve());
    }

    public function test_single_gateway_lease_rejects_a_second_process(): void
    {
        $this->expectException(\RuntimeException::class);
        app(Gateway::class)->claim();
    }

    public function test_gateway_observation_verifies_pong_and_complete_inventory_without_control_writes(): void
    {
        config()->set('voice_broadcast.enabled', false);
        config()->set('voice_broadcast.observe_only', true);
        $ami = new SessionAmi;
        $iterations = 0;
        $this->gateway->session($ami, function () use (&$iterations) {
            return ++$iterations > 5;
        });
        $this->assertNotContains('QueueStatus', array_column($ami->sent, 'Action'));
        $this->assertTrue($this->gateway->pongVerified);
        $this->assertTrue($this->gateway->snapshotVerified);
        $this->assertSame(['Ping', 'CoreShowChannels'], array_column($ami->sent, 'Action'));
    }

    public function test_subsecond_campaign_cps_cannot_be_bypassed_by_timestamp_truncation(): void
    {
        $campaign = $this->campaign(2);
        $a = $this->reserve();
        $campaign->update(['last_dispatched_at' => now()->format('Y-m-d H:i:s.u')]);
        $ami = new RecordingAmi;
        $this->gateway->authorized($ami, $a->uuid, true);
        $this->assertCount(0, $ami->sent);
        $this->assertSame('ready', $a->fresh()->state);
    }

    public function test_emergency_hangup_targets_only_freshly_correlated_broadcast_channels(): void
    {
        $campaign = $this->campaign();
        $a = $this->reserve();
        $this->send($a);
        $this->event($a, 'Newchannel');
        $this->engine->control($this->user('Super Administrator'), $campaign, 'emergency');
        DB::table('broadcast_gateway')->where('id', 1)->update(['channels' => json_encode([
            ['uniqueid' => $a->uuid, 'channel' => 'PJSIP/approved-trunk-00000001', 'linkedid' => $a->uuid],
            ['uniqueid' => 'ordinary', 'channel' => 'PJSIP/normal-00000002', 'linkedid' => 'ordinary'],
        ])]);
        $method = new \ReflectionMethod(Gateway::class, 'hangups');
        $ami = new RecordingAmi;
        $method->invoke($this->gateway, $ami);
        $this->assertCount(1, $ami->sent);
        $this->assertSame('PJSIP/approved-trunk-00000001', $ami->sent[0]['Channel']);
        $this->assertSame('Hangup', $ami->sent[0]['Action']);
        DB::table('broadcast_gateway')->where('id', 1)->update(['snapshot_at' => now()->subMinute()]);
        $ami = new RecordingAmi;
        $method->invoke($this->gateway, $ami);
        $this->assertCount(0, $ami->sent);
    }

    public function test_status_command_reports_fail_closed_and_enabled_readiness(): void
    {
        config()->set('voice_broadcast.enabled', false);
        $this->artisan('broadcast:status --json')->expectsOutputToContain('"dispatch_enabled": false')->assertExitCode(1);
        config()->set('voice_broadcast.enabled', true);
        $this->campaign();
        $this->artisan('broadcast:status --json')->expectsOutputToContain('"gateway_healthy": true')->assertExitCode(0);
    }

    public function test_status_fallback_requires_a_complete_inventory(): void
    {
        config()->set('voice_broadcast.enabled', false);
        config()->set('voice_broadcast.observe_only', true);
        $ami = new InventoryFallbackAmi;
        $iterations = 0;
        $this->gateway->session($ami, function () use (&$iterations) {
            return ++$iterations > 5;
        });
        $this->assertTrue($this->gateway->snapshotVerified);
        $this->assertSame(['Ping', 'CoreShowChannels', 'Status'], array_column($ami->sent, 'Action'));
    }

    public function test_rejected_channel_inventory_never_marks_gateway_healthy(): void
    {
        config()->set('voice_broadcast.enabled', false);
        config()->set('voice_broadcast.observe_only', true);
        DB::table('broadcast_gateway')->where('id', 1)->update(['healthy' => false]);
        $ami = new InventoryFallbackAmi;
        $ami->rejectStatus = true;
        $iterations = 0;
        try {
            $this->gateway->session($ami, function () use (&$iterations) {
                return ++$iterations > 5;
            });
            $this->fail('Expected rejected channel inventory.');
        } catch (\RuntimeException $e) {
            $this->assertSame('AMI channel inventory rejected. Verify AMI read permissions.', $e->getMessage());
            $this->assertFalse($this->gateway->snapshotVerified);
            $this->assertSame(0, (int) DB::table('broadcast_gateway')->where('id', 1)->value('healthy'));
        }
    }

    public function test_national_format_and_ip_endpoint_are_used_by_the_gateway(): void
    {
        $this->campaign(1, 'national', '196.201.48.139');
        $attempt = $this->reserve();
        $ami = $this->send($attempt);
        $this->assertSame('PJSIP/0240000001@196.201.48.139', $ami->sent[0]['Channel']);
        $this->assertSame('0596921082', $ami->sent[0]['CallerID']);
        $this->assertDatabaseHas('contacts', ['phone_e164' => '+233240000001']);
    }

    public function test_dial_formats_are_explicit_and_invalid_destinations_are_rejected(): void
    {
        $this->assertSame('0531005130', DialNumber::format('+233531005130', 'national'));
        $this->assertSame('233531005130', DialNumber::format('+233531005130', 'international'));
        $this->assertSame('+233531005130', DialNumber::format('+233531005130', 'e164'));
        $this->expectException(\InvalidArgumentException::class);
        DialNumber::format("+233531005130\r\nAction: Hangup", 'national');
    }

    private function interactiveFixture(): array
    {
        $base = $this->campaign();
        $base->update(['status' => 'paused']);
        $graph = ['start' => 'menu', 'nodes' => [
            ['id' => 'menu', 'type' => 'input', 'audio_id' => $base->configuration['audio_id'], 'timeout_seconds' => 5, 'branches' => ['1' => 'done', '9' => 'optout'], 'invalid' => 'done', 'timeout' => 'done'],
            ['id' => 'optout', 'type' => 'opt_out'], ['id' => 'done', 'type' => 'end'],
        ]];
        $designer = app(IvrDesigner::class);
        $flow = $designer->save($this->manager, 'Member updates', $graph);
        $designer->approve($this->approver, $flow);
        $flow->update(['deployed_at' => now()]);
        $campaign = app(Foundation::class)->createCampaign($this->manager, ['name' => 'Interactive', 'purpose' => 'Updates', 'type' => 'interactive']);
        $this->engine->configure($this->manager, $campaign, $base->configuration + ['ivr_flow_id' => $flow->id]);
        app(Foundation::class)->transition($this->manager, $campaign, 'submit', 'Ready');
        app(Foundation::class)->transition($this->approver, $campaign, 'approve', 'Reviewed');
        $this->engine->control($this->user('Operator'), $campaign, 'start');

        return [$campaign->fresh(), $flow->fresh()];
    }

    public function test_url_mode_ivr_prompts_use_protected_media_without_local_file_checks(): void
    {
        config()->set('voice_broadcast.media_mode', 'url');
        config()->set('voice_broadcast.media_base_url', 'https://voice.example.test/app');
        [$campaign, $flow] = $this->interactiveFixture();
        $compiled = app(IvrCompiler::class)->compile($flow);
        $this->assertStringContainsString('DB_DELETE(vb-auth/', $compiled);
        $this->assertStringContainsString('Read(VB_DIGIT,${VB_MEDIA_PREFIX}/', $compiled);
        $this->assertStringNotContainsString('STAT(e,', $compiled);
        $this->assertStringNotContainsString('DB(vb-audio/', $compiled);
        $this->assertStringContainsString('.wav,1,e,1,5)', $compiled);
    }

    public function test_interactive_flow_compilation_and_gateway_context(): void
    {
        [$campaign,$flow] = $this->interactiveFixture();
        $compiled = app(IvrCompiler::class)->compile($flow);
        $this->assertStringContainsString('DB_DELETE(vb-auth/', $compiled);
        $this->assertStringContainsString('Read(VB_DIGIT,gwosevo/', $compiled);
        $this->assertStringContainsString('VBOptOut', $compiled);
        $attempt = $this->reserve();
        $ami = $this->send($attempt);
        $this->assertSame('gwosevo-ivr-'.str_replace('-', '', $flow->uuid), $ami->sent[0]['Context']);
    }

    public function test_ivr_digits_are_allowlisted_deduplicated_and_optout_suppresses_immediately(): void
    {
        [$campaign,$flow] = $this->interactiveFixture();
        $attempt = $this->reserve();
        $this->send($attempt);
        $digit = ['UserEvent' => 'VBDigit', 'AttemptID' => $attempt->uuid, 'NodeID' => 'menu', 'Digit' => '9'];
        $this->event($attempt, 'UserEvent', $digit);
        $this->event($attempt, 'UserEvent', $digit);
        $this->event($attempt, 'UserEvent', array_replace($digit, ['Digit' => '4']));
        $this->assertDatabaseCount('dtmf_responses', 1);
        $this->assertDatabaseHas('dtmf_responses', ['node_id' => 'menu', 'digit' => '9']);
        $this->event($attempt, 'UserEvent', ['UserEvent' => 'VBOptOut', 'AttemptID' => $attempt->uuid, 'NodeID' => 'optout']);
        $recipient = CampaignRecipient::findOrFail($attempt->campaign_recipient_id);
        $this->assertDatabaseHas('suppression_entries', ['phone_e164' => $recipient->phone_e164, 'reason' => 'IVR opt-out']);
        $this->event($attempt, 'Hangup', ['Cause' => '16']);
        $this->assertSame('opt_out', $attempt->fresh()->outcome);
        $this->actingAs($this->approver)->get('/broadcast/campaigns/'.$campaign->id.'/report')->assertOk()->assertSee('Interactive responses');
    }

    public function test_flow_loop_and_reserved_extension_ids_are_rejected(): void
    {
        [$campaign,$flow] = $this->interactiveFixture();
        $graph = $flow->graph;
        $graph['nodes'][0]['branches']['1'] = 'menu';
        try {
            app(IvrDesigner::class)->validate($graph);
            $this->fail('Expected loop rejection');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('broadcast', $e->errors());
        }
        $this->expectException(ValidationException::class);
        app(IvrDesigner::class)->validate(['start' => 'h', 'nodes' => [['id' => 'h', 'type' => 'opt_out']]]);
    }

    public function test_approved_flow_is_immutable_and_clones_have_sequential_versions(): void
    {
        [$campaign,$flow] = $this->interactiveFixture();
        $designer = app(IvrDesigner::class);
        $second = $designer->save($this->manager, 'Revision 2', $flow->graph, $flow);
        $third = $designer->save($this->manager, 'Revision 3', $flow->graph, $flow);
        $this->assertSame(2, $second->version);
        $this->assertSame(3, $third->version);
        $this->assertSame($flow->family_uuid, $third->family_uuid);
        $this->expectException(\LogicException::class);
        $flow->update(['graph' => []]);
    }

    public function test_flow_self_approval_is_denied(): void
    {
        [$campaign,$flow] = $this->interactiveFixture();
        $draft = app(IvrDesigner::class)->save($this->manager, 'Draft', $flow->graph);
        $this->manager->assignRole('Approver');
        $this->actingAs($this->manager)->post('/interactive/flows/'.$draft->id.'/approve')->assertForbidden();
    }

    public function test_caller_ids_can_be_set_and_campaign_override_is_validated(): void
    {
        $campaign = $this->campaign();
        $trunk = TrunkProfile::firstOrFail();
        $this->actingAs($this->manager)->post('/interactive/caller-ids/'.$trunk->id, ['caller_id' => '0596921082', 'evidence' => 'carrier'])->assertForbidden();
        $this->actingAs($this->user('Telephony Administrator'))->post('/interactive/caller-ids/'.$trunk->id, ['caller_id' => '0596921082', 'allowed_caller_ids' => '0244215189', 'evidence' => 'carrier'])->assertRedirect();
        $draft = app(Foundation::class)->createCampaign($this->manager, ['name' => 'Caller ID test', 'purpose' => 'Updates', 'type' => 'one_way']);
        $config = $campaign->configuration + ['caller_id' => '0244215189'];
        $this->engine->configure($this->manager, $draft, $config);
        $this->assertSame('0244215189', app(Readiness::class)->snapshot($draft->fresh())['caller_id']);
        $this->engine->configure($this->manager, $draft, array_replace($config, ['caller_id' => '0200000000']));
        $this->assertSame('0200000000', app(Readiness::class)->snapshot($draft->fresh())['caller_id']);
        $this->expectException(ValidationException::class);
        $this->engine->configure($this->manager, $draft, array_replace($config, ['caller_id' => 'invalid-number']));
    }

    public function test_scheduled_campaign_waits_until_due_and_cancelled_campaign_does_not_activate(): void
    {
        $campaign = $this->campaign(1, 'international', 'approved-trunk', ['scheduled_at' => now()->addHour()->format('Y-m-d H:i:s')]);
        $this->assertSame('scheduled', $campaign->status);
        $this->assertNull($this->reserve());
        app(CampaignPolicy::class)->activateDue();
        $this->assertSame('scheduled', $campaign->fresh()->status);
        $campaign->update(['scheduled_at' => now()->subMinute()]);
        app(CampaignPolicy::class)->activateDue();
        $this->assertSame('running', $campaign->fresh()->status);
        $campaign->refresh()->update(['status' => 'scheduled', 'stop_requested' => true]);
        app(CampaignPolicy::class)->activateDue();
        $this->assertSame('scheduled', $campaign->fresh()->status);
    }

    public function test_retry_backoff_is_bounded_and_rechecks_eligibility(): void
    {
        $campaign = $this->campaign(1, 'international', 'approved-trunk', ['max_attempts' => 2, 'retry_delay_seconds' => 60]);
        $first = $this->reserve();
        $this->send($first);
        $this->event($first, 'Hangup', ['Cause' => '17']);
        $recipient = CampaignRecipient::findOrFail($first->campaign_recipient_id);
        $this->assertSame('pending', $recipient->status);
        $this->assertNotNull($recipient->next_attempt_at);
        $this->assertNull($this->reserve());
        $recipient->update(['next_attempt_at' => now()->subSecond()]);
        Campaign::query()->update(['last_dispatched_at' => now()->subSeconds(5)]);
        DB::table('broadcast_gateway')->update(['next_send_at' => 0]);
        TrunkProfile::query()->update(['next_send_at' => 0]);
        $second = $this->reserve();
        $this->assertSame(2, $second->attempt_no);
        $this->send($second);
        $this->event($second, 'Hangup', ['Cause' => '17']);
        $this->assertSame('terminal', $recipient->fresh()->status);
    }

    public function test_unknown_and_completed_outcomes_never_retry_even_with_retry_policy(): void
    {
        $this->campaign(1, 'international', 'approved-trunk', ['max_attempts' => 5]);
        $a = $this->reserve();
        $this->send($a);
        $this->engine->disconnected($this->gateway->owner);
        $this->assertSame('unknown', $a->fresh()->state);
        $this->assertSame('unknown', CampaignRecipient::find($a->campaign_recipient_id)->status);
    }

    public function test_transfer_routes_are_allowlisted_and_compiled_with_availability_checks(): void
    {
        [$campaign,$flow] = $this->interactiveFixture();
        $route = TransferRoute::create(['name' => 'Support', 'type' => 'queue', 'destination' => '200', 'evidence' => 'PBX verified', 'verified_by' => $this->manager->id, 'active' => true]);
        $graph = ['start' => 'agent', 'nodes' => [['id' => 'agent', 'type' => 'transfer', 'route_id' => $route->id, 'next' => 'done', 'unavailable' => 'done'], ['id' => 'done', 'type' => 'end']]];
        $draft = app(IvrDesigner::class)->save($this->manager, 'Support transfer', $graph);
        $compiled = app(IvrCompiler::class)->compile($draft);
        $this->assertStringContainsString('QUEUE_MEMBER(200,ready)', $compiled);
        $this->assertStringContainsString('Queue(200,c,,,30)', $compiled);
        $route->update(['active' => false]);
        $this->expectException(ValidationException::class);
        app(IvrDesigner::class)->validate($graph);
    }

    public function test_ivr_deployment_requires_actual_generated_file_checksum_and_pages_render(): void
    {
        [$campaign,$flow] = $this->interactiveFixture();
        $telephony = $this->user('Telephony Administrator');
        $this->actingAs($telephony)->get('/telephony')->assertOk()->assertSee('Outbound caller IDs');
        $this->post('/interactive/flows/'.$flow->id.'/deploy', ['checksum' => str_repeat('0', 64), 'evidence' => 'PBX test'])->assertSessionHasErrors('broadcast');
        $checksum = hash('sha256', app(IvrCompiler::class)->compile($flow));
        $this->post('/interactive/flows/'.$flow->id.'/deploy', ['checksum' => $checksum, 'evidence' => 'PBX test'])->assertRedirect();
        $this->get('/interactive/flows/'.$flow->id.'/dialplan')->assertOk();
        $this->actingAs($this->manager)->get('/interactive')->assertOk()->assertSee('Visual IVR builder');
    }

    public function test_phase_four_migration_is_resumable_without_losing_flow_data(): void
    {
        [$campaign,$flow] = $this->interactiveFixture();
        $migration = require database_path('migrations/2026_10_22_220000_add_interactive_engine.php');
        $migration->up();
        $this->assertDatabaseHas('ivr_flows', ['id' => $flow->id, 'uuid' => $flow->uuid, 'status' => 'approved']);
    }

    public function test_answered_extension_leg_is_correlated_without_replacing_recipient_channel(): void
    {
        [$campaign,$flow] = $this->interactiveFixture();
        $a = $this->reserve();
        $this->send($a);
        $this->event($a, 'UserEvent', ['UserEvent' => 'VBTransferStart', 'AttemptID' => $a->uuid, 'NodeID' => 'menu']);
        app(EventProcessor::class)->ingest(['Event' => 'UserEvent', 'UserEvent' => 'VBTransferAnswered', 'AttemptID' => $a->uuid, 'ParentUniqueid' => $a->uuid, 'Uniqueid' => 'staff-leg', 'Channel' => 'PJSIP/200-00000099']);
        $this->assertNotNull($a->fresh()->transfer_answered_at);
        $this->assertSame('PJSIP/approved-trunk-00000001', $a->fresh()->channel);
        $this->event($a, 'Hangup', ['Cause' => '16']);
        $this->assertSame('transfer_success', $a->fresh()->outcome);
    }

    public function test_environment_trunk_import_is_inactive_idempotent_and_does_not_invent_capacity(): void
    {
        config()->set('broadcasting_discovery.trunk', '196.201.48.139');
        config()->set('broadcasting_discovery.caller_id', '0596921082');
        config()->set('broadcasting_discovery.number_format', 'national');
        $this->artisan('broadcast:import-trunk')->assertExitCode(0);
        $this->artisan('broadcast:import-trunk')->assertExitCode(0);
        $this->assertDatabaseCount('trunk_profiles', 1);
        $trunk = TrunkProfile::firstOrFail();
        $this->assertFalse($trunk->active);
        $this->assertNull($trunk->verified_at);
        $this->assertSame(0, $trunk->max_channels);
        $this->actingAs($this->user('Telephony Administrator'))->get('/telephony')->assertOk()->assertSee('0596921082')->assertSee('Verify or update capacity');
    }

    public function test_trunk_capacity_requires_telephony_permission_and_valid_reservations(): void
    {
        $this->campaign();
        $trunk = TrunkProfile::firstOrFail();
        $data = ['max_channels' => 10, 'reserved_channels' => 10, 'safe_channels' => 5, 'max_cps' => 1, 'number_format' => 'national', 'evidence' => 'carrier'];
        $this->actingAs($this->user('Telephony Administrator'))->post('/interactive/capacity/'.$trunk->id, $data)->assertSessionHasErrors('reserved_channels');
        $data['reserved_channels'] = 5;
        $this->post('/interactive/capacity/'.$trunk->id, $data)->assertRedirect();
        $this->assertSame('national', $trunk->fresh()->number_format);
        $this->actingAs($this->manager)->post('/interactive/capacity/'.$trunk->id, $data)->assertForbidden();
    }

    public function test_window_closing_between_intent_and_socket_write_preserves_unsent_work(): void
    {
        $this->campaign();
        $a = $this->reserve();
        Campaign::query()->update(['last_dispatched_at' => now()->subSeconds(5)]);
        $ready = \Mockery::mock(Readiness::class)->makePartial();
        $ready->shouldReceive('inWindow')->twice()->andReturn(true, false);
        $gateway = new Gateway($this->engine, $ready, app(EventProcessor::class));
        $gateway->owner = $this->gateway->owner;
        $ami = new RecordingAmi;
        $gateway->authorized($ami, $a->uuid, true);
        $this->assertCount(0, $ami->sent);
        $this->assertSame('ready', $a->fresh()->state);
        $this->assertNull($a->fresh()->sent_at);
        $this->assertSame(1, DB::table('channel_reservations')->whereNull('released_at')->count());
    }

    public function test_schedule_datetime_is_converted_from_selected_timezone_to_utc(): void
    {
        $policy = app(CampaignPolicy::class)->schedule(['timezone' => 'Africa/Nairobi', 'scheduled_at' => '2026-10-23T12:00']);
        $this->assertSame('2026-10-23T09:00:00+00:00', $policy['scheduled_at']);
    }

    public function test_ami_header_injection_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        AmiClient::encode(['Action' => 'Originate', 'Channel' => "PJSIP/test\r\nAction: Hangup"]);
    }

    public function test_ami_frames_can_arrive_fragmented_and_batched(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        stream_set_blocking($pair[0], false);
        $ami = new AmiClient;
        $property = new \ReflectionProperty(AmiClient::class, 'socket');
        $property->setValue($ami, $pair[0]);
        fwrite($pair[1], 'Response: Suc');
        $this->assertSame([], $ami->poll());
        fwrite($pair[1], "cess\r\nActionID: vb-1\r\n\r\nEvent: Hangup\r\nUniqueid: 123\r\n\r\n");
        $frames = $ami->poll();
        $this->assertCount(2, $frames);
        $this->assertSame('Success', $frames[0]['Response']);
        $this->assertSame('Hangup', $frames[1]['Event']);
        fclose($pair[1]);
        $ami->close();
    }
}
