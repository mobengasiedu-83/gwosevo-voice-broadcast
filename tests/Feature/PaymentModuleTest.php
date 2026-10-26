<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PaymentEvent;
use App\Models\PaymentIntent;
use App\Models\PaymentReconciliation;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletLedgerEntry;
use App\Services\Billing\Wallet;
use App\Services\Clients\Accounts;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private Client $client;

    private WalletAccount $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-10-26T12:00:00.000000Z'));
        config()->set('payments.enabled', true);
        config()->set('payments.provider', 'xcel');
        config()->set('payments.min_amount_minor', 100);
        config()->set('payments.max_amount_minor', 1000000);
        config()->set('payments.pending_ttl_minutes', 30);
        config()->set('payments.providers.xcel', [
            'driver' => 'http',
            'base_url' => 'https://payments.test',
            'api_key' => 'test-api-key',
            'initiate_path' => '/payments',
            'http_timeout' => 5,
            'webhook_secret' => 'test-webhook-secret',
            'webhook_signature_header' => 'X-Payment-Signature',
            'webhook_timestamp_header' => 'X-Payment-Timestamp',
            'webhook_tolerance' => 300,
        ]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $accounts = app(Accounts::class);
        $this->client = $accounts->create($this->admin, 'Top-up customer');
        $accounts->status($this->admin, $this->client, 'active', 'Ready');
        $this->member = User::factory()->create();
        $accounts->member($this->admin, $this->client, $this->member, 'owner');
        $this->wallet = app(Wallet::class)->open($this->admin, $this->client, 'GHS', 2, 'Approved onboarding');
    }

    private function fakeProvider(): void
    {
        Http::fake(function (Request $request) {
            if (($request['amount_minor'] ?? null) === 1000) {
                return Http::response([], 502);
            }
            $reference = 'xcel-'.Str::uuid();

            return Http::response([
                'provider_reference' => $reference,
                'checkout_url' => 'https://checkout.xcel.test/pay/'.Str::uuid(),
            ], 201);
        });
    }

    private function initiate(string $amount = '50.00', ?string $key = null): PaymentIntent
    {
        $response = $this->actingAs($this->member)->post(route('wallet.payments.store'), [
            'amount' => $amount,
            'request_key' => $key ?? (string) Str::uuid(),
        ]);
        $response->assertRedirect();

        return PaymentIntent::latest('id')->firstOrFail();
    }

    private function sendCallback(PaymentIntent $intent, array $overrides = [], ?int $timestamp = null, ?string $secret = null)
    {
        $payload = array_merge([
            'event_id' => 'evt-'.Str::uuid(),
            'type' => 'payment.succeeded',
            'status' => 'succeeded',
            'reference' => $intent->public_id,
            'provider_reference' => $intent->provider_reference,
            'amount_minor' => $intent->amount_minor,
            'currency' => $intent->currency,
            'minor_unit_scale' => $intent->minor_unit_scale,
        ], $overrides);
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $stamp = (string) ($timestamp ?? now()->timestamp);
        $signature = hash_hmac('sha256', $stamp.'.'.$raw, $secret ?? 'test-webhook-secret');

        return $this->call('POST', route('payments.webhook', ['provider' => 'xcel']), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYMENT_TIMESTAMP' => $stamp,
            'HTTP_X_PAYMENT_SIGNATURE' => 'sha256='.$signature,
        ], $raw);
    }

    public function test_ghs_topups_require_ten_cedis_and_accept_custom_amounts(): void
    {
        $this->fakeProvider();
        // The legacy minimum must not allow payments below the business minimum.
        config()->set('payments.min_amount_minor', 100);
        $this->actingAs($this->member)->get(route('wallet.payments.index'))
            ->assertOk()->assertSee('10.00 GHS');
        foreach (['0.01', '9.99'] as $amount) {
            $this->postJson(route('wallet.payments.store'), [
                'amount' => $amount, 'request_key' => (string) Str::uuid(),
            ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
        Http::assertNothingSent();
        $this->assertSame(0, PaymentIntent::count());
        foreach (['10.00' => 1000, '12.37' => 1237] as $amount => $minor) {
            $intent = $this->initiate($amount);
            $this->assertSame($minor, $intent->amount_minor);
        }
    }

    public function test_client_can_initiate_a_topup_idempotently_and_is_redirected_to_checkout(): void
    {
        $this->fakeProvider();
        $key = (string) Str::uuid();

        $response = $this->actingAs($this->member)->post(route('wallet.payments.store'), [
            'amount' => '50.00', 'request_key' => $key,
        ]);

        $intent = PaymentIntent::firstOrFail();
        $response->assertRedirect($intent->checkout_url);
        $this->assertSame('pending', $intent->status);
        $this->assertSame(5000, $intent->amount_minor);
        $this->assertSame('GHS', $intent->currency);
        $this->assertSame(2, $intent->minor_unit_scale);
        $this->assertSame($this->client->id, $intent->client_id);
        $this->assertSame($this->wallet->id, $intent->wallet_id);
        $this->assertSame($this->member->id, $intent->initiated_by);
        $this->assertStringStartsWith('payment:'.$this->client->id.':', $intent->request_key);
        $this->assertNotNull($intent->expires_at);
        $this->assertSame(0, $this->wallet->refresh()->available_minor, 'An unverified intent must not credit the wallet.');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://payments.test/payments'
            && $request['reference'] === $intent->public_id
            && $request['amount_minor'] === 5000
            && $request['currency'] === 'GHS'
            && $request['callback_url'] === route('payments.webhook', ['provider' => 'xcel'])
            && $request->hasHeader('Idempotency-Key', $intent->public_id));

        // Retrying the form submission with the same request key returns the same intent.
        $this->actingAs($this->member)->post(route('wallet.payments.store'), [
            'amount' => '50.00', 'request_key' => $key,
        ])->assertRedirect($intent->checkout_url);
        $this->assertSame(1, PaymentIntent::count());
        Http::assertSentCount(1);
        $this->actingAs($this->member)->get(route('wallet.payments.show', $intent->public_id))
            ->assertOk()->assertSee('Resume secure payment')->assertSee($intent->checkout_url);
    }

    public function test_verified_callback_credits_once_and_rejects_duplicates_bad_signatures_and_mismatches(): void
    {
        $this->fakeProvider();
        $intent = $this->initiate();
        $eventId = 'evt-'.Str::uuid();

        $this->sendCallback($intent, ['event_id' => $eventId])->assertOk()->assertJson(['received' => true, 'result' => 'processed']);
        $intent->refresh();
        $this->assertSame('succeeded', $intent->status);
        $this->assertNotNull($intent->credited_at);
        $this->assertNotNull($intent->wallet_ledger_entry_id);
        $this->assertSame(5000, $this->wallet->refresh()->available_minor);
        $this->assertSame(1, WalletLedgerEntry::where('idempotency_key', 'payment:'.$intent->public_id)->count());
        $this->assertSame('processed', PaymentEvent::firstOrFail()->result);
        $this->assertSame($eventId, PaymentEvent::firstOrFail()->provider_event_id);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $this->member->id, 'event_key' => 'payment.succeeded']);

        // Replaying the same provider event returns the recorded outcome without another credit.
        $this->sendCallback($intent, ['event_id' => $eventId])->assertOk()->assertJson(['result' => 'processed']);
        $this->assertSame(5000, $this->wallet->refresh()->available_minor);
        $this->assertSame(1, PaymentEvent::count());

        // A different event id for an already-paid intent is also a duplicate credit-wise.
        $this->sendCallback($intent)->assertOk()->assertJson(['result' => 'duplicate']);
        $this->assertSame(5000, $this->wallet->refresh()->available_minor);
        $this->assertSame(2, PaymentEvent::count());

        // A later failure must not undo a paid top-up.
        $this->sendCallback($intent, ['type' => 'payment.failed', 'status' => 'failed'])
            ->assertOk()->assertJson(['result' => 'ignored_final']);
        $this->assertSame('succeeded', $intent->refresh()->status);
        $this->assertSame(5000, $this->wallet->refresh()->available_minor);
    }


    public function test_callback_verification_and_amount_currency_reference_mismatches_never_credit(): void
    {
        $this->fakeProvider();
        $pending = $this->initiate();
        $events = PaymentEvent::count();

        $this->post(route('payments.webhook', ['provider' => 'other']))->assertNotFound();
        $this->sendCallback($pending, [], null, 'wrong-secret')->assertStatus(401);
        $this->sendCallback($pending, [], now()->timestamp - 3600)->assertStatus(401);
        $this->assertSame($events, PaymentEvent::count());
        $this->assertSame(0, $this->wallet->refresh()->available_minor);

        $this->sendCallback($pending, ['amount_minor' => 4999])
            ->assertOk()->assertJson(['result' => 'amount_mismatch']);
        $this->sendCallback($pending, ['currency' => 'USD'])
            ->assertOk()->assertJson(['result' => 'currency_mismatch']);
        $this->sendCallback($pending, ['provider_reference' => 'someone-else'])
            ->assertOk()->assertJson(['result' => 'provider_reference_mismatch']);
        $this->sendCallback($pending, ['reference' => (string) Str::uuid(), 'provider_reference' => 'unknown-provider-reference'])
            ->assertOk()->assertJson(['result' => 'unknown_intent']);

        $pending->refresh();
        $this->assertSame('pending', $pending->status);
        $this->assertSame(0, $this->wallet->refresh()->available_minor);
        $this->assertSame($events + 4, PaymentEvent::count());
    }

    public function test_failed_payment_is_recorded_without_credit_and_provider_outage_fails_closed(): void
    {
        $this->fakeProvider();
        $intent = $this->initiate();

        $this->sendCallback($intent, [
            'type' => 'payment.failed', 'status' => 'failed', 'message' => 'Card declined',
        ])->assertOk()->assertJson(['result' => 'processed']);
        $intent->refresh();
        $this->assertSame('failed', $intent->status);
        $this->assertSame('provider_declined', $intent->failure_code);
        $this->assertSame('Card declined', $intent->failure_message);
        $this->assertSame(0, $this->wallet->refresh()->available_minor);

        // A delayed, verified success may still rescue a failed attempt because
        // the provider is authoritative and the customer may already have paid.
        $this->sendCallback($intent)->assertOk()->assertJson(['result' => 'processed']);
        $this->assertSame('succeeded', $intent->refresh()->status);
        $this->assertSame(5000, $this->wallet->refresh()->available_minor);

        $this->actingAs($this->member)->post(route('wallet.payments.store'), [
            'amount' => '10.00', 'request_key' => (string) Str::uuid(),
        ])->assertRedirect();

        $failed = PaymentIntent::latest('id')->firstOrFail();
        $this->assertSame('failed', $failed->status);
        $this->assertSame('provider_error', $failed->failure_code);
        $this->assertNull($failed->wallet_ledger_entry_id);
        $this->assertSame(5000, $this->wallet->refresh()->available_minor);
    }


    public function test_finance_can_refund_once_and_reconciliation_records_healthy_counts(): void
    {
        $this->fakeProvider();
        $intent = $this->initiate();
        $this->sendCallback($intent)->assertOk();
        $intent->refresh();
        $this->assertSame(5000, $this->wallet->refresh()->available_minor);

        // Client members cannot issue refunds.
        $this->actingAs($this->member)->post(route('billing.payments.refund', $intent->public_id), [
            'refund_reference' => 'RF-1', 'reason' => 'Customer request', 'approval' => 'FIN-APPROVED',
        ])->assertForbidden();

        $this->actingAs($this->admin)->post(route('billing.payments.refund', $intent->public_id), [
            'refund_reference' => 'RF-1', 'reason' => 'Customer request', 'approval' => 'FIN-APPROVED',
        ])->assertRedirect(route('billing.payments.show', $intent->public_id));

        $intent->refresh();
        $this->assertSame('refunded', $intent->status);
        $this->assertNotNull($intent->refunded_at);
        $this->assertSame('RF-1', $intent->refund_reference);
        $this->assertSame(0, $this->wallet->refresh()->available_minor);
        $reversal = WalletLedgerEntry::where('reversal_of_id', $intent->wallet_ledger_entry_id)->firstOrFail();
        $this->assertSame(-5000, $reversal->available_delta_minor);
        $this->assertSame($reversal->id, $intent->metadata['reversal_ledger_entry_id']);

        // A full refund cannot be recorded twice.
        $this->actingAs($this->admin)->post(route('billing.payments.refund', $intent->public_id), [
            'refund_reference' => 'RF-2', 'reason' => 'Duplicate', 'approval' => 'FIN-APPROVED',
        ])->assertSessionHasErrors('refund_reference');
        $this->assertSame(0, $this->wallet->refresh()->available_minor);

        $this->actingAs($this->admin)->post(route('billing.payments.reconcile'))
            ->assertRedirect(route('billing.payments'))
            ->assertSessionHas('status');

        $run = PaymentReconciliation::latest('id')->firstOrFail();
        $this->assertSame('ok', $run->status);
        $this->assertSame(1, $run->intents_checked);
        $this->assertSame(0, $run->credited_count);
        $this->assertSame(1, $run->refunded_count);
        $this->assertSame(0, $run->issue_count);
        $this->assertSame('ok', $run->details['status']);
    }

    public function test_client_sees_only_own_receipts_and_admin_console_requires_finance_permission(): void
    {
        $this->fakeProvider();
        $intent = $this->initiate();
        $this->sendCallback($intent)->assertOk();

        $this->actingAs($this->member)->get(route('wallet.payments.index'))
            ->assertOk()->assertSee('Top-ups')->assertSee($intent->receiptNumber());
        $this->actingAs($this->member)->get(route('wallet.payments.show', $intent->public_id))
            ->assertOk()->assertSee($intent->receiptNumber())->assertSee('Applied to your payment');
        $this->actingAs($this->member)->get(route('wallet.payments.receipt', $intent->public_id))
            ->assertOk()->assertSee('GWO SEVO VoiceCast')->assertSee('Calling-credit top-up');

        $this->actingAs($this->member)->get(route('billing.payments'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('billing.payments'))
            ->assertOk()->assertSee($intent->receiptNumber())->assertSee('Payment intents');
        $this->actingAs($this->admin)->get(route('billing.payments.show', $intent->public_id))
            ->assertOk()->assertSee($intent->public_id)->assertSee('Verified callbacks');

        $accounts = app(Accounts::class);
        $otherClient = $accounts->create($this->admin, 'Other customer');
        $accounts->status($this->admin, $otherClient, 'active', 'Ready');
        $otherMember = User::factory()->create();
        $accounts->member($this->admin, $otherClient, $otherMember, 'owner');

        $this->actingAs($otherMember)->get(route('wallet.payments.show', $intent->public_id))->assertNotFound();
        $this->actingAs($otherMember)->get(route('wallet.payments.receipt', $intent->public_id))->assertNotFound();
    }

    public function test_disabled_provider_blocks_new_topups_with_a_safe_message(): void
    {
        config()->set('payments.enabled', false);
        Http::fake();

        $this->actingAs($this->member)->post(route('wallet.payments.store'), [
            'amount' => '50.00', 'request_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, PaymentIntent::count());
        Http::assertNothingSent();
        $this->actingAs($this->member)->get(route('wallet.index'))
            ->assertOk()->assertSee('Online payment is not enabled');
    }
}

