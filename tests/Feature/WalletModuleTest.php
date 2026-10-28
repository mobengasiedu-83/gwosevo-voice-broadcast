<?php

namespace Tests\Feature;

use App\Models\AudioFile;
use App\Models\AuditLog;
use App\Models\CallAttempt;
use App\Models\CallRating;
use App\Models\CallTariff;
use App\Models\CallTelecomUsage;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignVersion;
use App\Models\Client;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletLedgerEntry;
use App\Models\WalletReservation;
use App\Services\Billing\FinanceReport;
use App\Services\Billing\Rating;
use App\Services\Billing\Tariffs;
use App\Services\Billing\TelecomUsage;
use App\Services\Billing\Wallet;
use App\Services\Billing\WalletDeclined;
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
use LogicException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WalletModuleTest extends TestCase
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
        config()->set('billing.max_call_seconds', 900);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00.000000Z'));
        config()->set('call_rating.trusted_sources', ['verified-cel']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $accounts = app(Accounts::class);
        $this->client = $accounts->create($this->admin, 'Wallet customer');
        $accounts->status($this->admin, $this->client, 'active', 'Ready');
        $this->member = User::factory()->create();
        $accounts->member($this->admin, $this->client, $this->member, 'owner');
        $this->campaign = Campaign::create(['client_id' => $this->client->id, 'owner_id' => $this->member->id, 'name' => 'Wallet campaign', 'purpose' => 'Updates', 'type' => 'one_way', 'status' => 'running']);
        // 30 minor units per minute in GHS: fifteen reserved minutes cost 450 minor units.
        $this->tariff = app(Tariffs::class)->create($this->admin, ['code' => 'wallet-test', 'version' => 1, 'currency' => 'GHS', 'minor_unit_scale' => 2, 'rate_minor_per_minute' => 30, 'playback_policy' => 'charge_answered'], 'Test-only approved fixture');
        app(Tariffs::class)->assign($this->admin, $this->client, $this->tariff, 'Test-only assignment');
    }

    private function wallet(): WalletAccount
    {
        return app(Wallet::class)->open($this->admin, $this->client, 'GHS', 2, 'Approved onboarding');
    }

    private function attempt(int $ms = 60001, bool $answered = true, int $attemptNo = 1, int $endOffsetSeconds = 2, ?int $recordingMs = null): array
    {
        $end = CarbonImmutable::now()->subSeconds($endOffsetSeconds);
        $answer = $end->subMilliseconds($ms);
        $recipient = CampaignRecipient::create(['campaign_id' => $this->campaign->id, 'status' => 'terminal']);
        $uuid = (string) Str::uuid();
        $version = CampaignVersion::create(['campaign_id' => $this->campaign->id, 'version' => max(100000, (int) $this->campaign->versions()->max('version') + 1), 'snapshot' => ['type' => 'interactive', 'broadcast' => ['audio_duration_ms' => $recordingMs ?? max(1, $ms)]], 'approved_by' => $this->admin->id, 'approved_at' => now()]);
        $attempt = CallAttempt::create([
            'uuid' => $uuid, 'uniqueid' => $uuid, 'action_id' => 'vb-'.$uuid, 'linkedid' => 'linked-'.$uuid,
            'campaign_version_id' => $version->id, 'campaign_recipient_id' => $recipient->id, 'attempt_no' => $attemptNo, 'state' => 'terminal', 'outcome' => $answered ? 'completed' : 'busy',
            'sent_at' => $answer->subSeconds(2), 'answered_at' => $answered ? $answer : null, 'ended_at' => $end,
            'playback_completed_at' => $answered ? $end : null,
            'tariff_snapshot' => app(Tariffs::class)->snapshot($this->client->id),
        ]);

        return [$attempt, ['attempt_uuid' => $uuid, 'uniqueid' => $uuid, 'linkedid' => $attempt->linkedid, 'disposition' => $answered ? 'ANSWERED' : 'BUSY', 'answered_at' => $answered ? $answer->format('Y-m-d\TH:i:s.u\Z') : null, 'ended_at' => $end->format('Y-m-d\TH:i:s.u\Z'), 'duration_ms' => $answered ? $ms : 0]];
    }

    public function test_carrier_expense_uses_actual_time_and_its_own_frozen_rate_without_debiting_the_client(): void
    {
        config()->set('telecom', ['rate_per_minute' => '0.30', 'currency' => 'GHS', 'minor_unit_scale' => 2]);
        $wallets = app(Wallet::class);
        $wallet = $this->wallet();
        $wallets->post($this->admin, $wallet, 'funding', '10.00', 'Separate carrier test', 'TEST-CARRIER');
        [$attempt] = $this->attempt(130000, recordingMs: 75000);
        $wallets->reserve($attempt);
        config()->set('telecom.rate_per_minute', '9.00');
        app(Rating::class)->internal($attempt);
        $usage = $attempt->fresh()->telecomUsage;
        $this->assertSame(130000, $usage->duration_ms);
        $this->assertSame(3, $usage->billed_minutes);
        $this->assertSame(180000, $usage->billed_duration_ms);
        $this->assertSame(90, $usage->cost_minor);
        $finance = app(FinanceReport::class);
        $summary = $finance->summary($finance->normalize(['from' => '2026-10-26', 'to' => '2026-10-26']));
        $this->assertEquals(90, $summary['carrier_expenses']->first()->cost_minor);
        $this->assertSame(60, $summary['currencies'][0]['debit_minor']);
        $this->assertSame(60, $attempt->fresh()->rating->amount_minor);
        $this->assertSame(940, $wallet->refresh()->available_minor);
        app(TelecomUsage::class)->record($attempt);
        $this->assertSame(940, $wallet->refresh()->available_minor);
        $this->assertSame(1, CallTelecomUsage::where('call_attempt_id', $attempt->id)->count());
        config()->set('telecom.rate_per_minute', null);
        [$unknown] = $this->attempt(5000, recordingMs: 75000);
        $usage = app(TelecomUsage::class)->record($unknown);
        $this->assertNull($usage->cost_minor);
        $this->assertSame(1, $usage->billed_minutes);
        $this->assertSame(940, $wallet->refresh()->available_minor);
    }

    public function test_full_recording_cost_is_deducted_on_answer_and_hangup_does_not_charge_again(): void
    {
        $wallets = app(Wallet::class);
        $wallet = $this->wallet();
        $wallets->post($this->admin, $wallet, 'funding', '10.00', 'Answer billing test', 'TEST-ANSWER');
        [$attempt] = $this->attempt(0, recordingMs: 62000);
        $attempt->update(['state' => 'active', 'outcome' => null, 'ended_at' => null, 'playback_completed_at' => null, 'answered_at' => now()]);
        $wallets->reserve($attempt);
        $this->assertSame(550, $wallet->refresh()->available_minor);
        $this->assertSame(450, $wallet->reserved_minor);
        $this->assertSame('answered', app(Rating::class)->internal($attempt));
        $this->assertSame(390, $wallet->refresh()->reserved_minor);
        $this->assertSame(940, $wallet->available_minor + $wallet->reserved_minor);
        $this->assertTrue($wallets->verify($wallet)['ok']);
        app(Rating::class)->internal($attempt);
        $this->assertSame(1, WalletLedgerEntry::where('type', 'answer_charge')->count());
        $this->travel(61)->seconds();
        app(Engine::class)->terminal($attempt->fresh(), 'playback_failed');
        $this->assertSame(940, $wallet->refresh()->available_minor);
        $this->assertSame(0, $wallet->reserved_minor);
        $this->assertSame(60, $attempt->fresh()->rating->amount_minor);
        $this->assertTrue($wallets->verify($wallet)['ok']);
        app(Rating::class)->internal($attempt);
        $this->assertSame(940, $wallet->refresh()->available_minor);
    }

    public function test_ami_answer_event_charges_before_hangup_and_no_answer_stays_free(): void
    {
        $wallets = app(Wallet::class);
        $wallet = $this->wallet();
        $wallets->post($this->admin, $wallet, 'funding', '10.00', 'AMI billing test', 'TEST-AMI');
        [$attempt] = $this->attempt(0, false);
        $attempt->update(['ended_at' => null, 'state' => 'active', 'outcome' => null]);
        $wallets->reserve($attempt);
        $frame = ['Event' => 'Newstate', 'Uniqueid' => $attempt->uuid, 'ChannelStateDesc' => 'Up'];
        app(EventProcessor::class)->ingest($frame);
        $this->assertNotNull($attempt->fresh()->answered_at);
        $this->assertNull($attempt->fresh()->ended_at);
        $this->assertSame(970, $wallet->refresh()->available_minor + $wallet->reserved_minor);
        app(EventProcessor::class)->ingest($frame);
        app(EventProcessor::class)->ingest(['Event' => 'Hangup', 'Uniqueid' => $attempt->uuid, 'Cause' => '16']);
        $this->assertSame(970, $wallet->refresh()->available_minor);
        $this->assertSame(30, $attempt->fresh()->rating->amount_minor);
        [$busy] = $this->attempt(0, false);
        $version = CampaignVersion::create(['campaign_id' => $this->campaign->id, 'version' => 1, 'snapshot' => ['broadcast' => ['purpose' => 'Updates', 'retry_policy' => ['max_attempts' => 1]]], 'approved_by' => $this->admin->id, 'approved_at' => now()]);
        $busy->update(['campaign_version_id' => $version->id, 'ended_at' => null, 'state' => 'active', 'outcome' => null]);
        $wallets->reserve($busy);
        app(Engine::class)->terminal($busy, 'busy');
        $this->assertSame(970, $wallet->refresh()->available_minor);
        $this->assertSame(0, $wallet->reserved_minor);
        $this->assertTrue($wallets->verify($wallet)['ok']);
    }

    public function test_answer_charge_shortfall_is_recorded_without_a_negative_or_double_debit(): void
    {
        $wallets = app(Wallet::class);
        $wallet = $this->wallet();
        $wallets->post($this->admin, $wallet, 'funding', '4.50', 'Shortfall test', 'TEST-SHORT');
        [$attempt] = $this->attempt(0);
        $wallets->reserve($attempt);
        $wallets->release($attempt, 'Previously expired');
        $wallets->post($this->admin, $wallet->refresh(), 'adjustment', '-4.40', 'Approved prior debit', 'TEST-SHORT-ADJ');
        app(Rating::class)->internal($attempt);
        $this->assertSame(0, $wallet->refresh()->available_minor);
        $this->assertSame(20, $attempt->fresh()->reservation->shortfall_minor);
        $this->assertSame(10, $attempt->fresh()->reservation->answer_paid_minor);
        $this->assertTrue($wallets->verify($wallet)['ok']);
        app(Rating::class)->internal($attempt);
        $this->assertSame(0, $wallet->refresh()->available_minor);
    }

    public function test_recording_length_controls_the_charge_for_short_and_long_answered_calls(): void
    {
        $wallets = app(Wallet::class);
        $wallet = $this->wallet();
        $wallets->post($this->admin, $wallet, 'funding', '100.00', 'Recording billing test', 'TEST-RECORDING');
        $expected = 10000;
        foreach ([0, 5000, 62000, 180000] as $actualMs) {
            [$attempt] = $this->attempt($actualMs, recordingMs: 62000);
            $wallets->reserve($attempt);
            app(Rating::class)->internal($attempt);
            $expected -= 60;
            $this->assertSame($expected, $wallet->refresh()->available_minor);
            $this->assertSame(62000, $attempt->fresh()->rating->duration_ms);
            $this->assertSame(2, $attempt->fresh()->rating->billable_minutes);
            $this->assertSame('recording_minute_v1', $attempt->fresh()->rating->policy_version);
            $this->assertSame($actualMs, $attempt->fresh()->timingEvidence->duration_ms);
            app(Rating::class)->internal($attempt);
            $this->assertSame($expected, $wallet->refresh()->available_minor);
        }
        foreach ([60000 => 30, 60001 => 60, 120001 => 90] as $recordingMs => $cost) {
            [$attempt] = $this->attempt(0, recordingMs: $recordingMs);
            $wallets->reserve($attempt);
            app(Rating::class)->internal($attempt);
            $expected -= $cost;
            $this->assertSame($expected, $wallet->refresh()->available_minor);
        }
        [$unanswered, $proof] = $this->attempt(0, false, recordingMs: 62000);
        $wallets->reserve($unanswered);
        app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'Unanswered recording test');
        $this->assertSame(0, $unanswered->fresh()->rating->amount_minor);
        $this->assertSame($expected, $wallet->refresh()->available_minor);
        $this->assertTrue($wallets->verify($wallet)['ok']);
    }

    public function test_legacy_recording_is_frozen_only_when_the_approved_checksum_matches(): void
    {
        $audio = AudioFile::create(['client_id' => $this->client->id, 'duration' => 62, 'checksum' => str_repeat('a', 64)]);
        $version = CampaignVersion::create(['campaign_id' => $this->campaign->id, 'version' => 1,
            'snapshot' => ['type' => 'one_way', 'broadcast' => ['audio_id' => $audio->id, 'checksum' => $audio->checksum]],
            'approved_by' => $this->admin->id, 'approved_at' => now()]);
        $recipient = CampaignRecipient::create(['campaign_id' => $this->campaign->id, 'status' => 'terminal']);
        $attempt = CallAttempt::create(['uuid' => (string) Str::uuid(), 'campaign_recipient_id' => $recipient->id, 'campaign_version_id' => $version->id, 'attempt_no' => 1]);
        $this->assertSame(62000, $attempt->fresh()->recordingDurationMs());
        $audio->update(['duration' => 120, 'checksum' => str_repeat('b', 64)]);
        $this->assertSame(62000, $attempt->fresh()->recordingDurationMs());
        $mismatch = CallAttempt::create(['uuid' => (string) Str::uuid(), 'campaign_recipient_id' => $recipient->id, 'campaign_version_id' => $version->id, 'attempt_no' => 2]);
        $this->assertNull($mismatch->fresh()->recordingDurationMs());
        $this->expectException(LogicException::class);
        $attempt->update(['recording_duration_ms' => 120000]);
    }

    public function test_missing_recording_measurement_is_held_instead_of_using_talk_time(): void
    {
        $wallets = app(Wallet::class);
        $wallet = $this->wallet();
        $wallets->post($this->admin, $wallet, 'funding', '10.00', 'Measurement test', 'TEST-MISSING');
        [$attempt] = $this->attempt(62000);
        $version = CampaignVersion::create(['campaign_id' => $this->campaign->id, 'version' => 1, 'snapshot' => ['type' => 'interactive', 'broadcast' => []], 'approved_by' => $this->admin->id, 'approved_at' => now()]);
        $attempt->update(['campaign_version_id' => $version->id]);
        DB::table('call_attempts')->where('id', $attempt->id)->update(['recording_duration_ms' => null]);
        $attempt->refresh();
        $wallets->reserve($attempt);
        $this->assertSame('held', app(Rating::class)->internal($attempt));
        $this->assertDatabaseHas('billing_exceptions', ['call_attempt_id' => $attempt->id, 'issue' => 'recording_duration_missing']);
        $this->assertNull($attempt->fresh()->rating);
        $this->assertSame(0, WalletLedgerEntry::where('type', 'answer_charge')->count());
    }

    public function test_zero_second_answer_and_released_hold_are_still_charged_once(): void
    {
        $wallets = app(Wallet::class);
        $wallet = $this->wallet();
        $wallets->post($this->admin, $wallet, 'funding', '10.00', 'Answer billing test', 'TEST-ZERO');
        [$attempt] = $this->attempt(0);
        $wallets->reserve($attempt);
        $wallets->release($attempt, 'Previously expired');
        $this->assertSame('rated', app(Rating::class)->internal($attempt));
        $this->assertSame(970, $wallet->refresh()->available_minor);
        $this->assertSame(1, $attempt->fresh()->rating->billable_minutes);
        $this->assertSame(1, $attempt->fresh()->rating->duration_ms);
        $this->assertSame(0, $attempt->fresh()->timingEvidence->duration_ms);
        $this->assertTrue($wallets->verify($wallet)['ok']);
        $this->artisan('billing:finalize-answered', ['--apply' => true])->assertSuccessful();
        $this->assertSame(970, $wallet->refresh()->available_minor);
    }

    public function test_cdr_includes_msisdn_time_and_charge_and_is_account_scoped(): void
    {
        [$attempt] = $this->attempt(60001);
        $attempt->recipient->update(['phone_e164' => '+233241234567']);
        app(Rating::class)->internal($attempt);
        $other = app(Accounts::class)->create($this->admin, 'Another CDR client');
        $foreignCampaign = Campaign::create(['client_id' => $other->id, 'owner_id' => $this->admin->id, 'name' => 'Foreign CDR campaign', 'purpose' => 'Updates', 'type' => 'one_way', 'status' => 'running']);
        $foreignRecipient = CampaignRecipient::create(['campaign_id' => $foreignCampaign->id, 'status' => 'terminal', 'phone_e164' => '+233209999999']);
        CallAttempt::create(['uuid' => (string) Str::uuid(), 'campaign_recipient_id' => $foreignRecipient->id, 'attempt_no' => 1, 'state' => 'terminal', 'sent_at' => now(), 'ended_at' => now(), 'outcome' => 'busy']);
        $this->actingAs($this->member)->get('/reports/cdr')->assertOk()->assertSee('+233241234567')->assertSee('60.001 s')
            ->assertSee('Recording length')->assertDontSee('+233209999999')
            // Filter bars use the shared secondary button for reset (docs/ui conventions).
            ->assertSee('<a class="button secondary" href="'.route('cdr.index').'" aria-label="Reset filters">Reset</a>', false)
            // Carrier cost is an internal expense and must never reach a client.
            ->assertDontSee('Carrier billed minutes')->assertDontSee('Carrier cost');
        $this->get('/reports/cdr?to=2026-10-26')->assertOk()->assertSee('+233241234567');
        $this->get('/reports/cdr?answered=0')->assertOk()->assertDontSee('+233241234567');
        $csv = $this->get('/reports/cdr.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('time_spent_ms', $csv);
        $this->assertStringContainsString('recording_duration_ms', $csv);
        $this->assertStringContainsString('recording_minute_v1', $csv);
        $this->assertStringContainsString('+233241234567', $csv);
        $this->assertStringContainsString('60001', $csv);
        $this->assertStringNotContainsString('+233209999999', $csv);
        $this->assertStringNotContainsString('telecom_billed_minutes', $csv);
        $this->assertStringNotContainsString('telecom_cost', $csv);
        $this->get('/reports/cdr?campaign_id='.$foreignCampaign->id)->assertOk()->assertDontSee('+233209999999')->assertDontSee('+233241234567');
    }

    public function test_staff_cdr_shows_the_separate_carrier_expense(): void
    {
        config()->set('telecom', ['rate_per_minute' => '0.07', 'currency' => 'GHS', 'minor_unit_scale' => 2]);
        [$attempt] = $this->attempt(60000, recordingMs: 75000);
        $attempt->recipient->update(['phone_e164' => '+233241234567']);
        app(TelecomUsage::class)->record($attempt);

        $this->actingAs($this->admin)->get('/reports/cdr')->assertOk()
            ->assertSee('Carrier billed minutes')->assertSee('Carrier cost')->assertSee('GHS 0.07');
        $csv = $this->get('/reports/cdr.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('telecom_billed_minutes', $csv);
        $this->assertStringContainsString('telecom_cost', $csv);
        $this->assertStringContainsString('0.07', $csv);
    }

    public function test_client_reservation_requires_a_wallet_even_for_existing_attempts(): void
    {
        [$attempt] = $this->attempt();
        $credit = app(Wallet::class)->admission($this->client->id, $attempt->tariff_snapshot);
        $this->assertFalse($credit['allowed']);
        $this->assertSame('wallet_missing', $credit['reason']);
        try {
            app(Wallet::class)->reserve($attempt);
            $this->fail('Client attempt was admitted without a wallet.');
        } catch (WalletDeclined $exception) {
            $this->assertSame('wallet_missing', $exception->reason);
        }
        $this->assertSame(0, WalletReservation::count());
    }

    public function test_funding_and_adjustments_are_exact_idempotent_and_corrected_by_reversal(): void
    {
        $wallets = app(Wallet::class);
        $wallet = $this->wallet();
        $this->assertSame(0, $wallet->available_minor);

        $funding = $wallets->post($this->admin, $wallet, 'funding', '100.00', 'Approved initial top-up', 'FIN-001', 'test-funding-1');
        $this->assertSame(10000, $wallet->refresh()->available_minor);
        $this->assertSame('100.00', Wallet::amount($wallet->available_minor, $wallet->minor_unit_scale));

        // The same idempotency key never posts twice.
        $replay = $wallets->post($this->admin, $wallet, 'funding', '100.00', 'Approved initial top-up', 'FIN-001', 'test-funding-1');
        $this->assertSame($funding->id, $replay->id);
        $this->assertSame(10000, $wallet->refresh()->available_minor);
        $this->assertSame(1, WalletLedgerEntry::where('wallet_id', $wallet->id)->count());

        $wallets->post($this->admin, $wallet, 'adjustment', '-12.34', 'Reviewed goodwill correction', 'FIN-002', 'test-adjust-1');
        $this->assertSame(8766, $wallet->refresh()->available_minor);

        foreach ([['adjustment', '-87.67'], ['adjustment', '1.001'], ['funding', '-5.00']] as [$kind, $amount]) {
            try {
                $wallets->post($this->admin, $wallet, $kind, $amount, 'Invalid entry', 'FIN-BAD');
                $this->fail('Invalid ledger entry was posted: '.$kind.' '.$amount);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('amount', $e->errors());
            }
        }
        $this->assertSame(8766, $wallet->refresh()->available_minor);

        $wallets->post($this->admin, $wallet, 'funding', '50.00', 'Second approved top-up', 'FIN-003', 'test-funding-2');
        $this->assertSame(13766, $wallet->refresh()->available_minor);

        // Corrections append an opposite entry; the original funding row is untouched.
        $reversal = $wallets->reverse($this->admin, $wallet, $funding, 'Top-up returned to payer', 'FIN-004');
        $this->assertSame('reversal', $reversal->type);
        $this->assertSame($funding->id, $reversal->reversal_of_id);
        $this->assertSame(-10000, $reversal->available_delta_minor);
        $this->assertSame(3766, $wallet->refresh()->available_minor);
        $this->assertSame(10000, WalletLedgerEntry::find($funding->id)->available_delta_minor);

        try {
            $wallets->reverse($this->admin, $wallet, $funding, 'Duplicate reversal', 'FIN-005');
            $this->fail('A funding entry was reversed twice.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already been reversed', collect($e->errors())->flatten()->first());
        }

        $check = $wallets->verify($wallet);
        $this->assertTrue($check['available_match']);
        $this->assertTrue($check['reserved_match']);
        $this->assertTrue($check['held_match']);
        $this->assertSame(3766, $check['ledger_available_minor']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.wallet_funding']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.wallet_reversal']);
    }

    public function test_only_billing_administrators_can_move_credit(): void
    {
        $wallet = $this->wallet();
        $wallet->refresh();

        try {
            app(Wallet::class)->open($this->admin, $this->client, 'GHS', 2, 'Second wallet');
            $this->fail('A duplicate wallet was opened.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('client_id', $e->errors());
        }

        $funding = app(Wallet::class)->post($this->admin, $wallet, 'funding', '10.00', 'Approved top-up', 'FIN-000', 'test-auth');

        try {
            app(Wallet::class)->post($this->member, $wallet, 'funding', '10.00', 'Attempted top-up', 'NONE');
            $this->fail('A client member posted wallet credit.');
        } catch (AuthorizationException $e) {
            $this->assertTrue(true);
        }

        try {
            app(Wallet::class)->reverse($this->member, $wallet, $funding, 'Attempted reversal', 'NONE');
            $this->fail('A client member reversed wallet credit.');
        } catch (AuthorizationException $e) {
            $this->assertTrue(true);
        }

        $wallet->refresh();
        $this->assertSame(1000, $wallet->available_minor);
        $this->assertSame(0, WalletLedgerEntry::where('wallet_id', $wallet->id)->where('type', 'reversal')->count());
        $this->assertSame(1, WalletLedgerEntry::where('wallet_id', $wallet->id)->count());
    }

    public function test_admission_and_reservation_fail_closed_without_enough_credit(): void
    {
        $wallets = app(Wallet::class);
        $wallet = $this->wallet();
        $snapshot = app(Tariffs::class)->snapshot($this->client->id);

        $admission = $wallets->admission($this->client->id, $snapshot);
        $this->assertTrue($admission['applicable']);
        $this->assertFalse($admission['allowed']);
        $this->assertSame('insufficient_credit', $admission['reason']);
        $this->assertSame(450, $admission['exposure_minor']);
        $this->assertSame('Insufficient calling credit for one reserved call. Ask Finance to top up the wallet before launching.', Wallet::message('insufficient_credit'));

        [$first] = $this->attempt();
        try {
            $wallets->reserve($first);
            $this->fail('A reservation was created without available credit.');
        } catch (WalletDeclined $declined) {
            $this->assertSame('insufficient_credit', $declined->reason);
        }
        $this->assertSame(0, WalletReservation::count());
        $this->assertDatabaseHas('user_notifications', ['user_id' => $this->member->id, 'event_key' => 'billing.low_balance']);

        $wallets->post($this->admin, $wallet, 'funding', '4.50', 'Minimum launch credit', 'FIN-010', 'test-minimum');
        $this->assertTrue($wallets->admission($this->client->id, $snapshot)['allowed']);

        $reservation = $wallets->reserve($first);
        $this->assertSame(450, $reservation->amount_minor);
        $this->assertSame('held', $reservation->state);
        $this->assertSame(0, $wallet->refresh()->available_minor);
        $this->assertSame(450, $wallet->reserved_minor);

        // A duplicate hold for the same attempt returns the existing hold, never a second one.
        $this->assertSame($reservation->id, $wallets->reserve($first)->id);
        $this->assertSame(450, $wallet->refresh()->reserved_minor);

        [$second] = $this->attempt(attemptNo: 2);
        try {
            $wallets->reserve($second);
            $this->fail('A second call was reserved with no remaining credit.');
        } catch (WalletDeclined $declined) {
            $this->assertSame('insufficient_credit', $declined->reason);
        }

        // Client billing fails closed even before Finance opens the wallet.
        $missing = $wallets->admission($this->client->id + 999, $snapshot);
        $this->assertTrue($missing['applicable']);
        $this->assertFalse($missing['allowed']);
        $this->assertSame('wallet_missing', $missing['reason']);
        $this->assertTrue($wallets->admission(null, null)['allowed']);
    }

    public function test_campaign_launch_is_blocked_while_the_wallet_is_unfunded(): void
    {
        $this->wallet();
        $snapshot = app(Tariffs::class)->snapshot($this->client->id);
        $this->assertFalse(app(Wallet::class)->admission($this->campaign->client_id, $snapshot)['allowed']);

        app(Wallet::class)->post($this->admin, $this->client->wallet, 'funding', '100.00', 'Funded launch', 'FIN-020', 'test-launch');
        $this->assertTrue(app(Wallet::class)->admission($this->campaign->client_id, $snapshot)['allowed']);
    }

    public function test_answered_call_settles_once_and_unanswered_call_releases_its_hold(): void
    {
        $wallets = app(Wallet::class);
        $wallet = $this->wallet();
        $wallets->post($this->admin, $wallet, 'funding', '10.00', 'Approved top-up', 'FIN-030', 'test-settle');

        [$answered, $proof] = $this->attempt(60001);
        $wallets->reserve($answered);
        $this->assertSame(450, $wallet->refresh()->reserved_minor);
        $this->assertSame(550, $wallet->available_minor);

        $this->assertSame('rated', app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/wallet-settle'));
        $wallet->refresh();
        $this->assertSame(0, $wallet->reserved_minor);
        // 1000 credited, 450 held, 450 released again and 60 charged for two billed minutes.
        $this->assertSame(940, $wallet->available_minor);

        $reservation = WalletReservation::where('call_attempt_id', $answered->id)->firstOrFail();
        $this->assertSame('settled', $reservation->state);
        $this->assertSame(60, $reservation->settled_minor);
        $this->assertSame(0, $reservation->shortfall_minor);

        $settlements = WalletLedgerEntry::where('wallet_id', $wallet->id)->where('type', 'settlement')->get();
        $this->assertCount(1, $settlements);
        $this->assertSame(390, $settlements->first()->available_delta_minor);
        $this->assertSame(-450, $settlements->first()->reserved_delta_minor);

        // Replays heal but never charge twice.
        for ($replay = 0; $replay < 3; $replay++) {
            $this->assertSame('rated', app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/wallet-settle'));
        }
        $this->assertSame(940, $wallet->refresh()->available_minor);
        $this->assertSame(1, WalletLedgerEntry::where('wallet_id', $wallet->id)->where('type', 'settlement')->count());
        $this->assertSame(1, CallRating::where('call_attempt_id', $answered->id)->count());

        // A definitively unanswered call releases the hold and posts no usage charge.
        $version = CampaignVersion::create([
            'campaign_id' => $this->campaign->id, 'version' => 1,
            'snapshot' => ['broadcast' => ['purpose' => 'Updates', 'retry_policy' => ['max_attempts' => 1, 'delay_seconds' => 60]]],
            'approved_by' => $this->admin->id, 'approved_at' => now(),
        ]);
        $recipient = CampaignRecipient::create(['campaign_id' => $this->campaign->id, 'status' => 'reserved']);
        $uuid = (string) Str::uuid();
        $unanswered = CallAttempt::create([
            'uuid' => $uuid, 'uniqueid' => $uuid, 'action_id' => 'vb-'.$uuid, 'campaign_recipient_id' => $recipient->id,
            'campaign_version_id' => $version->id, 'attempt_no' => 1, 'state' => 'ringing', 'sent_at' => now()->subSeconds(30),
            'tariff_snapshot' => app(Tariffs::class)->snapshot($this->client->id),
        ]);
        $wallets->reserve($unanswered);
        $this->assertSame(450, $wallet->refresh()->reserved_minor);
        $this->assertSame(490, $wallet->available_minor);

        app(Engine::class)->terminal($unanswered, 'busy');

        $wallet->refresh();
        $this->assertSame(0, $wallet->reserved_minor);
        $this->assertSame(940, $wallet->available_minor);
        $released = WalletReservation::where('call_attempt_id', $unanswered->id)->firstOrFail();
        $this->assertSame('released', $released->state);
        $this->assertSame('Call ended without an answer: busy', $released->release_reason);
        $this->assertSame(0, CallRating::where('call_attempt_id', $unanswered->id)->count());
        $this->assertSame(0, WalletLedgerEntry::where('call_attempt_id', $unanswered->id)->where('type', 'settlement')->count());
        $this->assertDatabaseHas('billing_exceptions', ['call_attempt_id' => $unanswered->id, 'issue' => 'timing_required']);
        $this->assertTrue($wallets->verify($wallet)['available_match']);
        $this->assertTrue($wallets->verify($wallet)['held_match']);
    }

    public function test_a_rated_charge_above_the_hold_is_recorded_as_a_shortfall_and_never_overdraws(): void
    {
        config()->set('billing.max_call_seconds', 60);
        $wallets = app(Wallet::class);
        $wallet = $this->wallet();
        $wallets->post($this->admin, $wallet, 'funding', '1.00', 'Tiny approved top-up', 'FIN-040', 'test-shortfall');

        [$attempt, $proof] = $this->attempt(120000);
        $wallets->reserve($attempt);
        $this->assertSame(30, $wallet->refresh()->reserved_minor);
        $this->assertSame(70, $wallet->available_minor);

        // Drain the remaining credit while the call is in flight.
        $wallets->post($this->admin, $wallet, 'adjustment', '-0.70', 'Reviewed mid-call debit', 'FIN-041', 'test-drain');
        $this->assertSame(0, $wallet->refresh()->available_minor);

        $this->assertSame('rated', app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/wallet-shortfall'));
        $rating = CallRating::where('call_attempt_id', $attempt->id)->firstOrFail();
        $this->assertSame(60, $rating->amount_minor);

        $wallet->refresh();
        $this->assertSame(0, $wallet->available_minor);
        $this->assertSame(0, $wallet->reserved_minor);

        $reservation = WalletReservation::where('call_attempt_id', $attempt->id)->firstOrFail();
        $this->assertSame('settled', $reservation->state);
        $this->assertSame(60, $reservation->settled_minor);
        $this->assertSame(30, $reservation->shortfall_minor);
        $this->assertDatabaseHas('billing_exceptions', ['call_attempt_id' => $attempt->id, 'issue' => 'wallet_shortfall']);
        $shortfallLog = AuditLog::where('action', 'billing.wallet_shortfall')->firstOrFail();
        $this->assertSame(30, $shortfallLog->details['shortfall_minor']);
        $this->assertSame(30, $shortfallLog->details['paid_minor']);

        // A repeated settlement of the same rating is a no-op.
        $this->assertSame('duplicate', $wallets->settle(CallRating::where('call_attempt_id', $attempt->id)->firstOrFail()));
        $this->assertSame(0, $wallet->refresh()->available_minor);
        $this->assertSame(1, WalletLedgerEntry::where('wallet_id', $wallet->id)->where('type', 'settlement')->count());
    }

    public function test_stale_holds_expire_without_erasing_ledger_history(): void
    {
        $wallets = app(Wallet::class);
        $wallet = $this->wallet();
        $wallets->post($this->admin, $wallet, 'funding', '10.00', 'Initial funding', 'FIN-200', 'expire-funding');
        [$attempt] = $this->attempt(0, false);
        $reservation = $wallets->reserve($attempt);
        $this->assertSame(450, $wallet->refresh()->reserved_minor);

        // A fresh hold keeps funding the call.
        $this->assertSame(0, $wallets->expire(24));
        $this->assertSame('held', $reservation->refresh()->state);

        WalletReservation::whereKey($reservation->id)->update(['reserved_at' => now()->subHours(48)]);
        $summary = $wallets->summary($wallet->refresh());
        $this->assertSame(1, $summary['stale_hold_count']);
        $this->assertSame(450, $summary['held_minor']);
        $this->assertTrue($summary['oldest_hold_at']->lessThan(now()->subDay()));
        $this->assertSame(1, $wallets->expire(24, 500, $this->admin));
        $wallet->refresh();
        $this->assertSame(1000, $wallet->available_minor);
        $this->assertSame(0, $wallet->reserved_minor);
        $reservation->refresh();
        $this->assertSame('released', $reservation->state);
        $this->assertStringContainsString('expired', $reservation->release_reason);
        $release = WalletLedgerEntry::where('wallet_reservation_id', $reservation->id)->where('type', 'release')->firstOrFail();
        $this->assertSame(450, $release->available_delta_minor);
        $this->assertSame($this->admin->id, $release->actor_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.wallet_hold_expired', 'actor_id' => $this->admin->id]);

        // Expiry is idempotent and appends history instead of rewriting it.
        $this->assertSame(0, $wallets->expire(24));
        $this->assertSame(1, WalletLedgerEntry::where('wallet_id', $wallet->id)->where('type', 'release')->count());
        $this->assertSame('billing:release:'.$attempt->id, $release->idempotency_key);

        $this->artisan('billing:wallet-expire', ['--actor' => $this->member->id])->expectsOutputToContain('A billing administrator is required.')->assertFailed();
        $this->artisan('billing:wallet-expire', ['--actor' => $this->admin->id])->expectsOutputToContain('Released 0 stale wallet hold(s)')->assertSuccessful();
    }

    public function test_wallet_console_enforces_permissions_and_tenancy(): void
    {
        $other = app(Accounts::class)->create($this->admin, 'Other customer');
        app(Accounts::class)->status($this->admin, $other, 'active', 'Ready');
        $otherWallet = app(Wallet::class)->open($this->admin, $other, 'GHS', 2, 'Approved onboarding');
        $foreign = app(Wallet::class)->post($this->admin, $otherWallet, 'funding', '9.99', 'Other tenant funding', 'FIN-100', 'test-foreign');

        $this->actingAs($this->member)->get('/billing/wallets')->assertForbidden();
        $this->actingAs($this->member)->get('/billing/wallets/'.$this->client->id)->assertForbidden();
        $this->actingAs($this->member)->post(route('billing.wallets.open', $this->client), ['currency' => 'GHS', 'minor_unit_scale' => 2, 'reason' => 'Attempted self service'])->assertForbidden();

        $this->actingAs($this->admin)->get('/billing/wallets')->assertOk()->assertSee($this->client->name)->assertSee('Open wallet');
        $this->get('/billing/wallets/'.$this->client->id)->assertOk()->assertSee('Open wallet');
        $this->get('/billing/wallets/'.$other->id)->assertOk()->assertSee('9.99');

        $this->post(route('billing.wallets.open', $this->client), ['currency' => 'GHS', 'minor_unit_scale' => 2, 'reason' => 'Approved onboarding'])->assertRedirect();
        $this->get('/billing/wallets/'.$this->client->id)->assertOk()->assertSee('Post approved entry');

        $this->post(route('billing.wallets.post', $this->client), ['kind' => 'funding', 'amount' => '25.00', 'reason' => 'Top-up', 'approval' => ''])->assertSessionHasErrors('approval');
        $this->post(route('billing.wallets.post', $this->client), ['kind' => 'funding', 'amount' => '25.00', 'reason' => 'Top-up', 'approval' => 'FIN-101'])->assertRedirect();
        $wallet = $this->client->fresh()->wallet;
        $this->assertSame(2500, $wallet->available_minor);

        // A wallet entry belonging to another tenant can never be reversed through this client.
        $this->post(route('billing.wallets.reverse', [$this->client, $foreign]), ['reason' => 'Cross-tenant attempt', 'approval' => 'FIN-102'])->assertNotFound();
        $this->assertSame(999, $otherWallet->refresh()->available_minor);

        $own = WalletLedgerEntry::where('wallet_id', $wallet->id)->where('type', 'funding')->firstOrFail();
        $this->post(route('billing.wallets.reverse', [$this->client, $own]), ['reason' => 'Returned to payer', 'approval' => 'FIN-103'])->assertRedirect();
        $this->assertSame(0, $wallet->refresh()->available_minor);
        $this->get('/billing/wallets/'.$this->client->id)->assertOk()->assertSee('Returned to payer');

        // The client portal shows its own credit read-only and never another tenant's activity.
        $this->actingAs($this->member)->get('/dashboard')->assertOk()->assertSee('Calling credit')->assertSee('Available credit')->assertSee('Funding')->assertDontSee('9.99')->assertDontSee('Other customer');
    }

    public function test_client_wallet_page_shows_only_own_balance_holds_and_transactions(): void
    {
        $wallets = app(Wallet::class);
        $wallet = $this->wallet();
        $wallets->post($this->admin, $wallet, 'funding', '10.00', 'Approved client top-up', 'FIN-300', 'client-page-funding');
        [$attempt] = $this->attempt();
        $this->assertNotNull($wallets->reserve($attempt));

        $other = app(Accounts::class)->create($this->admin, 'Other wallet customer');
        app(Accounts::class)->status($this->admin, $other, 'active', 'Ready');
        $otherWallet = $wallets->open($this->admin, $other, 'GHS', 2, 'Approved onboarding');
        $wallets->post($this->admin, $otherWallet, 'funding', '9.99', 'Other tenant funding', 'FIN-301', 'client-page-other');

        $this->actingAs($this->member)->get('/wallet')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Calling credit')
            ->assertSee('10.00')
            ->assertSee('4.50')
            ->assertSee('Calls in flight')
            ->assertSee('Paid from wallet')
            ->assertSee('Approved client top-up')
            ->assertSee('Attempt '.$attempt->id)
            ->assertSee('Reserved maximum exposure')
            ->assertDontSee('Other wallet customer')
            ->assertDontSee('9.99');

        // Type and state filters remain scoped to this tenant's wallet.
        $this->get('/wallet?type=funding')->assertOk()->assertSee('Approved client top-up')->assertDontSee('Reserved maximum exposure');
        $this->get('/wallet?type=reservation')->assertOk()->assertSee('Reserved maximum exposure')->assertDontSee('Approved client top-up');
        $this->get('/wallet?state=held')->assertOk()->assertSee('Attempt '.$attempt->id);
        $this->get('/wallet?state=settled')->assertOk()->assertDontSee('Attempt '.$attempt->id);
        $this->get('/wallet?type=invalid')->assertSessionHasErrors('type');

        // Administrators have no client membership, so this portal route is not theirs.
        $this->actingAs($this->admin)->get('/wallet')->assertForbidden();
    }

    public function test_admin_wallet_console_summarises_coverage_holds_and_filters(): void
    {
        $wallets = app(Wallet::class);
        $wallet = $this->wallet();
        $wallets->post($this->admin, $wallet, 'funding', '10.00', 'Seed funding', 'FIN-310', 'console-funding');
        [$attempt] = $this->attempt();
        $this->assertNotNull($wallets->reserve($attempt));
        $unfunded = app(Accounts::class)->create($this->admin, 'Unfunded customer');
        app(Accounts::class)->status($this->admin, $unfunded, 'active', 'Ready');

        $this->actingAs($this->admin)->get('/billing/wallets/'.$this->client->id)
            ->assertOk()
            ->assertSee('Tariff and one-call coverage')
            ->assertSee('wallet-test')
            ->assertSee('One-call exposure')
            ->assertSee('Full holds covered')
            ->assertSee('Holds older than 24h')
            ->assertSee('Ledger reconciliation')
            ->assertSee('Seed funding')
            ->assertSee('Attempt '.$attempt->id);

        $this->get('/billing/wallets/'.$this->client->id.'?type=funding')->assertOk()->assertSee('Seed funding')->assertDontSee('Reserved maximum exposure');
        $this->get('/billing/wallets/'.$this->client->id.'?type=reservation')->assertOk()->assertSee('Reserved maximum exposure')->assertDontSee('Seed funding');
        $this->get('/billing/wallets/'.$this->client->id.'?state=held')->assertOk()->assertSee('Attempt '.$attempt->id);
        $this->get('/billing/wallets/'.$this->client->id.'?state=settled')->assertOk()->assertDontSee('Attempt '.$attempt->id);

        $this->get('/billing/wallets?state=funded')->assertOk()->assertSee('Wallet customer')->assertDontSee('Unfunded customer');
        $this->get('/billing/wallets?state=unfunded')->assertOk()->assertSee('Unfunded customer')->assertDontSee('Wallet customer');
        $this->get('/billing/wallets?state=held')->assertOk()->assertSee('Wallet customer')->assertDontSee('Unfunded customer')->assertSee('Held credit');
    }

    public function test_admin_client_finance_tab_requires_billing_permission_and_shows_wallet(): void
    {
        $wallet = $this->wallet();
        app(Wallet::class)->post($this->admin, $wallet, 'funding', '25.00', 'Finance tab funding', 'FIN-320', 'finance-tab');

        $this->actingAs($this->admin)->get('/clients/'.$this->client->id.'?tab=finance')
            ->assertOk()
            ->assertSee('Tariff and one-call coverage')
            ->assertSee('wallet-test')
            ->assertSee('25.00')
            ->assertSee('Finance tab funding')
            ->assertSee('Ledger reconciliation passed')
            ->assertSee('Manage wallet, ledger and reservations');

        // A manager with only clients.manage sees the tab but no financial detail.
        $role = Role::findOrCreate('Client Manager', 'web');
        $role->syncPermissions(['clients.manage']);
        $manager = User::factory()->create();
        $manager->assignRole($role);
        $this->actingAs($manager)->get('/clients/'.$this->client->id.'?tab=finance')
            ->assertOk()
            ->assertSee('billing.manage')
            ->assertDontSee('Finance tab funding')
            ->assertDontSee('wallet-test');

        $this->actingAs($this->member)->get('/clients/'.$this->client->id.'?tab=finance')->assertForbidden();
    }

    public function test_wallet_currency_must_match_the_assigned_tariff(): void
    {
        $wallets = app(Wallet::class);
        try {
            $wallets->open($this->admin, $this->client, 'USD', 2, 'Mismatched wallet');
            $this->fail('A wallet opened in a currency that does not match the assigned tariff.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('currency', $e->errors());
            $this->assertStringContainsString('must match the assigned call tariff', collect($e->errors())->flatten()->first());
        }
        $this->assertSame(0, WalletAccount::where('client_id', $this->client->id)->count());

        $wallet = $wallets->open($this->admin, $this->client, 'GHS', 2, 'Matching wallet');
        $this->assertSame('GHS', $wallet->currency);
        $this->assertSame(2, $wallet->minor_unit_scale);
    }

    public function test_wallet_verification_detects_ledger_chain_and_lifecycle_tampering(): void
    {
        $wallets = app(Wallet::class);
        $wallet = $this->wallet();
        $wallets->post($this->admin, $wallet, 'funding', '10.00', 'Verify funding', 'FIN-330', 'verify-funding');
        [$attempt, $proof] = $this->attempt(60001);
        $wallets->reserve($attempt);
        $this->assertSame('rated', app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/wallet-verify'));

        $check = $wallets->verify($wallet->refresh());
        $this->assertTrue($check['ok']);
        $this->assertTrue($check['chain_ok']);
        $this->assertSame(0, $check['reservation_entry_missing']);
        $this->assertSame(0, $check['release_entry_missing']);
        $this->assertSame(0, $check['settlement_entry_missing']);
        $this->assertTrue($check['settlement_amounts_match']);
        $this->assertSame(60, $check['settlement_paid_minor']);
        $this->assertSame(60, $check['settled_payable_minor']);

        // Simulate external corruption of a historical "balance after" snapshot.
        $settlement = WalletLedgerEntry::where('wallet_id', $wallet->id)->where('type', 'settlement')->firstOrFail();
        DB::table('wallet_ledger_entries')->where('id', $settlement->id)
            ->update(['available_after_minor' => $settlement->available_after_minor + 1]);
        $broken = $wallets->verify($wallet->refresh());
        $this->assertFalse($broken['chain_ok']);
        $this->assertFalse($broken['ok']);
        $this->assertStringContainsString('do not follow', implode(' ', $broken['issues']));

        // Restore the snapshot, then simulate a missing settlement close.
        DB::table('wallet_ledger_entries')->where('id', $settlement->id)
            ->update(['available_after_minor' => $settlement->available_after_minor]);
        DB::table('wallet_ledger_entries')->where('id', $settlement->id)->delete();
        $missing = $wallets->verify($wallet->refresh());
        $this->assertSame(1, $missing['settlement_entry_missing']);
        $this->assertFalse($missing['settlement_amounts_match']);
        $this->assertFalse($missing['ok']);
    }

    public function test_wallet_denomination_cannot_change_after_ledger_history(): void
    {
        $wallet = $this->wallet();
        app(Wallet::class)->post($this->admin, $wallet, 'funding', '1.00', 'Denomination guard', 'FIN-340', 'denomination');

        foreach (['currency' => 'USD', 'minor_unit_scale' => 3] as $field => $value) {
            try {
                $wallet->{$field} = $value;
                $wallet->save();
                $this->fail('Wallet '.$field.' changed after ledger history existed.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot change', $e->getMessage());
            }
            $wallet->refresh();
        }

        $other = app(Accounts::class)->create($this->admin, 'Denomination target');
        try {
            $wallet->client_id = $other->id;
            $wallet->save();
            $this->fail('Wallet ownership changed after ledger history existed.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('cannot change', $e->getMessage());
        }
        $this->assertSame($this->client->id, $wallet->refresh()->client_id);
    }

    public function test_affordability_reports_full_hold_coverage_and_low_credit(): void
    {
        $wallets = app(Wallet::class);
        $wallet = $this->wallet();
        $tariff = app(Tariffs::class)->snapshot($this->client->id);

        $empty = $wallets->affordability($wallet, $tariff);
        $this->assertSame(450, $empty['exposure_minor']);
        $this->assertSame(0, $empty['covered_calls']);
        $this->assertTrue($empty['low']);
        $this->assertSame('insufficient_credit', $empty['reason']);
        $this->assertStringContainsString('below the maximum cost', Wallet::clientMessage($empty['reason']));
        $this->assertStringContainsString('not assigned', Wallet::clientMessage('tariff_missing'));
        $this->assertStringContainsString('does not match', Wallet::clientMessage('currency_mismatch'));

        $wallets->post($this->admin, $wallet, 'funding', '4.49', 'Below one hold', 'FIN-350', 'afford-1');
        $this->assertTrue($wallets->affordability($wallet->refresh(), $tariff)['low']);

        $wallets->post($this->admin, $wallet, 'funding', '0.01', 'Exactly one hold', 'FIN-351', 'afford-2');
        $covered = $wallets->affordability($wallet->refresh(), $tariff);
        $this->assertSame(1, $covered['covered_calls']);
        $this->assertFalse($covered['low']);
        $this->assertNull($covered['reason']);

        $missing = $wallets->affordability($wallet->refresh(), null);
        $this->assertSame('tariff_missing', $missing['reason']);
        $this->assertTrue($missing['low']);
        $this->assertSame(0, $missing['covered_calls']);
    }
}
