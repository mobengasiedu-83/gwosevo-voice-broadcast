<?php

namespace Tests\Feature;

use App\Models\BillingDispute;
use App\Models\CallAttempt;
use App\Models\CallRating;
use App\Models\CallTariff;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Client;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletLedgerEntry;
use App\Models\WalletReservation;
use App\Services\Billing\Rating;
use App\Services\Billing\Tariffs;
use App\Services\Billing\Wallet;
use App\Services\Clients\Accounts;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceAdminModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private Client $client;

    private Campaign $campaign;

    private CallTariff $tariff;

    private WalletAccount $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00.000000Z'));
        config()->set('call_rating.trusted_sources', ['verified-cel']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $accounts = app(Accounts::class);
        $this->client = $accounts->create($this->admin, 'Finance customer');
        $accounts->status($this->admin, $this->client, 'active', 'Ready');
        $this->member = User::factory()->create();
        $accounts->member($this->admin, $this->client, $this->member, 'owner');
        $this->campaign = Campaign::create([
            'client_id' => $this->client->id, 'owner_id' => $this->member->id,
            'name' => 'Finance campaign', 'purpose' => 'Updates', 'type' => 'one_way', 'status' => 'running',
        ]);
        $this->tariff = app(Tariffs::class)->create($this->admin, [
            'code' => 'finance-standard', 'version' => 1, 'currency' => 'GHS', 'minor_unit_scale' => 2,
            'rate_minor_per_minute' => 30, 'playback_policy' => 'charge_answered',
        ], 'Test-only approved tariff');
        app(Tariffs::class)->assign($this->admin, $this->client, $this->tariff, 'Test-only assignment');
        $this->wallet = app(Wallet::class)->open($this->admin, $this->client, 'GHS', 2, 'Approved onboarding');
    }

    private function attempt(int $ms = 60001): array
    {
        $end = CarbonImmutable::now()->subSeconds(2);
        $answer = $end->subMilliseconds($ms);
        $recipient = CampaignRecipient::create(['campaign_id' => $this->campaign->id, 'status' => 'terminal']);
        $uuid = (string) Str::uuid();
        $attempt = CallAttempt::create([
            'uuid' => $uuid, 'uniqueid' => $uuid, 'action_id' => 'vb-'.$uuid, 'linkedid' => 'linked-'.$uuid,
            'campaign_recipient_id' => $recipient->id, 'attempt_no' => 1, 'state' => 'terminal', 'outcome' => 'completed',
            'sent_at' => $answer->subSeconds(2), 'answered_at' => $answer, 'ended_at' => $end,
            'playback_completed_at' => $end, 'tariff_snapshot' => app(Tariffs::class)->snapshot($this->client->id),
        ]);

        return [$attempt, [
            'attempt_uuid' => $uuid, 'uniqueid' => $uuid, 'linkedid' => $attempt->linkedid,
            'disposition' => 'ANSWERED', 'answered_at' => $answer->format('Y-m-d\TH:i:s.u\Z'),
            'ended_at' => $end->format('Y-m-d\TH:i:s.u\Z'), 'duration_ms' => $ms,
        ]];
    }

    public function test_administrator_and_super_administrator_can_manually_credit_a_wallet(): void
    {
        $administrator = User::factory()->create();
        $administrator->assignRole('Administrator');

        $this->actingAs($administrator)->post(route('billing.wallets.credit', $this->client), [
            'amount' => '25.00', 'reason' => 'Administrator goodwill credit', 'approval' => '', 'credit_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->assertSame(2500, $this->wallet->refresh()->available_minor);
        $this->assertDatabaseHas('wallet_ledger_entries', [
            'wallet_id' => $this->wallet->id, 'type' => 'funding', 'available_delta_minor' => 2500,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.wallet_funding', 'actor_id' => $administrator->id]);

        $this->actingAs($this->admin)->post(route('billing.wallets.credit', $this->client), [
            'amount' => '10.00', 'reason' => 'Super Administrator top-up', 'approval' => 'FIN-ADMIN-2',
        ])->assertRedirect();
        $this->assertSame(3500, $this->wallet->refresh()->available_minor);

        $this->actingAs($this->member)->post(route('billing.wallets.credit', $this->client), [
            'amount' => '5.00', 'reason' => 'Client self credit',
        ])->assertForbidden();
        $this->assertSame(3500, $this->wallet->refresh()->available_minor);
    }

    public function test_manual_credit_is_positive_and_idempotent(): void
    {
        $key = (string) Str::uuid();
        $payload = ['amount' => '12.34', 'reason' => 'Approved manual credit', 'credit_key' => $key];
        $this->actingAs($this->admin)->post(route('billing.wallets.credit', $this->client), $payload)->assertRedirect();
        $this->actingAs($this->admin)->post(route('billing.wallets.credit', $this->client), $payload)->assertRedirect();
        $this->assertSame(1234, $this->wallet->refresh()->available_minor);
        $this->assertSame(1, WalletLedgerEntry::where('wallet_id', $this->wallet->id)->where('type', 'funding')->count());

        $this->actingAs($this->admin)->post(route('billing.wallets.credit', $this->client), [
            'amount' => '0.00', 'reason' => 'Zero credit',
        ])->assertSessionHasErrors('amount');
        $this->actingAs($this->admin)->post(route('billing.wallets.credit', $this->client), [
            'amount' => '-1.00', 'reason' => 'Negative credit',
        ])->assertSessionHasErrors('amount');
        $this->assertSame(1234, $this->wallet->refresh()->available_minor);
    }

    public function test_rate_versions_and_assignments_are_permissioned_and_denomination_safe(): void
    {
        $this->actingAs($this->admin)->get(route('billing.rates'))
            ->assertOk()->assertSee('Rate plans and client assignments')->assertSee('finance-standard');
        $this->actingAs($this->member)->get(route('billing.rates'))->assertForbidden();

        $this->actingAs($this->admin)->post(route('billing.rates.store'), [
            'code' => 'finance-standard', 'version' => 2, 'currency' => 'GHS', 'minor_unit_scale' => 2,
            'rate_minor_per_minute' => 35, 'playback_policy' => 'charge_answered', 'evidence' => 'FIN-RATE-2',
        ])->assertRedirect(route('billing.rates'));
        $version = CallTariff::where('code', 'finance-standard')->where('version', 2)->firstOrFail();

        $this->actingAs($this->admin)->post(route('billing.rates.assign'), [
            'client_id' => $this->client->id, 'tariff_id' => $version->id, 'reason' => 'Approved price change',
        ])->assertRedirect(route('billing.rates'));
        $this->assertDatabaseHas('client_tariffs', ['client_id' => $this->client->id, 'call_tariff_id' => $version->id]);

        $this->actingAs($this->admin)->post(route('billing.rates.store'), [
            'code' => 'finance-usd', 'version' => 1, 'currency' => 'USD', 'minor_unit_scale' => 2,
            'rate_minor_per_minute' => 10, 'playback_policy' => 'charge_answered', 'evidence' => 'FIN-RATE-USD',
        ])->assertRedirect();
        $usd = CallTariff::where('code', 'finance-usd')->firstOrFail();
        $this->actingAs($this->admin)->post(route('billing.rates.assign'), [
            'client_id' => $this->client->id, 'tariff_id' => $usd->id, 'reason' => 'Wrong denomination attempt',
        ])->assertSessionHasErrors('tariff_id');
        $this->assertDatabaseMissing('client_tariffs', ['client_id' => $this->client->id, 'call_tariff_id' => $usd->id]);
    }

    public function test_finance_report_and_csv_export_reconcile_to_attempts_ratings_and_ledger(): void
    {
        app(Wallet::class)->post($this->admin, $this->wallet, 'funding', '100.00', 'Approved funding', 'FIN-REPORT');
        [$attempt, $proof] = $this->attempt();
        app(Wallet::class)->reserve($attempt);
        $this->assertSame('rated', app(Rating::class)->import($this->admin, $proof, 'verified-cel', 'private/finance-report'));
        $this->assertSame(60, CallRating::where('call_attempt_id', $attempt->id)->value('amount_minor'));

        $this->actingAs($this->member)->get(route('billing.finance'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('billing.finance', ['from' => '2026-10-26', 'to' => '2026-10-26']))
            ->assertOk()->assertSee('Finance campaign')->assertSee('Per-attempt durations and charges')
            ->assertSee('Reconciliation')->assertSee($attempt->uuid);
        $response = $this->actingAs($this->admin)->get(route('billing.finance.export', [
            'from' => '2026-10-26', 'to' => '2026-10-26', 'client_id' => $this->client->id,
        ]));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('client,campaign,attempt_uuid', $response->streamedContent());
        $this->assertStringContainsString($attempt->uuid, $response->streamedContent());
    }

    public function test_finance_report_flags_ledger_tampering_and_never_repairs_it_automatically(): void
    {
        DB::table('wallets')->where('id', $this->wallet->id)->update(['available_minor' => 1]);
        $this->actingAs($this->admin)->get(route('billing.finance', ['from' => '2026-10-26', 'to' => '2026-10-26']))
            ->assertOk()->assertSee('Reconciliation needs attention')->assertSee($this->client->name);
        $this->assertSame(1, $this->wallet->refresh()->available_minor);
    }

    public function test_admin_dispute_resolution_credits_once_through_the_ledger(): void
    {
        $this->actingAs($this->admin)->post(route('billing.disputes.store'), [
            'client_id' => $this->client->id, 'category' => 'charge', 'subject' => 'Call charge query',
            'description' => 'The customer disputes the rated duration.', 'amount' => '5.00',
        ])->assertRedirect(route('billing.disputes'));
        $dispute = BillingDispute::latest('id')->firstOrFail();
        $this->assertSame('open', $dispute->status);
        $this->assertSame(500, $dispute->amount_minor);

        $this->actingAs($this->member)->post(route('billing.disputes.resolve', $dispute), [
            'status' => 'resolved', 'resolution' => 'Goodwill credit approved', 'credit_amount' => '5.00',
        ])->assertForbidden();

        $this->actingAs($this->admin)->post(route('billing.disputes.resolve', $dispute), [
            'status' => 'resolved', 'resolution' => 'Goodwill credit approved', 'credit_amount' => '5.00', 'approval' => 'FIN-DISPUTE-1',
        ])->assertRedirect(route('billing.disputes'));
        $dispute->refresh();
        $this->assertSame('resolved', $dispute->status);
        $this->assertSame(500, $dispute->credit_amount_minor);
        $this->assertNotNull($dispute->credit_ledger_entry_id);
        $this->assertSame(500, $this->wallet->refresh()->available_minor);
        $this->assertDatabaseHas('wallet_ledger_entries', [
            'id' => $dispute->credit_ledger_entry_id, 'type' => 'adjustment', 'idempotency_key' => 'billing:dispute:'.$dispute->public_id,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'billing.dispute_resolved']);

        $this->actingAs($this->admin)->post(route('billing.disputes.resolve', $dispute), [
            'status' => 'resolved', 'resolution' => 'Try to credit twice', 'credit_amount' => '5.00',
        ])->assertSessionHasErrors('status');
        $this->assertSame(500, $this->wallet->refresh()->available_minor);
    }

    public function test_client_can_open_and_view_only_its_own_disputes(): void
    {
        $this->actingAs($this->member)->post(route('wallet.disputes.store'), [
            'category' => 'charge', 'subject' => 'Unexpected charge', 'description' => 'Please explain the rated charge for this campaign.',
        ])->assertRedirect(route('wallet.disputes.index'));
        $dispute = BillingDispute::latest('id')->firstOrFail();
        $this->assertSame('client', $dispute->source);
        $this->assertSame($this->client->id, $dispute->client_id);

        $this->actingAs($this->member)->get(route('wallet.disputes.index'))->assertOk()->assertSee('Unexpected charge');
        $this->actingAs($this->member)->get(route('billing.disputes'))->assertForbidden();

        $this->actingAs($this->admin);
        $accounts = app(Accounts::class);
        $other = $accounts->create($this->admin, 'Other finance customer');
        $accounts->status($this->admin, $other, 'active', 'Ready');
        $otherMember = User::factory()->create();
        $accounts->member($this->admin, $other, $otherMember, 'owner');
        $this->actingAs($otherMember)->get(route('wallet.disputes.index'))->assertOk()->assertDontSee('Unexpected charge');
        $this->actingAs($otherMember)->post(route('wallet.disputes.store'), [
            'category' => 'charge', 'subject' => 'Other tenant dispute', 'description' => 'This must be scoped to the other client account.',
        ])->assertRedirect();
        $this->assertDatabaseHas('billing_disputes', ['client_id' => $other->id, 'source' => 'client']);
    }

    public function test_admin_and_client_can_export_append_only_wallet_statements(): void
    {
        app(Wallet::class)->post($this->admin, $this->wallet, 'funding', '20.00', 'Approved statement funding', 'FIN-STMT');
        $adminResponse = $this->actingAs($this->admin)->get(route('billing.wallets.statement', $this->client));
        $adminResponse->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('entry_id,created_at_utc,type', $adminResponse->streamedContent());
        $this->assertStringContainsString('Approved statement funding', $adminResponse->streamedContent());

        $clientResponse = $this->actingAs($this->member)->get(route('wallet.statement'));
        $clientResponse->assertOk();
        $this->assertStringContainsString('Approved statement funding', $clientResponse->streamedContent());
        $this->actingAs($this->member)->get(route('billing.wallets.statement', $this->client))->assertForbidden();
    }
}
