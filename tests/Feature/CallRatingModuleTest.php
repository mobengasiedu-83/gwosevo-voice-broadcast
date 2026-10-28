<?php

namespace Tests\Feature;

use App\Models\BillingException;
use App\Models\CallAttempt;
use App\Models\CallRating;
use App\Models\CallTariff;
use App\Models\CallTimingEvidence;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Client;
use App\Models\User;
use App\Services\Billing\Rating;
use App\Services\Billing\Tariffs;
use App\Services\Broadcast\Engine;
use App\Services\Broadcast\EventProcessor;
use App\Services\Clients\Accounts;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CallRatingModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private Client $client;

    private Campaign $campaign;

    private CallTariff $tariff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00.000000Z'));
        config()->set('call_rating.trusted_sources', ['verified-cel']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $accounts = app(Accounts::class);
        $this->client = $accounts->create($this->admin, 'Rating customer');
        $accounts->status($this->admin, $this->client, 'active', 'Ready');
        $this->member = User::factory()->create();
        $accounts->member($this->admin, $this->client, $this->member, 'owner');
        $this->campaign = Campaign::create(['client_id' => $this->client->id, 'owner_id' => $this->member->id, 'name' => 'Rating campaign', 'purpose' => 'Updates', 'type' => 'one_way', 'status' => 'running']);
        $this->tariff = app(Tariffs::class)->create($this->admin, ['code' => 'test-only', 'version' => 1, 'currency' => 'GHS', 'minor_unit_scale' => 2, 'rate_minor_per_minute' => 30, 'playback_policy' => 'charge_answered'], 'Test-only approved fixture');
        app(Tariffs::class)->assign($this->admin, $this->client, $this->tariff, 'Test-only assignment');
    }

    private function attempt(int $ms = 60001, bool $answered = true, bool $priced = true, ?int $recipientId = null, int $attemptNo = 1, int $endOffsetSeconds = 2): array
    {
        $end = CarbonImmutable::now()->subSeconds($endOffsetSeconds);
        $answer = $end->subMilliseconds($ms);
        $recipient = $recipientId ? CampaignRecipient::findOrFail($recipientId) : CampaignRecipient::create(['campaign_id' => $this->campaign->id, 'status' => 'terminal']);
        $uuid = (string) Str::uuid();
        $attempt = CallAttempt::create([
            'uuid' => $uuid, 'uniqueid' => $uuid, 'action_id' => 'vb-'.$uuid, 'linkedid' => 'linked-'.$uuid,
            'campaign_recipient_id' => $recipient->id, 'attempt_no' => $attemptNo, 'state' => 'terminal', 'outcome' => $answered ? 'completed' : 'busy',
            'sent_at' => $answer->subSeconds(2), 'answered_at' => $answered ? $answer : null, 'ended_at' => $end,
            'playback_completed_at' => $answered ? $end : null,
            'tariff_snapshot' => $priced ? app(Tariffs::class)->snapshot($this->client->id) : null,
        ]);

        return [$attempt, ['attempt_uuid' => $uuid, 'uniqueid' => $uuid, 'linkedid' => $attempt->linkedid, 'disposition' => $answered ? 'ANSWERED' : 'BUSY', 'answered_at' => $answered ? $answer->format('Y-m-d\TH:i:s.u\Z') : null, 'ended_at' => $end->format('Y-m-d\TH:i:s.u\Z'), 'duration_ms' => $answered ? $ms : 0]];
    }

    public function test_exact_minute_boundaries_and_each_retry_are_rated_independently(): void
    {
        foreach ([0 => 1, 1 => 1, 5000 => 1, 59999 => 1, 60000 => 1, 60001 => 2, 60400 => 2, 120000 => 2, 120001 => 3] as $ms => $minutes) {
            [$a, $proof] = $this->attempt($ms);
            $this->assertSame('rated', app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/proof/'.$a->uuid));
            $rating = CallRating::where('call_attempt_id', $a->id)->firstOrFail();
            $this->assertSame($ms, $rating->duration_ms);
            $this->assertSame($minutes, $rating->billable_minutes);
            $this->assertSame($minutes * 30, $rating->amount_minor);
        }
        $sum = 0;
        foreach ([10000, 61000, 121000] as $ms) {
            [$a, $proof] = $this->attempt($ms);
            app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/three-calls');
            $sum += $a->rating->billable_minutes;
        }
        $this->assertSame(6, $sum);
        [$unanswered, $proof] = $this->attempt(0, false, false);
        app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/busy');
        $this->assertSame(0, $unanswered->rating->billable_minutes);
        $this->assertSame(0, $unanswered->rating->amount_minor);
    }

    public function test_replay_cannot_double_rate_or_change_tariff_and_minor_units_are_exact(): void
    {
        [$a, $proof] = $this->attempt();
        $new = app(Tariffs::class)->create($this->admin, ['code' => 'test-only', 'version' => 2, 'currency' => 'GHS', 'minor_unit_scale' => 2, 'rate_minor_per_minute' => 999, 'playback_policy' => 'charge_answered'], 'Test version');
        app(Tariffs::class)->assign($this->admin, $this->client, $new, 'New future price');
        foreach ([1, 2, 3] as $replay) {
            $this->assertSame('rated', app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/replay'));
        }
        $this->assertSame(1, CallTimingEvidence::count());
        $this->assertSame(1, CallRating::count());
        $this->assertSame(60, $a->rating->amount_minor);
        $this->assertSame('0.60', Rating::amount($a->rating));
        $this->assertSame(1, $a->rating->tariff_snapshot['version']);
        $this->expectException(\LogicException::class);
        $a->rating->update(['amount_minor' => 0]);
    }

    public function test_import_precision_and_missing_tariff_are_checked_but_playback_never_blocks_billing(): void
    {
        [$a, $proof] = $this->attempt(60400);
        $seconds = $proof;
        $seconds['answered_at'] = CarbonImmutable::parse($proof['answered_at'])->format('Y-m-d\TH:i:s\Z');
        $this->assertSame('held', app(Rating::class)->import($this->admin, $seconds, 'verified-cel', 'private/integer-billsec'));
        $this->assertDatabaseHas('billing_exceptions', ['call_attempt_id' => $a->id, 'issue' => 'timing_precision', 'resolved_at' => null]);
        $this->assertSame('rated', app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/exact-cel'));
        $this->assertSame(2, $a->rating->billable_minutes);
        [$unpriced, $proof] = $this->attempt(1, true, false);
        $this->assertSame('held', app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/no-historical-price'));
        $this->assertNull($unpriced->rating);
        $this->assertSame(1, $unpriced->timingEvidence->billable_minutes);
        $hold = app(Tariffs::class)->create($this->admin, ['code' => 'hold', 'version' => 1, 'currency' => 'GHS', 'minor_unit_scale' => 2, 'rate_minor_per_minute' => 30, 'playback_policy' => 'hold_on_failure'], 'Test policy');
        app(Tariffs::class)->assign($this->admin, $this->client, $hold, 'Hold incomplete playback');
        [$silent, $proof] = $this->attempt();
        $silent->update(['playback_completed_at' => null, 'outcome' => 'playback_failed']);
        $this->assertSame('rated', app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/silent'));
        $this->assertSame(60, $silent->rating->amount_minor);
    }

    public function test_microseconds_are_not_truncated_at_a_minute_boundary_and_conflicts_never_rewrite(): void
    {
        [$a, $proof] = $this->attempt(60000);
        $proof['answered_at'] = CarbonImmutable::parse($proof['answered_at'])->subMicrosecond()->format('Y-m-d\TH:i:s.u\Z');
        $proof['duration_ms'] = 60001;
        app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/sub-ms');
        $this->assertSame(2, $a->rating->billable_minutes);
        $conflict = $proof;
        $conflict['answered_at'] = CarbonImmutable::parse($proof['answered_at'])->subMinute()->format('Y-m-d\TH:i:s.u\Z');
        unset($conflict['duration_ms']);
        // Keep the contradictory evidence inside the dispatch interval.
        $a->update(['sent_at' => CarbonImmutable::parse($conflict['answered_at'])->subSecond()]);
        $this->assertSame('held', app(Rating::class)->import($this->admin, $conflict, 'verified-cel', 'private/conflict'));
        $this->assertSame(1, CallRating::count());
        $this->assertDatabaseHas('billing_exceptions', ['call_attempt_id' => $a->id, 'issue' => 'timing_conflict']);
        $this->assertSame(60001, $a->rating->duration_ms);
    }

    public function test_unknown_call_requires_fresh_empty_channel_inventory_and_precise_correlation(): void
    {
        [$a, $proof] = $this->attempt(1000);
        $a->update(['ended_at' => null, 'state' => 'unknown', 'answered_at' => null, 'playback_completed_at' => null]);
        DB::table('broadcast_gateway')->where('id', 1)->update(['healthy' => true, 'snapshot_at' => now(), 'channels' => '[]']);
        $this->assertSame('rated', app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/unknown'));
        $this->assertSame('terminal', $a->fresh()->state);
        $this->assertSame(1000, $a->rating->duration_ms);
        [$linked, $proof] = $this->attempt();
        $proof['linkedid'] = 'different-leg';
        $this->assertSame('held', app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/wrong-leg'));
        $this->assertNull($linked->rating);
    }

    public function test_customer_reports_and_exception_access_are_scoped_and_late_answers_raise_holds(): void
    {
        [$a, $proof] = $this->attempt(0, false);
        app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/provider-reference-never-client');
        app(EventProcessor::class)->ingest(['Event' => 'Newstate', 'Uniqueid' => $a->uniqueid, 'ChannelStateDesc' => 'Up', 'Timestamp' => '123']);
        $this->assertDatabaseHas('billing_exceptions', ['call_attempt_id' => $a->id, 'issue' => 'answer_conflict']);
        $this->actingAs($this->member)->get('/broadcast/campaigns/'.$this->campaign->id.'/report')->assertOk()->assertSee('Held for review')->assertSee('0 ms')->assertDontSee('private/provider-reference-never-client');
        $this->get('/billing/reconciliation')->assertForbidden();
        $this->actingAs($this->admin)->get('/billing/reconciliation')->assertOk()->assertSee('answer conflict');
        $other = app(Accounts::class)->create($this->admin, 'Other');
        app(Accounts::class)->status($this->admin, $other, 'active', 'Ready');
        $foreign = User::factory()->create();
        app(Accounts::class)->member($this->admin, $other, $foreign);
        $this->actingAs($foreign)->get('/broadcast/campaigns/'.$this->campaign->id.'/report')->assertNotFound();
        $this->assertSame(0, CallRating::count());
        $this->assertSame(0, BillingException::count());
    }

    public function test_tariffs_and_attempt_snapshots_are_immutable(): void
    {
        [$a] = $this->attempt();
        try {
            $this->tariff->update(['rate_minor_per_minute' => 0]);
            $this->fail('Tariff was changed.');
        } catch (\LogicException) {
        }
        $this->expectException(\LogicException::class);
        $a->update(['tariff_snapshot' => null]);
    }

    public function test_unconfigured_sources_cannot_create_a_rating(): void
    {
        [$a, $proof] = $this->attempt();
        config()->set('call_rating.trusted_sources', []);
        $this->expectException(HttpException::class);
        app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/untrusted');
    }

    public function test_answered_retry_has_its_own_rating_after_an_unanswered_attempt(): void
    {
        [$first, $proof] = $this->attempt(0, false, true, null, 1, 120);
        app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/first-attempt');
        [$retry, $proof] = $this->attempt(61000, true, true, $first->campaign_recipient_id, 2);
        app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/retry-attempt');
        $this->assertSame(0, $first->rating->amount_minor);
        $this->assertSame(60, $retry->rating->amount_minor);
        $this->assertSame(2, CallRating::count());
    }

    public function test_unknown_call_cannot_be_rated_with_stale_or_active_channel_evidence(): void
    {
        [$a, $proof] = $this->attempt(1000);
        $a->update(['state' => 'unknown', 'ended_at' => null]);
        foreach ([['healthy' => false, 'snapshot_at' => now(), 'channels' => '[]'], ['healthy' => true, 'snapshot_at' => now()->subMinute(), 'channels' => '[]'], ['healthy' => true, 'snapshot_at' => now(), 'channels' => json_encode([['uniqueid' => $a->uniqueid, 'linkedid' => $a->linkedid]])]] as $snapshot) {
            DB::table('broadcast_gateway')->where('id', 1)->update($snapshot);
            try {
                app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/unsafe-snapshot');
                $this->fail('An uncertain active call was rated.');
            } catch (ValidationException) {
            }
            $this->assertDatabaseCount('call_ratings', 0);
            $this->assertDatabaseCount('call_timing_evidence', 0);
        }
    }

    public function test_client_cannot_import_evidence_or_assign_a_tariff_even_with_an_internal_role(): void
    {
        [$a, $proof] = $this->attempt();
        $this->member->assignRole('Super Administrator');
        $this->artisan('billing:rate', ['file' => '/tmp/not-used.json', '--actor' => $this->member->id])->assertFailed();
        try {
            app(Tariffs::class)->assign($this->member, $this->client, $this->tariff, 'Forged');
            $this->fail('A client assigned its own price.');
        } catch (AuthorizationException) {
        }
        $this->expectException(AuthorizationException::class);
        app(Rating::class)->import($this->member, $proof, 'verified-cel', 'private/forged');
    }

    public function test_cancelled_before_send_rates_zero_without_inventing_network_timing(): void
    {
        [$a] = $this->attempt(0, false);
        $a->update(['state' => 'ready', 'ended_at' => null, 'sent_at' => null, 'outcome' => null]);
        app(Engine::class)->terminal($a, 'cancelled_before_send');
        app(Engine::class)->terminal($a->fresh(), 'cancelled_before_send');
        $this->assertSame(1, CallRating::count());
        $this->assertSame(0, $a->rating->amount_minor);
        $this->assertSame('application:not_sent', $a->timingEvidence->source);
        $this->assertNull($a->fresh()->answered_at);
    }

    public function test_verified_file_import_replays_safely_through_the_operator_command(): void
    {
        [$a, $proof] = $this->attempt(60001);
        $file = tempnam(sys_get_temp_dir(), 'rating-import-');
        try {
            file_put_contents($file, json_encode([$proof, $proof], JSON_THROW_ON_ERROR));
            $this->artisan('billing:rate', ['file' => $file, '--actor' => $this->admin->id, '--source' => 'verified-cel', '--evidence' => 'private/cli-import'])
                ->expectsOutputToContain('Wallet charges settle once per rated attempt')->assertSuccessful();
            $this->assertSame(1, CallRating::count());
            $this->assertSame(2, $a->rating->billable_minutes);
        } finally {
            unlink($file);
        }
    }

    public function test_late_authoritative_answer_stops_a_queued_retry_without_inventing_playback(): void
    {
        [$a, $proof] = $this->attempt(61000);
        $a->update(['outcome' => 'originate_failed', 'answered_at' => null, 'playback_completed_at' => null]);
        CampaignRecipient::whereKey($a->campaign_recipient_id)->update(['status' => 'pending']);
        $this->assertSame('rated', app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/late-answer'));
        $this->assertSame('answered_unknown', $a->fresh()->outcome);
        $this->assertNull($a->fresh()->playback_completed_at);
        $this->assertSame('terminal', CampaignRecipient::findOrFail($a->campaign_recipient_id)->status);
        $this->assertSame(2, $a->rating->billable_minutes);
    }
}
