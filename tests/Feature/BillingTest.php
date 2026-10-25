<?php

namespace Tests\Feature;

use App\Models\AudioFile;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\ClientAccount;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\RateVersion;
use App\Models\TrunkProfile;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Billing\Ledger;
use App\Services\Broadcast\AmiClient;
use App\Services\Broadcast\Engine;
use App\Services\Broadcast\EventProcessor;
use App\Services\Broadcast\Gateway;
use App\Services\Clients\Campaigns;
use Database\Seeders\DefaultRatePlanSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $client;

    private User $other;

    private Wallet $wallet;

    private Ledger $ledger;

    private ContactGroup $group;

    private AudioFile $audio;

    private Gateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config()->set('billing.enabled', true);
        config()->set('voice_broadcast.client_execution_enabled', true);
        config()->set('voice_broadcast.enabled', true);
        config()->set('voice_broadcast.global_channels', 5);
        config()->set('voice_broadcast.global_cps', 5);
        config()->set('broadcasting_discovery.trunk', 'billing-test-trunk');
        Storage::fake('local');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        foreach (['client', 'other'] as $name) {
            $account = ClientAccount::create(['name' => $name, 'created_by' => $this->admin->id]);
            $this->$name = User::factory()->create();
            $this->$name->forceFill(['client_account_id' => $account->id])->save();
            $this->$name->assignRole('Client');
        }
        $this->ledger = app(Ledger::class);
        $this->seed(DefaultRatePlanSeeder::class);
        $this->wallet = $this->ledger->assign($this->admin, ClientAccount::find($this->client->client_account_id), RateVersion::firstOrFail(), 'Agreed client price');
        $this->group = ContactGroup::create(['name' => 'Audience', 'owner_id' => $this->client->id]);
        for ($i = 0; $i < 2; $i++) {
            $contact = Contact::create(['name' => 'Recipient '.$i, 'phone_e164' => '+23324123456'.$i, 'created_by' => $this->client->id]);
            $this->group->contacts()->attach($contact);
        }
        Storage::disk('local')->put('billing.wav', 'approved fixture');
        $this->audio = AudioFile::create(['uuid' => (string) Str::uuid(), 'name' => 'Billing message', 'uploaded_by' => $this->client->id, 'status' => 'approved', 'duration' => 60.001, 'approved_at' => now(), 'approved_by' => $this->admin->id, 'deployed_at' => now(), 'storage_key' => 'billing.wav', 'checksum' => hash('sha256', 'approved fixture')]);
        TrunkProfile::create(['name' => 'billing-test-trunk', 'caller_id' => '0241234567', 'max_channels' => 20, 'reserved_channels' => 2, 'safe_channels' => 10, 'max_cps' => 5, 'number_format' => 'national', 'active' => true, 'verified_at' => now(), 'verified_by' => $this->admin->id, 'evidence' => 'test fixture']);
        $this->gateway = app(Gateway::class);
        DB::table('broadcast_gateway')->where('id', 1)->update(['owner' => $this->gateway->owner, 'healthy' => true, 'heartbeat_at' => now(), 'snapshot_at' => now(), 'channels' => '[]']);
    }

    private function fund(int $amount = 100): void
    {
        $this->ledger->adjust($this->admin, $this->wallet, $amount, 'credit-'.Str::uuid(), 'Verified manual credit evidence');
    }

    private function campaign(bool $start = true): Campaign
    {
        $campaign = app(Campaigns::class)->save($this->client, null, ['name' => 'Billing campaign', 'purpose' => 'Updates', 'source_group_id' => $this->group->id, 'audio_id' => $this->audio->id, 'timezone' => 'Africa/Accra', 'window_start' => '00:00', 'window_end' => '23:59', 'max_attempts' => 2, 'retry_delay_seconds' => 60]);
        if ($start) {
            app(Engine::class)->control($this->client, $campaign, 'start');
        }

        return $campaign->fresh();
    }

    private function reserve(): ?CallAttempt
    {
        return DB::transaction(function () {
            app(Engine::class)->mutex();

            return app(Engine::class)->reserve($this->gateway->owner);
        });
    }

    private function terminal(CallAttempt $attempt, bool $answered, string $outcome = 'completed'): void
    {
        DB::transaction(function () use ($attempt, $answered, $outcome) {
            app(Engine::class)->mutex();
            $attempt->update(['sent_at' => now()->subSeconds(10), 'answered_at' => $answered ? now()->subSeconds(9) : null]);
            app(Engine::class)->terminal($attempt, $outcome);
        });
    }

    public function test_confirmed_price_precision_rounding_and_no_seeded_credit_or_assignment(): void
    {
        $rate = RateVersion::firstOrFail();
        $this->assertSame('GHS', $rate->currency);
        $this->assertSame(3, (int) $rate->minor_digits);
        $this->assertSame(3, (int) $rate->rate_minor_per_minute);
        $this->assertSame('0.003', Ledger::format(3, 3));
        $this->assertSame('-0.006', Ledger::format(-6, 3));
        $this->assertSame('12', Ledger::format(12, 0));
        $this->assertSame(0, (int) $this->wallet->balance_minor);
        $this->assertSame(1, Wallet::count());
        $this->seed(DefaultRatePlanSeeder::class);
        $this->assertSame(1, RateVersion::count());
        $this->fund();
        foreach ([[0.1, 1], [60, 1], [60.001, 2], [120, 2], [120.001, 3]] as [$seconds, $minutes]) {
            $this->audio->update(['duration' => $seconds]);
            $quote = $this->ledger->quote($this->campaign(false));
            $this->assertSame($minutes * 3, $quote['call_charge_minor']);
        }
    }

    public function test_launch_requires_price_and_affordability_and_snapshot_keeps_original_rate(): void
    {
        $campaign = $this->campaign(false);
        try {
            app(Engine::class)->control($this->client, $campaign, 'start');
            $this->fail('Unfunded launch succeeded.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('billing', $e->errors());
        }
        $this->assertSame(0, $campaign->versions()->count());
        $this->fund();
        app(Engine::class)->control($this->client, $campaign, 'start');
        $snapshot = $campaign->fresh()->versions()->first()->snapshot['broadcast'];
        $this->assertSame(6, $snapshot['billing']['call_charge_minor']);
        $new = $this->ledger->price($this->admin, ['plan_code' => 'ghs-recording', 'currency' => 'GHS', 'minor_digits' => 3, 'rate_minor_per_minute' => 20, 'evidence' => 'New future price']);
        $this->ledger->assign($this->admin, ClientAccount::find($this->client->client_account_id), $new, 'New future price');
        $attempt = $this->reserve();
        $this->terminal($attempt, true);
        $this->assertSame(-6, (int) WalletLedgerEntry::where('kind', 'call_charge')->firstOrFail()->amount_minor);
        $this->assertSame(40, $this->ledger->quote($this->campaign(false))['call_charge_minor']);
    }

    public function test_competing_campaigns_and_duplicate_reservation_cannot_overspend_available_credit(): void
    {
        $this->fund(6);
        $a = $this->campaign();
        $this->campaign();
        $attempt = $this->reserve();
        $this->assertNotNull($attempt);
        $this->assertSame(6, (int) $this->wallet->fresh()->reserved_minor);
        $policy = $a->versions()->first()->snapshot['broadcast'];
        $this->assertTrue($this->ledger->reserve($attempt, $a, $policy));
        DB::table('campaigns')->update(['last_dispatched_at' => now()->subSeconds(10)]);
        $this->assertNull($this->reserve());
        $this->assertSame(1, CallAttempt::count());
        $this->assertSame(1, DB::table('wallet_reservations')->count());
        $this->terminal($attempt, false, 'busy');
        $this->assertSame(0, (int) $this->wallet->fresh()->reserved_minor);
        $this->assertSame(6, (int) $this->wallet->fresh()->balance_minor);
        $this->assertNotNull($this->reserve());
    }

    public function test_answered_failed_playback_is_charged_once_and_can_receive_a_bounded_audited_refund(): void
    {
        $this->fund(100);
        $this->campaign();
        $attempt = $this->reserve();
        $this->terminal($attempt, true, 'playback_failed');
        $this->ledger->settle($attempt);
        $charge = WalletLedgerEntry::where('kind', 'call_charge')->firstOrFail();
        $this->assertSame(94, (int) $this->wallet->fresh()->balance_minor);
        $this->assertSame(0, (int) $this->wallet->fresh()->reserved_minor);
        $this->assertSame(1, WalletLedgerEntry::where('kind', 'call_charge')->count());
        $refund = $this->ledger->adjust($this->admin, $this->wallet, 6, 'refund-00000001', 'Audible playback dispute credit', $charge->id);
        $replay = $this->ledger->adjust($this->admin, $this->wallet, 6, 'refund-00000001', 'Audible playback dispute credit', $charge->id);
        $this->assertSame($refund->id, $replay->id);
        $this->assertSame(100, (int) $this->wallet->fresh()->balance_minor);
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.refund', 'entity_id' => $this->wallet->id]);
        $this->expectException(ValidationException::class);
        $this->ledger->adjust($this->admin, $this->wallet, 1, 'refund-00000002', 'Excess refund', $charge->id);
    }

    public function test_duplicate_answer_and_hangup_events_settle_once_and_unanswered_calls_are_free(): void
    {
        $this->fund();
        $this->campaign();
        $attempt = $this->reserve();
        $attempt->update(['sent_at' => now(), 'state' => 'sent']);
        $events = app(EventProcessor::class);
        $answer = ['Event' => 'Newstate', 'Uniqueid' => $attempt->uuid, 'ChannelStateDesc' => 'Up'];
        $hangup = ['Event' => 'Hangup', 'Uniqueid' => $attempt->uuid, 'Cause' => '16'];
        $events->ingest($answer);
        $events->ingest($answer);
        $events->ingest($hangup);
        $events->ingest($hangup);
        $this->assertSame(1, WalletLedgerEntry::where('kind', 'call_charge')->count());
        $this->assertSame(94, (int) $this->wallet->fresh()->balance_minor);
        DB::table('campaigns')->update(['last_dispatched_at' => now()->subSeconds(10)]);
        $free = $this->reserve();
        $this->assertNotNull($free);
        $this->terminal($free, false, 'no_answer');
        $this->assertSame(94, (int) $this->wallet->fresh()->balance_minor);
        $this->assertTrue($this->ledger->reconciliation($this->wallet->fresh())['matches']);
    }

    public function test_uncertain_calls_hold_credit_and_late_answer_after_release_requires_review(): void
    {
        $this->fund();
        $this->campaign();
        $attempt = $this->reserve();
        $attempt->update(['sent_at' => now(), 'state' => 'unknown', 'outcome' => 'reconciling']);
        $this->ledger->settle($attempt);
        $r = DB::table('wallet_reservations')->where('call_attempt_id', $attempt->id)->first();
        $this->assertSame('review', $r->status);
        $this->assertSame(6, (int) $r->held_minor);
        $this->assertSame(100, (int) $this->wallet->fresh()->balance_minor);
        try {
            $this->ledger->resolve($this->admin, $r->id, 'release', 'No verified end yet');
            $this->fail('An uncertain live attempt was released.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('billing', $e->errors());
        }
        $this->terminal($attempt->fresh(), false, 'no_answer');
        $this->assertSame('released', DB::table('wallet_reservations')->find($r->id)->status);
        app(EventProcessor::class)->ingest(['Event' => 'Newstate', 'Uniqueid' => $attempt->uuid, 'ChannelStateDesc' => 'Up']);
        $this->assertSame('review', DB::table('wallet_reservations')->find($r->id)->status);
        $this->assertSame(0, (int) $this->wallet->fresh()->reserved_minor);
        $this->assertSame(100, (int) $this->wallet->fresh()->balance_minor);
        $this->ledger->resolve($this->admin, $r->id, 'charge', 'Verified late correlated answer');
        $this->assertSame(94, (int) $this->wallet->fresh()->balance_minor);
    }

    public function test_final_gateway_send_requires_funded_reservation_and_disabling_admission_still_settles_sent_calls(): void
    {
        $this->fund();
        $this->campaign();
        $attempt = $this->reserve();
        config()->set('billing.enabled', false);
        $ami = new class extends AmiClient
        {
            public array $sent = [];

            public function send(array $headers): void
            {
                $this->sent[] = $headers;
            }
        };
        $this->gateway->authorized($ami, $attempt->uuid, true);
        $this->assertSame([], $ami->sent);
        $this->assertSame('billing_blocked', $attempt->fresh()->outcome);
        $this->assertSame(0, (int) $this->wallet->fresh()->reserved_minor);
        config()->set('billing.enabled', true);
        DB::table('campaigns')->update(['last_dispatched_at' => now()->subSeconds(10)]);
        $sent = $this->reserve();
        $this->assertNotNull($sent);
        $sent->update(['sent_at' => now(), 'answered_at' => now()]);
        config()->set('billing.enabled', false);
        $this->terminal($sent, true);
        $this->assertSame(94, (int) $this->wallet->fresh()->balance_minor);
    }

    public function test_manual_adjustment_idempotency_reserved_credit_protection_and_exact_decimal_ui(): void
    {
        $this->fund(6);
        $this->campaign();
        $this->reserve();
        try {
            $this->ledger->adjust($this->admin, $this->wallet, -1, 'debit-00000001', 'Cannot take held funds');
            $this->fail('Held funds were consumed.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('billing', $e->errors());
        }
        $url = route('billing.adjust', $this->wallet);
        $this->actingAs($this->admin)->post($url, ['amount' => '0.003', 'reference' => 'credit-exact-001', 'reason' => 'Exact verified credit'])->assertRedirect();
        $this->post($url, ['amount' => '0.003', 'reference' => 'credit-exact-001', 'reason' => 'Exact verified credit'])->assertRedirect();
        $this->assertSame(9, (int) $this->wallet->fresh()->balance_minor);
        $this->post($url, ['amount' => '0.004', 'reference' => 'credit-exact-001', 'reason' => 'Exact verified credit'])->assertSessionHasErrors('billing');
        $this->post($url, ['amount' => '0.0003', 'reference' => 'credit-exact-002', 'reason' => 'Must not round'])->assertSessionHasErrors('amount');
        $this->assertSame(9, (int) $this->wallet->fresh()->balance_minor);
    }

    public function test_client_wallet_is_scoped_and_internal_financial_actions_are_forbidden(): void
    {
        $this->fund();
        $otherWallet = $this->ledger->assign($this->admin, ClientAccount::find($this->other->client_account_id), RateVersion::first(), 'Other account price');
        $this->ledger->adjust($this->admin, $otherWallet, 500, 'other-credit-0001', 'OTHER PRIVATE EVIDENCE');
        $this->actingAs($this->client)->get('/client/wallet')->assertOk()->assertSee('0.100')->assertSee('0.003')->assertDontSee('OTHER PRIVATE EVIDENCE');
        $this->assertSame(1, Wallet::count());
        $this->assertSame(1, WalletLedgerEntry::count());
        $this->get(route('billing.wallet', $otherWallet))->assertNotFound();
        $this->post(route('billing.adjust', $this->wallet), ['amount' => '1.000', 'reference' => 'bad-client-0001', 'reason' => 'Unverified'])->assertForbidden();
        $this->get('/billing')->assertForbidden();
        $this->actingAs($this->admin)->get('/billing')->assertOk()->assertSee('Wallets and pricing');
        $this->get(route('billing.wallet', $this->wallet))->assertOk()->assertSee('Balances match');
    }

    public function test_reconciliation_detects_corrupted_cache_and_immutable_rows_reject_edits(): void
    {
        $this->fund();
        $this->artisan('billing:reconcile')->expectsOutputToContain('MATCH')->assertSuccessful();
        DB::table('wallets')->where('id', $this->wallet->id)->update(['balance_minor' => 999]);
        $this->assertFalse($this->ledger->reconciliation($this->wallet->fresh())['matches']);
        $this->artisan('billing:reconcile')->expectsOutputToContain('MISMATCH')->assertFailed();
        try {
            $this->ledger->quote($this->campaign(false));
            $this->fail('An inconsistent wallet passed admission.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('billing', $e->errors());
        }
        try {
            WalletLedgerEntry::firstOrFail()->update(['amount_minor' => 999]);
            $this->fail('Ledger mutation succeeded.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('cannot be changed', $e->getMessage());
        }
        $this->expectException(\LogicException::class);
        RateVersion::firstOrFail()->delete();
    }

    public function test_database_prevents_direct_ledger_rewrites_and_price_history_deletion(): void
    {
        $this->fund();
        try {
            DB::table('wallet_ledger_entries')->update(['amount_minor' => 900]);
            $this->fail('Direct financial history rewrite succeeded.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
        $this->expectException(QueryException::class);
        DB::table('rate_versions')->delete();
    }

    public function test_configured_wallet_cannot_bypass_billing_by_disabling_the_flag(): void
    {
        $this->fund();
        $campaign = $this->campaign(false);
        config()->set('billing.enabled', false);
        $this->expectException(ValidationException::class);
        app(Engine::class)->control($this->client, $campaign, 'start');
    }

    public function test_unsent_authorization_failure_releases_funds_and_does_not_debit(): void
    {
        $this->fund();
        $this->campaign();
        $attempt = $this->reserve();
        $ami = new class extends AmiClient
        {
            public function send(array $headers): void
            {
                throw new \RuntimeException('No origination permitted');
            }
        };
        $this->gateway->authorized($ami, $attempt->uuid, false);
        $this->assertSame('authorization_failed', $attempt->fresh()->outcome);
        $this->assertSame(100, (int) $this->wallet->fresh()->balance_minor);
        $this->assertSame(0, (int) $this->wallet->fresh()->reserved_minor);
        $this->assertSame(0, WalletLedgerEntry::where('kind', 'call_charge')->count());
    }

    public function test_finance_role_and_missing_wallet_views_have_explicit_access_boundaries(): void
    {
        $finance = User::factory()->create();
        $finance->assignRole('Billing Administrator');
        $this->actingAs($finance)->get('/billing')->assertOk();
        $this->assertFalse($finance->can('telephony.manage'));
        $this->assertFalse($finance->can('campaigns.approve'));
        $this->actingAs($this->other)->get('/client/wallet')->assertOk()->assertSee('Wallet not configured');
        $this->actingAs($this->admin)->get('/client/wallet')->assertForbidden();
    }

    private function assignPolicy(array $overrides): RateVersion
    {
        $rate = $this->ledger->price($this->admin, array_replace(['plan_code' => 'custom-policy', 'currency' => 'GHS', 'minor_digits' => 3, 'rate_minor_per_minute' => 3, 'evidence' => 'Administrator approved policy'], $overrides));
        $this->ledger->assign($this->admin, ClientAccount::find($this->client->client_account_id), $rate, 'Use custom policy');

        return $rate;
    }

    public function test_superadmin_and_billing_admin_can_configure_versioned_policy_through_the_ui(): void
    {
        $finance = User::factory()->create();
        $finance->assignRole('Billing Administrator');
        foreach ([$this->admin, $finance] as $actor) {
            $this->actingAs($actor)->get('/billing')->assertOk()->assertSee('Configure billing policy')->assertSee('Verified actual answered duration');
            $this->post(route('billing.price'), ['plan_code' => 'configurable', 'currency' => 'GHS', 'minor_digits' => 3, 'amount' => '0.300', 'duration_basis' => 'answered', 'increment_seconds' => 6, 'playback_failure_policy' => 'review', 'reservation_seconds' => 300, 'reason' => 'Approved custom billing terms'])->assertRedirect()->assertSessionHasNoErrors();
        }
        $versions = RateVersion::where('plan_code', 'configurable')->orderBy('version')->get();
        $this->assertCount(2, $versions);
        $this->assertSame([1, 2], $versions->pluck('version')->all());
        $this->assertSame('answered', $versions[1]->duration_basis);
        $this->assertSame(6, (int) $versions[1]->increment_seconds);
        $this->assertSame('review', $versions[1]->playback_failure_policy);
        $this->assertSame(300, (int) $versions[1]->rate_minor_per_minute);
        $this->get(route('billing.index', ['version' => $versions[0]->id]))->assertOk()->assertSee('value="0.300"', false)->assertSee('selected', false);
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.price_version_created', 'entity_id' => $versions[1]->id]);
        $this->actingAs($this->client)->post(route('billing.price'), ['plan_code' => 'client-policy'])->assertForbidden();
    }

    public function test_invalid_policy_values_are_rejected_without_creating_versions(): void
    {
        $this->actingAs($this->admin);
        $base = ['plan_code' => 'invalid', 'amount' => '0.003', 'reason' => 'Validation test'];
        foreach ([['duration_basis' => 'ringing'], ['increment_seconds' => 0], ['increment_seconds' => 7], ['playback_failure_policy' => 'anything'], ['reservation_seconds' => 0], ['reservation_seconds' => 86401], ['minor_digits' => 7], ['currency' => 'cedi']] as $invalid) {
            $this->post(route('billing.price'), array_replace($base, $invalid))->assertSessionHasErrors(array_key_first($invalid));
        }
        $this->assertSame(1, RateVersion::count());
    }

    public function test_configured_rounding_is_frozen_for_existing_runs(): void
    {
        $this->fund();
        $this->assignPolicy(['increment_seconds' => 6, 'playback_failure_policy' => 'charge']);
        $campaign = $this->campaign();
        $this->assertSame(4, $campaign->versions()->first()->snapshot['broadcast']['billing']['call_charge_minor']);
        $this->assignPolicy(['increment_seconds' => 60, 'playback_failure_policy' => 'waive']);
        $attempt = $this->reserve();
        $this->terminal($attempt, true, 'playback_failed');
        $this->assertSame(96, (int) $this->wallet->fresh()->balance_minor);
        $this->assertSame(6, $this->ledger->quote($this->campaign(false))['call_charge_minor']);
        $this->actingAs($this->client)->get('/client/wallet')->assertOk()->assertSee('Confirmed playback failures are free.');
        $this->get(route('client.campaigns.show', $campaign))->assertOk()->assertSee('6-second increments')->assertSee('Answered calls are charged even if playback fails.');
    }

    public function test_waived_playback_failures_release_credit_once_without_reopening_on_replay(): void
    {
        $this->fund();
        $this->assignPolicy(['playback_failure_policy' => 'waive']);
        $this->campaign();
        $attempt = $this->reserve();
        $this->terminal($attempt, true, 'playback_failed');
        $this->ledger->settle($attempt);
        $this->assertSame(100, (int) $this->wallet->fresh()->balance_minor);
        $this->assertSame(0, (int) $this->wallet->fresh()->reserved_minor);
        $this->assertSame('released', DB::table('wallet_reservations')->where('call_attempt_id', $attempt->id)->value('status'));
        $this->assertSame(0, WalletLedgerEntry::where('kind', 'call_charge')->count());
    }

    public function test_playback_review_requires_explicit_admin_decision_and_preserves_release_on_replay(): void
    {
        $this->fund();
        $this->assignPolicy(['playback_failure_policy' => 'review']);
        $this->campaign();
        $attempt = $this->reserve();
        $this->terminal($attempt, true, 'playback_failed');
        $r = DB::table('wallet_reservations')->where('call_attempt_id', $attempt->id)->first();
        $this->assertSame('review', $r->status);
        $this->assertSame(6, (int) $this->wallet->fresh()->reserved_minor);
        $this->ledger->settle($attempt);
        $this->assertSame(100, (int) $this->wallet->fresh()->balance_minor);
        $this->ledger->resolve($this->admin, $r->id, 'release', 'Verified playback dispute waiver');
        $this->ledger->settle($attempt);
        $this->assertSame('released', DB::table('wallet_reservations')->find($r->id)->status);
        $this->assertSame(0, (int) $this->wallet->fresh()->reserved_minor);
    }

    public function test_actual_duration_retains_hold_until_verified_seconds_are_supplied(): void
    {
        $this->fund();
        $this->assignPolicy(['duration_basis' => 'answered', 'increment_seconds' => 6, 'reservation_seconds' => 300]);
        $this->campaign();
        $attempt = $this->reserve();
        $this->assertSame(15, (int) $this->wallet->fresh()->reserved_minor);
        $this->terminal($attempt, true);
        $r = DB::table('wallet_reservations')->where('call_attempt_id', $attempt->id)->first();
        $this->assertSame('review', $r->status);
        $this->assertSame(100, (int) $this->wallet->fresh()->balance_minor);
        $this->actingAs($this->admin)->get(route('billing.wallet', $this->wallet))->assertOk()->assertSee('Verified answered duration')->assertSee('6-second increments');
        $this->post(route('billing.resolve', [$this->wallet, $r->id]), ['decision' => 'charge', 'evidence' => 'CDR reference 123', 'verified_seconds' => 61])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(96, (int) $this->wallet->fresh()->balance_minor);
        $this->assertSame(0, (int) $this->wallet->fresh()->reserved_minor);
        $this->assertSame(61, (int) DB::table('wallet_reservations')->find($r->id)->verified_seconds);
        $this->ledger->settle($attempt);
        $this->assertSame(1, WalletLedgerEntry::where('kind', 'call_charge')->count());
    }

    public function test_verified_duration_over_reservation_needs_review_and_sufficient_credit(): void
    {
        $this->fund(6);
        $this->assignPolicy(['duration_basis' => 'answered', 'reservation_seconds' => 60]);
        $this->campaign();
        $attempt = $this->reserve();
        $attempt->update(['billable_seconds' => 121]);
        $this->terminal($attempt, true);
        $r = DB::table('wallet_reservations')->where('call_attempt_id', $attempt->id)->first();
        $this->assertSame('review', $r->status);
        $this->assertSame(6, (int) $this->wallet->fresh()->balance_minor);
        try {
            $this->ledger->resolve($this->admin, $r->id, 'charge', 'Verified 121-second CDR');
            $this->fail('An overdrawn reconciliation was posted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('billing', $e->errors());
        }
        $this->fund(10);
        $this->ledger->resolve($this->admin, $r->id, 'charge', 'Verified 121-second CDR');
        $this->assertSame(7, (int) $this->wallet->fresh()->balance_minor);
        $this->assertTrue($this->ledger->reconciliation($this->wallet->fresh())['matches']);
    }

    public function test_playback_failure_policy_survives_gateway_disconnect_and_call_reconciliation(): void
    {
        $this->fund();
        $this->assignPolicy(['playback_failure_policy' => 'waive']);
        $this->campaign();
        $attempt = $this->reserve();
        $attempt->update(['sent_at' => now(), 'answered_at' => now()]);
        app(EventProcessor::class)->ingest(['Event' => 'UserEvent', 'UserEvent' => 'VBPlaybackFailed', 'Uniqueid' => $attempt->uuid, 'AttemptID' => $attempt->uuid]);
        $this->assertNotNull($attempt->fresh()->playback_failed_at);
        app(Engine::class)->disconnected($this->gateway->owner);
        $this->assertSame('reconciling', $attempt->fresh()->outcome);
        $this->terminal($attempt->fresh(), true, 'answered_unknown');
        $this->assertSame(100, (int) $this->wallet->fresh()->balance_minor);
        $this->assertSame(0, (int) $this->wallet->fresh()->reserved_minor);
        $this->assertSame('released', DB::table('wallet_reservations')->where('call_attempt_id', $attempt->id)->value('status'));
    }

    public function test_late_playback_failure_waiver_refunds_a_posted_charge_once(): void
    {
        $this->fund();
        $this->assignPolicy(['playback_failure_policy' => 'waive']);
        $this->campaign();
        $attempt = $this->reserve();
        $this->terminal($attempt, true, 'early_hangup');
        $this->assertSame(94, (int) $this->wallet->fresh()->balance_minor);
        $failure = ['Event' => 'UserEvent', 'UserEvent' => 'VBPlaybackFailed', 'Uniqueid' => $attempt->uuid, 'AttemptID' => $attempt->uuid];
        app(EventProcessor::class)->ingest($failure);
        app(EventProcessor::class)->ingest($failure);
        $this->ledger->settle($attempt);
        $this->assertSame(100, (int) $this->wallet->fresh()->balance_minor);
        $this->assertSame(1, WalletLedgerEntry::where('kind', 'refund')->count());
        $this->assertSame(1, WalletLedgerEntry::where('kind', 'call_charge')->count());
        $this->assertTrue($this->ledger->reconciliation($this->wallet->fresh())['matches']);
    }

    public function test_account_spending_cap_blocks_reservation_and_is_rechecked_before_network_send(): void
    {
        $this->fund();
        $campaign = $this->campaign();
        ClientAccount::whereKey($this->client->client_account_id)->update(['daily_spend_limit_minor' => 5]);
        $this->assertNull($this->reserve());
        $this->assertSame(0, (int) $this->wallet->fresh()->reserved_minor);
        ClientAccount::whereKey($this->client->client_account_id)->update(['daily_spend_limit_minor' => 6]);
        $attempt = $this->reserve();
        $this->assertNotNull($attempt);
        $snapshot = $campaign->versions()->whereKey($campaign->active_version_id)->first()->snapshot['broadcast'];
        $this->assertTrue($this->ledger->canSend($attempt, $campaign, $snapshot));
        ClientAccount::whereKey($this->client->client_account_id)->update(['daily_spend_limit_minor' => 0]);
        $this->assertFalse($this->ledger->canSend($attempt, $campaign, $snapshot));
        $this->assertSame(1, WalletLedgerEntry::count()); // Funding only; no call charge was posted.
    }

    public function test_account_dispatch_restriction_stops_new_admission_after_launch(): void
    {
        $this->fund();
        $campaign = $this->campaign();
        ClientAccount::whereKey($this->client->client_account_id)->update(['dispatch_blocked' => true]);
        $this->assertNull($this->reserve());
        $this->assertSame('running', $campaign->fresh()->status);
        $this->assertSame(0, CallAttempt::count());
    }
}
