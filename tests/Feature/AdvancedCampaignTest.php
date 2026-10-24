<?php

namespace Tests\Feature;

use App\Models\AudioFile;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ClientAccount;
use App\Models\Contact;
use App\Models\IvrFlow;
use App\Models\TrunkProfile;
use App\Models\User;
use App\Services\Broadcast\CampaignPolicy;
use App\Services\Broadcast\Engine;
use App\Services\Broadcast\EventProcessor;
use App\Services\Broadcast\IvrCompiler;
use App\Services\Broadcast\IvrSimulator;
use App\Services\Broadcast\Readiness;
use App\Services\Campaigns\Recurring;
use App\Services\Reports\Analytics;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdvancedCampaignTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    private Campaign $campaign;

    private AudioFile $audio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-24 09:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        config(['voice_broadcast.client_execution_enabled' => false, 'billing.enabled' => false, 'broadcasting_discovery.trunk' => 'advanced-test']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $account = ClientAccount::create(['name' => 'Advanced client', 'created_by' => $this->admin->id]);
        $this->client = User::factory()->create();
        $this->client->forceFill(['client_account_id' => $account->id])->save();
        $this->client->assignRole('Client');
        $contact = Contact::create(['phone_e164' => '+233241234567', 'created_by' => $this->client->id]);
        Storage::disk('local')->put('advanced.wav', 'audio');
        $this->audio = AudioFile::create(['uuid' => (string) Str::uuid(), 'name' => 'Prompt', 'uploaded_by' => $this->client->id, 'status' => 'approved', 'deployed_at' => now(), 'storage_key' => 'advanced.wav', 'checksum' => hash('sha256', 'audio')]);
        TrunkProfile::create(['name' => 'advanced-test', 'caller_id' => '0241234567', 'max_channels' => 10, 'reserved_channels' => 2, 'safe_channels' => 2, 'max_cps' => 1, 'number_format' => 'national', 'active' => true, 'verified_at' => now(), 'evidence' => 'fixture']);
        $this->campaign = Campaign::create(['name' => 'Recurring source', 'purpose' => 'Updates', 'type' => 'one_way', 'owner_id' => $this->client->id, 'status' => 'ready', 'timezone' => 'Africa/Accra', 'configuration' => ['audio_id' => $this->audio->id, 'contact_ids' => [$contact->id], 'timezone' => 'Africa/Accra', 'concurrency' => 1, 'cps' => 1, 'window_start' => '08:00', 'window_end' => '18:00', 'max_attempts' => 3, 'retry_delay_seconds' => 60]]);
    }

    private function schedule(array $extra = []): int
    {
        return app(Recurring::class)->create($this->client, $this->campaign, $extra + ['frequency' => 'daily', 'starts_on' => '2026-10-25', 'ends_on' => '2026-11-01', 'local_time' => '09:00']);
    }

    private function graph(): array
    {
        return ['start' => 'menu', 'nodes' => [
            ['id' => 'menu', 'type' => 'input', 'audio_id' => $this->audio->id, 'branches' => ['1' => 'done', '9' => 'optout'], 'invalid' => 'done', 'timeout' => 'done'],
            ['id' => 'done', 'type' => 'end'], ['id' => 'optout', 'type' => 'opt_out'],
        ]];
    }

    public function test_recurring_slots_are_idempotent_frozen_and_do_not_overlap_pending_reviews(): void
    {
        $id = $this->schedule();
        $this->campaign->update(['name' => 'Changed later']);
        $this->travelTo(CarbonImmutable::parse('2026-10-25 09:00:00'));
        app(Recurring::class)->process();
        app(Recurring::class)->process();
        $this->assertDatabaseCount('campaign_occurrences', 1);
        $child = Campaign::where('source_campaign_id', $this->campaign->id)->firstOrFail();
        $this->assertSame('Recurring source', $child->name);
        $this->assertSame('ready', $child->status);
        $this->assertNull($child->active_version_id);
        $this->assertSame($this->client->client_account_id, $child->client_account_id);
        $this->travelTo(CarbonImmutable::parse('2026-10-26 09:00:00'));
        app(Recurring::class)->process();
        $this->assertDatabaseHas('campaign_occurrences', ['campaign_series_id' => $id, 'status' => 'skipped']);
        $this->assertSame(1, Campaign::where('source_campaign_id', $this->campaign->id)->count());
    }

    public function test_automatic_occurrences_keep_client_execution_gate_and_pause_on_failure(): void
    {
        $id = $this->schedule(['auto_start' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-25 09:00:00'));
        app(Recurring::class)->process();
        $this->assertDatabaseHas('campaign_occurrences', ['campaign_series_id' => $id, 'status' => 'blocked', 'campaign_id' => null]);
        $this->assertDatabaseHas('campaign_series', ['id' => $id, 'active' => false]);
        $this->assertDatabaseCount('campaigns', 1);
        $this->assertDatabaseCount('campaign_runs', 0);
    }

    public function test_automatic_occurrence_creates_a_fresh_launch_snapshot_when_gates_allow_it(): void
    {
        config(['voice_broadcast.client_execution_enabled' => true]);
        $id = $this->schedule(['auto_start' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-25 09:00:00'));
        app(Recurring::class)->process();
        $this->assertDatabaseHas('campaign_occurrences', ['campaign_series_id' => $id, 'status' => 'started']);
        $child = Campaign::where('source_campaign_id', $this->campaign->id)->firstOrFail();
        $this->assertSame('running', $child->status);
        $this->assertNotNull($child->active_version_id);
        $this->assertDatabaseCount('campaign_runs', 1);
        $this->assertDatabaseCount('call_attempts', 0);
        $this->assertNull($this->campaign->fresh()->active_version_id);
    }

    public function test_expired_campaign_excludes_pending_recipients_without_sending_calls(): void
    {
        config(['voice_broadcast.client_execution_enabled' => true]);
        $this->campaign->update(['configuration' => $this->campaign->configuration + ['expires_on' => '2026-10-24']]);
        app(Engine::class)->control($this->client, $this->campaign, 'start');
        $this->travelTo(CarbonImmutable::parse('2026-10-25 09:00:00'));
        app(CampaignPolicy::class)->activateDue();
        $this->assertDatabaseHas('campaign_recipients', ['campaign_id' => $this->campaign->id, 'exclusion_reason' => 'campaign_expired']);
        $this->assertSame('completed', $this->campaign->fresh()->status);
        $this->assertDatabaseCount('call_attempts', 0);
    }

    public function test_excluded_and_missed_occurrences_do_not_backfill_and_stopping_prevents_future_work(): void
    {
        $this->campaign->update(['configuration' => $this->campaign->configuration + ['excluded_dates' => ['2026-10-25']]]);
        $id = $this->schedule();
        $this->travelTo(CarbonImmutable::parse('2026-10-25 09:00:00'));
        app(Recurring::class)->process();
        $this->assertDatabaseHas('campaign_occurrences', ['reason' => 'Excluded date or campaign expiry.']);
        $this->travelTo(CarbonImmutable::parse('2026-10-29 09:00:00'));
        app(Recurring::class)->process();
        $this->assertDatabaseHas('campaign_occurrences', ['reason' => 'Expired or missed slot; no backfill calls.']);
        app(Recurring::class)->stop($this->client, $id);
        $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00:00'));
        app(Recurring::class)->process();
        $this->assertDatabaseCount('campaign_occurrences', 2);
    }

    public function test_monthly_day_is_anchored_after_short_month_and_local_time_survives_dst(): void
    {
        $s = (object) ['frequency' => 'monthly', 'timezone' => 'Africa/Accra', 'anchor_date' => '2027-01-31', 'local_time' => '09:00'];
        $next = app(Recurring::class)->next($s, CarbonImmutable::parse('2027-01-31 09:00:00'));
        $this->assertSame('2027-02-28 09:00', $next->format('Y-m-d H:i'));
        $this->assertSame('2027-03-31 09:00', app(Recurring::class)->next($s, $next)->format('Y-m-d H:i'));
        $s->frequency = 'daily';
        $s->timezone = 'America/New_York';
        $this->assertSame('2027-03-14 13:00', app(Recurring::class)->next($s, CarbonImmutable::parse('2027-03-13 14:00:00'))->format('Y-m-d H:i'));
    }

    public function test_series_routes_enforce_scope_and_render_for_client_and_internal_admin(): void
    {
        $id = $this->schedule();
        $this->actingAs($this->client)->get(route('client.campaigns.series'))->assertOk()->assertSee('Recurring source');
        $other = User::factory()->create();
        $account = ClientAccount::create(['name' => 'Other', 'created_by' => $this->admin->id]);
        $other->forceFill(['client_account_id' => $account->id])->save();
        $other->assignRole('Client');
        $this->actingAs($other)->get(route('client.campaigns.series'))->assertOk()->assertDontSee('Template #'.$this->campaign->id);
        $this->post(route('client.campaigns.series.stop', $id))->assertNotFound();
        $this->get(route('campaign-series'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('campaign-series'))->assertOk()->assertSee('Recurring source');
    }

    public function test_retry_reasons_and_date_rules_are_validated_and_frozen_in_launch_snapshot(): void
    {
        $policy = app(CampaignPolicy::class);
        $this->assertSame([], $policy->advanced(['retry_policy_configured' => 1])['retry_outcomes']);
        $this->assertSame(['busy'], $policy->schedule(['retry_outcomes' => ['busy']])['retry_policy']['outcomes']);
        $rules = $policy->advanced(['excluded_dates_text' => '2026-12-25, 2027-01-01', 'expires_on' => '2027-02-01']);
        $this->assertCount(2, $rules['excluded_dates']);
        $this->expectException(ValidationException::class);
        $policy->advanced(['retry_outcomes' => ['unknown']]);
    }

    public function test_holiday_expiry_and_timezone_block_admission_window(): void
    {
        $r = app(Readiness::class);
        $snapshot = ['timezone' => 'Africa/Accra', 'window_start' => '08:00', 'window_end' => '18:00'];
        $this->assertTrue($r->inWindow($snapshot));
        $this->assertFalse($r->inWindow($snapshot + ['excluded_dates' => ['2026-10-24']]));
        $this->assertFalse($r->inWindow($snapshot + ['expires_on' => '2026-10-23']));
        $this->assertFalse($r->inWindow(array_replace($snapshot, ['timezone' => 'Pacific/Honolulu'])));
    }

    public function test_retry_policy_respects_selected_outcomes_even_for_other_retryable_failures(): void
    {
        config(['voice_broadcast.client_execution_enabled' => true]);
        $this->campaign->update(['configuration' => $this->campaign->configuration + ['retry_outcomes' => ['busy']]]);
        app(Engine::class)->control($this->client, $this->campaign, 'start');
        $this->campaign->refresh();
        $recipient = CampaignRecipient::where('campaign_id', $this->campaign->id)->firstOrFail();
        $a = CallAttempt::create(['uuid' => (string) Str::uuid(), 'campaign_recipient_id' => $recipient->id, 'campaign_version_id' => $this->campaign->active_version_id, 'attempt_no' => 1, 'sent_at' => now()]);
        $this->assertFalse(app(CampaignPolicy::class)->retry($a, 'no_answer'));
        $this->assertTrue(app(CampaignPolicy::class)->retry($a, 'busy'));
        $this->assertFalse(app(CampaignPolicy::class)->retry($a, 'unknown'));
    }

    public function test_simulator_branches_timeout_invalid_and_optout_without_persisting_live_actions(): void
    {
        $simulator = app(IvrSimulator::class);
        $this->assertStringContainsString('simulated opt-out', $simulator->run($this->graph(), ['9'])['trace'][1]['result']);
        $this->assertSame('timeout', $simulator->run($this->graph(), [])['trace'][0]['result']);
        $this->assertSame('invalid input', $simulator->run($this->graph(), ['8'])['trace'][0]['result']);
        $this->assertDatabaseCount('suppression_entries', 0);
        $this->assertDatabaseCount('call_attempts', 0);
        $this->actingAs($this->admin)->postJson(route('interactive.simulate'), ['graph' => $this->graph(), 'inputs' => ['1']])->assertOk()->assertJsonPath('simulation_only', true);
        $this->actingAs($this->client)->postJson(route('interactive.simulate'), ['graph' => $this->graph(), 'inputs' => []])->assertForbidden();
    }

    public function test_new_ivr_telemetry_is_versioned_correlated_and_deduplicated(): void
    {
        $graph = $this->graph();
        $graph['telemetry_version'] = 2;
        $flow = IvrFlow::create(['uuid' => (string) Str::uuid(), 'family_uuid' => (string) Str::uuid(), 'version' => 1, 'name' => 'Flow', 'owner_id' => $this->client->id, 'status' => 'approved', 'graph' => $graph, 'checksum' => hash('sha256', json_encode($graph)), 'deployed_at' => now()]);
        $compiled = app(IvrCompiler::class)->compile($flow);
        $this->assertStringContainsString('VBInputTimeout', $compiled);
        $this->assertStringContainsString('VBInvalidInput', $compiled);
        $legacy = new IvrFlow(['uuid' => (string) Str::uuid(), 'graph' => $this->graph(), 'checksum' => 'legacy']);
        $this->assertStringNotContainsString('VBInputTimeout', app(IvrCompiler::class)->compile($legacy));
        config(['voice_broadcast.client_execution_enabled' => true]);
        $this->campaign->update(['type' => 'interactive', 'configuration' => $this->campaign->configuration + ['ivr_flow_id' => $flow->id]]);
        app(Engine::class)->control($this->client, $this->campaign, 'start');
        $this->campaign->refresh();
        $recipient = CampaignRecipient::where('campaign_id', $this->campaign->id)->firstOrFail();
        $a = CallAttempt::create(['uuid' => (string) Str::uuid(), 'campaign_recipient_id' => $recipient->id, 'campaign_version_id' => $this->campaign->active_version_id, 'attempt_no' => 1, 'state' => 'sent', 'sent_at' => now(), 'uniqueid' => 'advanced.unique']);
        $event = ['Event' => 'UserEvent', 'UserEvent' => 'VBInvalidInput', 'AttemptID' => $a->uuid, 'Uniqueid' => $a->uniqueid, 'NodeID' => 'menu', 'Digit' => 'secret'];
        app(EventProcessor::class)->ingest($event);
        app(EventProcessor::class)->ingest($event);
        $this->assertDatabaseHas('dtmf_responses', ['call_attempt_id' => $a->id, 'response_type' => 'invalid', 'digit' => null]);
        $this->assertDatabaseCount('dtmf_responses', 1);
        $event['UserEvent'] = 'VBInputTimeout';
        $event['Uniqueid'] = 'wrong';
        app(EventProcessor::class)->ingest($event);
        $this->assertDatabaseCount('dtmf_responses', 1);
        $filters = app(Analytics::class)->filters($this->client, ['kind' => 'ivr']);
        $summary = app(Analytics::class)->summary($this->client, $filters);
        $this->assertSame(1, $summary['metrics']['invalid_inputs']);
        $this->assertSame(0, $summary['metrics']['timeouts']);
    }
}
