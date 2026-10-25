<?php

namespace Tests\Feature;

use App\Jobs\DeliverWebhook;
use App\Jobs\ImportContacts;
use App\Models\AudioFile;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ClientAccount;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\User;
use App\Services\Broadcast\Engine;
use App\Services\Clients\AccountContext;
use App\Services\Integrations\Access;
use App\Services\Integrations\Webhooks;
use App\Services\Integrations\WebhookTransport;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $alice;

    private User $bob;

    private ContactGroup $group;

    private AudioFile $audio;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Queue::fake();
        config(['integrations.api_enabled' => true, 'integrations.webhook_delivery_enabled' => true]);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $this->alice = $this->client('Alice');
        $this->bob = $this->client('Bob');
        $this->group = ContactGroup::create(['name' => 'Audience', 'owner_id' => $this->alice->id, 'active' => true]);
        $contact = Contact::create(['name' => 'Alice recipient', 'phone_e164' => '+233241234567', 'created_by' => $this->alice->id]);
        $this->group->contacts()->attach($contact);
        $this->audio = AudioFile::create(['uuid' => (string) Str::uuid(), 'name' => 'Approved audio', 'status' => 'approved', 'uploaded_by' => $this->alice->id]);
        $this->token = app(Access::class)->issue($this->alice, $this->alice, 'Test', Access::SCOPES)['token'];
        $this->partialMock(WebhookTransport::class, function ($mock) {
            $mock->shouldReceive('addresses')->andReturn(['8.8.8.8']);
        });
    }

    private function client(string $name): User
    {
        $account = ClientAccount::create(['name' => $name, 'created_by' => $this->admin->id]);
        $user = User::factory()->create(['name' => $name]);
        $user->forceFill(['client_account_id' => $account->id])->save();
        $user->assignRole('Client');

        return $user;
    }

    private function draft(): array
    {
        return ['name' => 'API draft', 'purpose' => 'Updates', 'source_group_id' => $this->group->id, 'audio_id' => $this->audio->id, 'timezone' => 'Africa/Accra', 'window_start' => '08:00', 'window_end' => '18:00', 'max_attempts' => 1, 'retry_delay_seconds' => 60];
    }

    private function subscription(?User $user = null): array
    {
        return app(Webhooks::class)->create($user ?? $this->alice, 'https://receiver.example/events', Webhooks::EVENTS);
    }

    private function completed(?User $user = null): Campaign
    {
        return Campaign::create(['name' => 'Done', 'owner_id' => ($user ?? $this->alice)->id, 'type' => 'one_way', 'status' => 'completed']);
    }

    private function delivery(): object
    {
        $this->subscription();
        $this->completed();
        app(Webhooks::class)->maintain();

        return DB::table('webhook_deliveries')->first();
    }

    public function test_api_is_disabled_by_default_and_rejects_missing_expired_and_revoked_credentials(): void
    {
        config(['integrations.api_enabled' => false]);
        $this->withToken($this->token)->getJson('/api/v1/contacts')->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
        config(['integrations.api_enabled' => true]);
        $this->withToken('invalid')->getJson('/api/v1/contacts')->assertUnauthorized()->assertHeader('X-Request-ID');
        DB::table('api_credentials')->update(['expires_at' => now()->subSecond()]);
        $this->withToken($this->token)->getJson('/api/v1/contacts')->assertUnauthorized();
        DB::table('api_credentials')->update(['expires_at' => now()->addDay(), 'revoked_at' => now()]);
        $this->withToken($this->token)->getJson('/api/v1/contacts')->assertUnauthorized();
    }

    public function test_api_scopes_account_boundaries_and_context_restoration(): void
    {
        Contact::create(['name' => 'Bob secret', 'phone_e164' => '+233241234568', 'created_by' => $this->bob->id]);
        $bobCampaign = $this->completed($this->bob);
        $this->withToken($this->token)->getJson('/api/v1/contacts')->assertOk()->assertJsonCount(1, 'data')->assertDontSee('Bob secret');
        $this->assertNull(app(AccountContext::class)->id());
        $this->withToken($this->token)->getJson('/api/v1/campaigns/'.$bobCampaign->id)->assertNotFound();
        $read = app(Access::class)->issue($this->alice, $this->alice, 'Read', ['contacts.read']);
        $this->withToken($read['token'])->getJson('/api/v1/campaigns')->assertForbidden();
        $this->withToken($this->token)->getJson('/api/v1/reports?kind=campaigns')->assertOk()->assertDontSee('Bob secret');
        ClientAccount::whereKey($this->alice->client_account_id)->update(['status' => 'suspended']);
        $this->withToken($this->token)->getJson('/api/v1/contacts')->assertForbidden();
        $this->assertNull(app(AccountContext::class)->id());
    }

    public function test_api_write_is_idempotent_rejects_conflicts_and_never_launches(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/campaigns', $this->draft())->assertUnprocessable();
        $headers = ['Idempotency-Key' => 'draft-unique-001'];
        $r = $this->withToken($this->token)->postJson('/api/v1/campaigns', $this->draft() + ['caller_id' => '911', 'status' => 'running'], $headers)->assertCreated()->assertJsonPath('status', 'draft');
        $this->withToken($this->token)->postJson('/api/v1/campaigns', $this->draft() + ['caller_id' => '911', 'status' => 'running'], $headers)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('id', $r->json('id'));
        $this->withToken($this->token)->postJson('/api/v1/campaigns', array_replace($this->draft(), ['name' => 'Different']), $headers)->assertConflict();
        $this->assertDatabaseCount('campaigns', 1);
        $this->assertDatabaseCount('campaign_runs', 0);
        $this->assertDatabaseCount('call_attempts', 0);
        $this->assertArrayNotHasKey('caller_id', Campaign::first()->configuration);
        $this->assertSame(1, (int) DB::table('integration_usage')->where('metric', 'drafts_per_day')->value('used'));
        $this->travel(2)->days();
        $this->withToken($this->token)->postJson('/api/v1/campaigns', $this->draft(), $headers)->assertConflict();
    }

    public function test_failed_validation_and_cross_account_writes_leave_no_side_effects(): void
    {
        $headers = ['Idempotency-Key' => 'invalid-draft-001'];
        $this->withToken($this->token)->postJson('/api/v1/campaigns', array_replace($this->draft(), ['audio_id' => 999999]), $headers)->assertNotFound();
        $this->assertDatabaseCount('campaigns', 0);
        $this->assertDatabaseCount('api_idempotency', 0);
        $this->assertDatabaseMissing('integration_usage', ['metric' => 'drafts_per_day']);
        $other = ContactGroup::create(['name' => 'Bob group', 'active' => true, 'owner_id' => $this->bob->id]);
        $this->withToken($this->token)->postJson('/api/v1/contact-imports', ['group_id' => $other->id, 'rows' => [['phone' => '0241234567']]], $headers)->assertNotFound();
        $this->assertDatabaseCount('contact_imports', 0);
    }

    public function test_import_preview_confirmation_replay_and_scoped_status(): void
    {
        $r = $this->withToken($this->token)->postJson('/api/v1/contact-imports', ['group_id' => $this->group->id, 'rows' => [['phone' => '0241234569', 'name' => 'New'], ['phone' => 'bad']]], ['Idempotency-Key' => 'import-unique-001'])->assertCreated()->assertJsonPath('status', 'preview');
        $id = $r->json('id');
        $this->withToken($this->token)->postJson("/api/v1/contact-imports/{$id}/confirm", [], ['Idempotency-Key' => 'confirm-unique-001'])->assertAccepted();
        $this->withToken($this->token)->postJson("/api/v1/contact-imports/{$id}/confirm", [], ['Idempotency-Key' => 'confirm-unique-001'])->assertAccepted()->assertHeader('Idempotency-Replayed', 'true');
        Queue::assertPushed(ImportContacts::class, 1);
        $bob = app(Access::class)->issue($this->bob, $this->bob, 'Bob', ['contacts.read']);
        $this->withToken($bob['token'])->getJson("/api/v1/contact-imports/{$id}")->assertNotFound();
    }

    public function test_quotas_are_shared_and_daily_rejection_does_not_increment_minute_usage(): void
    {
        config(['integrations.defaults.requests_per_day' => 1]);
        $this->withToken($this->token)->getJson('/api/v1/contacts')->assertOk();
        $second = app(Access::class)->issue($this->alice, $this->alice, 'Second', ['contacts.read']);
        $this->withToken($second['token'])->getJson('/api/v1/contacts')->assertStatus(429)->assertHeader('Retry-After', '60');
        $this->assertSame(1, (int) DB::table('integration_usage')->where('metric', 'requests_per_minute')->value('used'));
        $this->assertSame(1, (int) DB::table('integration_usage')->where('metric', 'requests_per_day')->value('used'));
    }

    public function test_credential_ui_returns_secrets_once_and_rotation_revokes_old_key(): void
    {
        $this->actingAs($this->alice)->get('/client/integrations')->assertOk()->assertDontSee($this->token);
        $key = DB::table('api_credentials')->first();
        $this->actingAs($this->alice)->post('/client/integrations/credentials/'.$key->id, ['action' => 'rotate'])->assertOk()->assertSee('Replacement API credential')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertNotNull(DB::table('api_credentials')->where('id', $key->id)->value('revoked_at'));
        $this->assertDatabaseCount('api_credentials', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'api.credential_revoked']);
        $this->actingAs($this->bob)->post('/client/integrations/credentials/2', ['action' => 'revoke'])->assertNotFound();
        $this->actingAs($this->alice)->post('/client/integrations/credentials/2', ['action' => 'revoke'])->assertRedirect();
        $this->assertDatabaseHas('api_credentials', ['id' => 2, 'revoked_at' => now()]);
    }

    public function test_staff_plans_are_audited_and_do_not_grant_finance_or_client_access(): void
    {
        $data = ['name' => 'Limited', 'requests_per_minute' => 2, 'requests_per_day' => 5, 'import_rows_per_day' => 5, 'drafts_per_day' => 0, 'webhook_limit' => 1];
        $this->actingAs($this->alice)->get('/integration-plans')->assertForbidden();
        $this->actingAs($this->admin)->post('/integration-plans', $data)->assertRedirect();
        $this->actingAs($this->admin)->post('/integration-plans/accounts/'.$this->alice->client_account_id, ['plan_id' => 1])->assertRedirect();
        $this->actingAs($this->admin)->get('/integration-plans')->assertOk()->assertSee('Limited');
        $this->assertSame(0, (int) app(Access::class)->limits($this->alice->client_account_id)['drafts_per_day']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'integration.plan_assigned']);
        $this->assertDatabaseCount('wallet_ledger_entries', 0);
        $this->actingAs($this->admin)->post('/integration-plans', array_replace($data, ['webhook_limit' => -1]))->assertSessionHasErrors('webhook_limit');
    }

    public function test_outbox_is_transactional_scoped_deduplicated_and_captures_engine_completion(): void
    {
        $this->subscription();
        $this->subscription($this->bob);
        DB::beginTransaction();
        $this->completed();
        DB::rollBack();
        $this->assertDatabaseCount('integration_events', 0);
        $c = Campaign::create(['name' => 'Running', 'type' => 'one_way', 'owner_id' => $this->alice->id, 'status' => 'running']);
        app(Engine::class)->complete($c->id);
        $c->refresh()->update(['name' => 'Edited']);
        $this->assertDatabaseCount('integration_events', 1);
        app(Webhooks::class)->maintain();
        app(Webhooks::class)->maintain();
        $this->assertDatabaseCount('webhook_deliveries', 1);
        $d = DB::table('webhook_deliveries')->first();
        $this->assertSame('campaign.completed', json_decode($d->payload, true)['type']);
        $this->assertSame($c->id, json_decode($d->payload, true)['data']['campaign_id']);
    }

    public function test_call_outcome_events_exclude_phone_tokens_and_credentials(): void
    {
        $this->subscription();
        $c = Campaign::create(['name' => 'Call', 'type' => 'one_way', 'status' => 'running', 'owner_id' => $this->alice->id]);
        $r = CampaignRecipient::create(['campaign_id' => $c->id, 'contact_id' => Contact::first()->id, 'phone_e164' => '+233241234567']);
        $a = CallAttempt::create(['uuid' => (string) Str::uuid(), 'campaign_recipient_id' => $r->id, 'state' => 'terminal', 'ended_at' => now(), 'answered_at' => now(), 'outcome' => 'playback_failed', 'token' => 'SECRET-MEDIA-TOKEN']);
        $a->update(['hangup_cause' => 16]);
        app(Webhooks::class)->maintain();
        $this->assertDatabaseCount('integration_events', 1);
        $payload = DB::table('webhook_deliveries')->value('payload');
        $this->assertStringNotContainsString('SECRET-MEDIA-TOKEN', $payload);
        $this->assertStringNotContainsString('+233', $payload);
        $this->assertTrue(json_decode($payload, true)['data']['answered']);
        $this->assertFalse(json_decode($payload, true)['data']['playback_completed']);
    }

    public function test_webhook_signature_stable_delivery_id_and_duplicate_jobs(): void
    {
        $d = $this->delivery();
        $s = DB::table('webhook_subscriptions')->first();
        $secret = Crypt::decryptString($s->signing_secret);
        $transport = \Mockery::mock(WebhookTransport::class);
        $transport->shouldReceive('send')->once()->with($s->url, $d->payload, \Mockery::on(function ($headers) use ($d, $secret) {
            $expected = hash_hmac('sha256', now()->timestamp.'.'.$d->uuid.'.'.$d->payload, $secret);

            return in_array('X-VoiceCast-Signature: v1='.$expected, $headers, true) && in_array('X-VoiceCast-Delivery: '.$d->uuid, $headers, true);
        }))->andReturn(204);
        (new DeliverWebhook($d->id))->handle($transport);
        (new DeliverWebhook($d->id))->handle($transport);
        $this->assertDatabaseHas('webhook_deliveries', ['id' => $d->id, 'status' => 'sent', 'attempts' => 1]);
        $this->assertNotSame($secret, $s->signing_secret);
    }

    public function test_webhook_failures_back_off_and_stop_at_five_attempts(): void
    {
        $d = $this->delivery();
        $transport = \Mockery::mock(WebhookTransport::class);
        $transport->shouldReceive('send')->times(5)->andReturn(503);
        for ($n = 1; $n <= 5; $n++) {
            (new DeliverWebhook($d->id))->handle($transport);
            (new DeliverWebhook($d->id))->handle($transport);
            $this->assertSame($n, (int) DB::table('webhook_deliveries')->value('attempts'));
            $this->travel(20)->minutes();
        }
        app(Webhooks::class)->maintain();
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'failed', 'attempts' => 5, 'next_attempt_at' => null]);
    }

    public function test_disabled_suspended_and_interrupted_deliveries_do_not_send(): void
    {
        $d = $this->delivery();
        $transport = \Mockery::mock(WebhookTransport::class);
        $transport->shouldNotReceive('send');
        config(['integrations.webhook_delivery_enabled' => false]);
        (new DeliverWebhook($d->id))->handle($transport);
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'pending', 'attempts' => 0]);
        config(['integrations.webhook_delivery_enabled' => true]);
        DB::table('webhook_deliveries')->update(['status' => 'sending', 'attempts' => 1, 'updated_at' => now()->subMinutes(6)]);
        app(Webhooks::class)->maintain();
        (new DeliverWebhook($d->id))->handle($transport);
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'unknown']);
        $this->actingAs($this->bob)->post('/client/integrations/deliveries/'.$d->id.'/retry', ['confirm' => 1])->assertNotFound();
        $this->actingAs($this->alice)->post('/client/integrations/deliveries/'.$d->id.'/retry', ['confirm' => 1])->assertRedirect();
        DB::table('client_accounts')->where('id', $this->alice->client_account_id)->update(['status' => 'suspended']);
        (new DeliverWebhook($d->id))->handle($transport);
        $this->assertDatabaseHas('webhook_deliveries', ['status' => 'skipped']);
    }

    public function test_webhook_rotation_disable_and_subscription_ownership(): void
    {
        $sub = $this->subscription();
        $this->actingAs($this->bob)->post('/client/integrations/webhooks/'.$sub['id'], ['action' => 'rotate'])->assertNotFound();
        $this->actingAs($this->alice)->post('/client/integrations/webhooks/'.$sub['id'], ['action' => 'rotate'])->assertOk()->assertSee('Replacement webhook signing secret');
        $this->assertNotSame($sub['secret'], Crypt::decryptString(DB::table('webhook_subscriptions')->value('signing_secret')));
        $this->actingAs($this->alice)->post('/client/integrations/webhooks/'.$sub['id'], ['action' => 'disable'])->assertRedirect();
        $this->assertDatabaseHas('webhook_subscriptions', ['active' => false]);
        $this->completed();
        app(Webhooks::class)->maintain();
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_endpoint_validation_rejects_ssrf_and_unsafe_urls_without_network_calls(): void
    {
        $transport = new class extends WebhookTransport
        {
            public array $ips = ['8.8.8.8'];

            public function addresses(string $host): array
            {
                return $this->ips;
            }
        };
        foreach (['http://example.com', 'https://user:pass@example.com', 'https://example.com:8443', 'https://example.com?a=b', 'https://example.com/#fragment', 'https://[::1]/'] as $url) {
            try {
                $transport->destination($url);
                $this->fail('Unsafe endpoint accepted: '.$url);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('url', $e->errors());
            }
        }
        foreach (['127.0.0.1', '169.254.169.254', '10.0.0.1', '100.100.100.200', '192.0.0.1', '198.18.0.1', '224.0.0.1', '0.0.0.0'] as $ip) {
            $transport->ips = ['8.8.8.8', $ip];
            try {
                $transport->destination('https://example.com/events');
                $this->fail('Unsafe address accepted: '.$ip);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('url', $e->errors());
            }
        }
        $transport->ips = ['8.8.8.8'];
        $this->assertSame(['example.com', '8.8.8.8'], $transport->destination('https://example.com/events'));
    }

    public function test_retention_redacts_payloads_and_preserves_replay_tombstones(): void
    {
        $d = $this->delivery();
        DB::table('webhook_deliveries')->where('id', $d->id)->update(['status' => 'sent']);
        $this->withToken($this->token)->postJson('/api/v1/campaigns', $this->draft(), ['Idempotency-Key' => 'retention-draft-001'])->assertCreated();
        $this->travel(32)->days();
        app(Webhooks::class)->maintain();
        $this->assertNull(DB::table('integration_events')->value('payload'));
        $this->assertSame('{}', DB::table('webhook_deliveries')->value('payload'));
        $this->assertNull(DB::table('api_idempotency')->value('response_body'));
        $this->withToken($this->token)->postJson('/api/v1/campaigns', $this->draft(), ['Idempotency-Key' => 'retention-draft-001'])->assertConflict();
        $this->assertDatabaseCount('campaigns', 2);
        $this->assertDatabaseCount('webhook_deliveries', 1);
    }

    public function test_payload_size_credential_limits_and_unverified_identity_are_enforced(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/campaigns', ['name' => str_repeat('x', 1048577)], ['Idempotency-Key' => 'oversized-request'])->assertStatus(413);
        $this->assertDatabaseCount('campaigns', 0);
        $this->alice->forceFill(['email_verified_at' => null, 'phone_verified_at' => null])->save();
        $this->withToken($this->token)->getJson('/api/v1/contacts')->assertForbidden();
        $this->alice->forceFill(['email_verified_at' => now()])->save();
        for ($n = 1; $n < 10; $n++) {
            app(Access::class)->issue($this->alice, $this->alice, 'Key '.$n, ['contacts.read']);
        }
        $this->actingAs($this->alice)->post('/client/integrations/credentials', ['name' => 'Too many', 'days' => 30, 'scopes' => ['contacts.read']])->assertStatus(422);
        $this->assertDatabaseCount('api_credentials', 10);
        $this->assertStringNotContainsString($this->token, DB::table('api_credentials')->first()->token_hash);
    }

    public function test_outbox_backlog_is_bounded_and_resumes_without_missing_or_duplicate_events(): void
    {
        $this->subscription();
        $rows = [];
        for ($n = 1; $n <= 450; $n++) {
            $rows[] = ['event_key' => 'load:'.$n, 'client_account_id' => $this->alice->client_account_id, 'type' => 'campaign.completed', 'payload' => json_encode(['campaign_id' => $n, 'status' => 'completed']), 'occurred_at' => now()];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('integration_events')->insert($chunk);
        }
        app(Webhooks::class)->maintain();
        $this->assertDatabaseCount('webhook_deliveries', 200);
        app(Webhooks::class)->maintain();
        $this->assertDatabaseCount('webhook_deliveries', 400);
        app(Webhooks::class)->maintain();
        app(Webhooks::class)->maintain();
        $this->assertDatabaseCount('webhook_deliveries', 450);
        $this->assertSame(450, (int) DB::table('webhook_subscriptions')->value('last_event_id'));
        $this->assertSame(450, DB::table('webhook_deliveries')->distinct()->count('event_key'));
    }

    public function test_global_emergency_stop_emits_cancellation_events_without_bypassing_stop(): void
    {
        $this->subscription();
        $c = Campaign::create(['name' => 'Active', 'type' => 'one_way', 'status' => 'running', 'owner_id' => $this->alice->id]);
        $this->actingAs($this->admin)->post('/broadcast/emergency', ['action' => 'stop'])->assertRedirect();
        $this->assertSame('cancelled', $c->fresh()->status);
        $this->assertTrue((bool) DB::table('broadcast_gateway')->value('emergency_stop'));
        $this->assertDatabaseHas('integration_events', ['type' => 'campaign.cancelled', 'client_account_id' => $this->alice->client_account_id]);
    }
}
