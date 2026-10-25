<?php

namespace Tests\Feature;

use App\Models\AudioFile;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\User;
use App\Services\Broadcast\Readiness;
use App\Services\Clients\Accounts;
use App\Services\Clients\Tenancy;
use App\Services\Contacts\Imports;
use App\Services\Contacts\Suppression;
use App\Services\Foundation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ClientIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $a;

    private User $b;

    private Client $ca;

    private Client $cb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $accounts = app(Accounts::class);
        $this->ca = $accounts->create($this->admin, 'Client Alpha');
        $this->cb = $accounts->create($this->admin, 'Client Bravo');
        foreach ([$this->ca, $this->cb] as $client) {
            $accounts->status($this->admin, $client, 'active', 'Test provisioning');
        }
        $this->a = User::factory()->create();
        $this->b = User::factory()->create();
        $accounts->member($this->admin, $this->ca, $this->a, 'owner');
        $accounts->member($this->admin, $this->cb, $this->b, 'owner');
    }

    private function contact(User $user, string $name = 'Private contact'): Contact
    {
        return app(Tenancy::class)->run(app(Tenancy::class)->clientId($user), fn () => app(Foundation::class)->createContact($user, ['name' => $name, 'phone' => '0241234567', 'source' => 'Test']));
    }

    private function group(User $user): ContactGroup
    {
        return app(Tenancy::class)->run(app(Tenancy::class)->clientId($user), fn () => ContactGroup::create(['owner_id' => $user->id, 'name' => 'Private group']));
    }

    public function test_contacts_can_repeat_across_accounts_and_are_not_enumerated_or_modified(): void
    {
        $own = $this->contact($this->a, 'Alpha secret');
        $other = $this->contact($this->b, 'Bravo secret');
        $this->assertNotSame($own->id, $other->id);
        $this->actingAs($this->a)->get('/contacts')->assertOk()->assertSee('Alpha secret')->assertDontSee('Bravo secret');
        $this->get('/contacts/'.$other->id)->assertNotFound();
        $this->post('/contacts/'.$other->id.'/save', ['name' => 'Stolen', 'source' => 'Test'])->assertNotFound();
        $this->assertDatabaseHas('contacts', ['id' => $other->id, 'name' => 'Bravo secret']);
    }

    public function test_multiple_members_share_their_account_without_internal_privileges(): void
    {
        $colleague = User::factory()->create();
        app(Accounts::class)->member($this->admin, $this->ca, $colleague);
        $contact = $this->contact($this->a);
        $group = $this->group($this->a);
        $this->actingAs($colleague)->get('/contacts/'.$contact->id)->assertOk();
        $this->get('/contacts/groups/'.$group->id)->assertOk();
        $this->get('/telephony')->assertForbidden();
        $this->assertFalse($colleague->can('audio.approve'));
    }

    public function test_posted_foreign_contact_cannot_be_attached_to_owned_group(): void
    {
        $group = $this->group($this->a);
        $contact = $this->contact($this->b);
        $this->actingAs($this->a)->post('/contacts/groups/'.$group->id.'/members', ['contact_id' => $contact->id, 'action' => 'add'])->assertNotFound();
        $this->assertDatabaseCount('contact_contact_group', 0);
    }

    public function test_suspended_and_revoked_accounts_are_denied_but_can_logout(): void
    {
        app(Accounts::class)->status($this->admin, $this->ca, 'suspended', 'Account review');
        $this->actingAs($this->a)->get('/dashboard')->assertForbidden();
        $this->post('/logout')->assertRedirect();
        app(Accounts::class)->status($this->admin, $this->ca, 'active', 'Review completed');
        app(Accounts::class)->member($this->admin, $this->ca, $this->a, 'owner', 'revoked');
        $this->actingAs($this->a)->get('/contacts')->assertForbidden();
    }

    public function test_client_cannot_gain_internal_capabilities_by_assigning_an_internal_role(): void
    {
        $this->a->assignRole('Super Administrator');
        $this->assertFalse($this->a->isSuperAdministrator());
        foreach (['audio.approve', 'clients.manage', 'campaigns.approve', 'telephony.manage', 'broadcast.emergency', 'dashboard.global', 'audit.view'] as $ability) {
            $this->assertFalse($this->a->can($ability), $ability);
            $this->assertFalse($this->a->hasAnyPermission([$ability]), $ability);
        }
    }

    public function test_media_download_and_preview_do_not_expose_another_accounts_files(): void
    {
        Storage::fake('local');
        $audio = AudioFile::create(['client_id' => $this->cb->id, 'uuid' => (string) Str::uuid(), 'uploaded_by' => $this->b->id, 'name' => 'Bravo recording', 'storage_key' => 'secret.wav', 'status' => 'pending']);
        Storage::disk('local')->put('secret.wav', 'private');
        $this->actingAs($this->a)->get('/audio')->assertOk()->assertDontSee('Bravo recording');
        $this->get('/audio/'.$audio->id.'/preview')->assertNotFound();
        $this->get('/broadcast/audio/'.$audio->id)->assertNotFound();
        $this->post('/broadcast/audio/'.$audio->id.'/approve')->assertNotFound();
    }

    public function test_campaigns_calls_reports_and_dashboard_json_are_scoped(): void
    {
        $campaign = Campaign::create(['owner_id' => $this->b->id, 'name' => 'Bravo campaign', 'type' => 'one_way', 'purpose' => 'Test']);
        $recipient = CampaignRecipient::create(['campaign_id' => $campaign->id]);
        $uuid = (string) Str::uuid();
        $call = CallAttempt::create(['uuid' => $uuid, 'uniqueid' => $uuid, 'campaign_recipient_id' => $recipient->id, 'state' => 'active']);
        $this->actingAs($this->a)->get('/campaigns')->assertOk()->assertDontSee('Bravo campaign');
        $this->get('/campaigns/'.$campaign->id)->assertNotFound();
        $this->get('/broadcast/campaigns/'.$campaign->id.'/report')->assertNotFound();
        $this->get('/dashboard/calls/'.$call->id)->assertNotFound();
        $this->getJson('/dashboard/live')->assertOk()->assertDontSee($uuid);
        $this->get('/dashboard?campaign_id='.$campaign->id)->assertForbidden();
    }

    public function test_import_exports_and_queued_processing_preserve_account_and_stop_on_revocation(): void
    {
        $group = $this->group($this->b);
        $imports = app(Imports::class);
        $batch = $imports->stage($this->b, $group, 'private.csv', ['phone'], [['0241234567']]);
        $imports->map($this->b, $batch, ['phone' => 0]);
        $batch->update(['status' => 'queued']);
        $this->actingAs($this->a)->get('/contacts/imports/'.$batch->id)->assertNotFound();
        $this->get('/contacts/imports/'.$batch->id.'/errors')->assertNotFound();
        auth()->forgetGuards();
        $imports->process($batch->id);
        $this->assertDatabaseHas('contacts', ['client_id' => $this->cb->id, 'phone_e164' => '+233241234567']);
        $this->assertNull(app(Tenancy::class)->current());
        $batch->refresh()->update(['status' => 'queued']);
        app(Accounts::class)->member($this->admin, $this->cb, $this->b, 'owner', 'revoked');
        $imports->process($batch->id);
        $this->assertDatabaseHas('contact_imports', ['id' => $batch->id, 'status' => 'blocked']);
    }

    public function test_account_asset_validation_also_applies_without_http_authentication(): void
    {
        $campaign = Campaign::create(['owner_id' => $this->a->id, 'name' => 'Alpha', 'purpose' => 'Test']);
        $other = $this->contact($this->b);
        $this->expectException(HttpException::class);
        app(Tenancy::class)->assertAssets($campaign, [$other->id], Contact::class);
    }

    public function test_contact_consents_and_suppression_details_are_not_shared(): void
    {
        $contact = $this->contact($this->b, 'Bravo secret');
        $consent = $contact->consents()->create(['purpose' => 'Bravo purpose', 'legal_basis' => 'Consent', 'source' => 'Form', 'evidence_ref' => 'Private evidence', 'granted_at' => now()]);
        DB::table('suppression_entries')->insert(['client_id' => $this->cb->id, 'ownership_key' => $this->cb->id, 'phone_e164' => $contact->phone_e164, 'reason' => 'Bravo reason', 'created_by' => $this->b->id, 'effective_at' => now()]);
        $this->actingAs($this->a)->get('/contacts/consents')->assertOk()->assertDontSee('Bravo purpose');
        $this->get('/contacts/suppressions')->assertOk()->assertDontSee('Bravo reason');
        $this->post('/consents/'.$consent->id.'/revoke')->assertNotFound();
    }

    public function test_client_cannot_change_ownership_or_provision_accounts(): void
    {
        $contact = $this->contact($this->a);
        $this->actingAs($this->a);
        $this->expectException(AuthorizationException::class);
        app(Accounts::class)->create($this->a, 'Unauthorized account');
    }

    public function test_provisioning_and_account_changes_are_audited_and_internal_data_remains_unassigned(): void
    {
        $internal = app(Foundation::class)->createContact($this->admin, ['name' => 'Internal', 'phone' => '0241234567', 'source' => 'Existing workspace']);
        $this->assertNull($internal->client_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.status_changed', 'entity_id' => $this->ca->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client.membership_changed', 'entity_id' => $this->ca->id]);
        $this->actingAs($this->a)->get('/contacts/'.$internal->id)->assertNotFound();
    }

    public function test_unassigned_client_login_fails_closed(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Client Member');
        $this->actingAs($user)->get('/contacts')->assertForbidden();
    }

    public function test_cross_account_campaign_payload_is_rejected_without_creating_a_draft(): void
    {
        $contact = $this->contact($this->b);
        $this->actingAs($this->a)->post('/campaigns/create', [
            'name' => 'Forged campaign', 'purpose' => 'Test', 'type' => 'one_way', 'timezone' => 'Africa/Accra',
            'dialing_strategy' => 'direct', 'contact_ids' => (string) $contact->id,
            'window_start' => '00:00', 'window_end' => '23:59', 'concurrency' => 1, 'cps' => 1,
            'max_attempts' => 1, 'retry_delay_seconds' => 300, 'priority' => 1,
        ])->assertStatus(422);
        $this->assertDatabaseMissing('campaigns', ['name' => 'Forged campaign']);
    }

    public function test_account_ownership_cannot_be_rewritten(): void
    {
        $contact = $this->contact($this->a);
        $this->actingAs($this->a);
        $contact->client_id = $this->cb->id;
        $this->expectException(HttpException::class);
        $contact->save();
    }

    public function test_account_suspension_blocks_launch_readiness_without_http_context(): void
    {
        $campaign = Campaign::create(['owner_id' => $this->a->id, 'name' => 'Pending launch', 'purpose' => 'Test', 'type' => 'one_way']);
        app(Accounts::class)->status($this->admin, $this->ca, 'suspended', 'Review required');
        try {
            app(Readiness::class)->snapshot($campaign);
            $this->fail('Suspended account passed launch readiness.');
        } catch (ValidationException $e) {
            $this->assertSame('Client account is not active.', $e->errors()['broadcast'][0]);
        }
    }

    public function test_pbx_capability_rejects_foreign_assets_and_suspended_accounts(): void
    {
        Storage::fake('local');
        config()->set('voice_broadcast.media_mode', 'url');
        $checksum = hash('sha256', 'private wav');
        Storage::disk('local')->put('private.wav', 'private wav');
        $audio = AudioFile::create(['client_id' => $this->ca->id, 'uploaded_by' => $this->a->id, 'uuid' => (string) Str::uuid(), 'name' => 'Private', 'storage_key' => 'private.wav', 'checksum' => $checksum, 'status' => 'approved']);
        $campaign = Campaign::create(['owner_id' => $this->a->id, 'name' => 'Alpha playback', 'purpose' => 'Test']);
        $version = $campaign->versions()->create(['version' => 1, 'approved_by' => $this->admin->id, 'approved_at' => now(), 'snapshot' => ['broadcast' => ['audio_uuid' => $audio->uuid, 'checksum' => $checksum]]]);
        $recipient = CampaignRecipient::create(['campaign_id' => $campaign->id]);
        $uuid = (string) Str::uuid();
        $token = bin2hex(random_bytes(32));
        $call = CallAttempt::create(['uuid' => $uuid, 'uniqueid' => $uuid, 'campaign_recipient_id' => $recipient->id, 'campaign_version_id' => $version->id, 'sent_at' => now(), 'token' => $token]);
        $url = '/pbx-media/'.$uuid.'/'.$token.'/'.$audio->uuid.'/'.$checksum.'.wav';
        $this->get($url)->assertOk();
        $this->actingAs($this->b)->get($url)->assertNotFound();
        auth()->forgetGuards();
        app(Accounts::class)->status($this->admin, $this->ca, 'suspended', 'Review required');
        $this->get($url)->assertNotFound();
    }

    public function test_cli_provisioning_is_authorized_and_audited(): void
    {
        $this->artisan('clients:manage', ['action' => 'create', '--actor' => $this->admin->id, '--name' => 'CLI account'])->expectsOutputToContain('Created pending client')->assertSuccessful();
        $this->assertDatabaseHas('clients', ['name' => 'CLI account', 'status' => 'pending']);
    }

    public function test_migration_resumes_and_preserves_explicit_legacy_account_ownership(): void
    {
        Schema::create('client_accounts', function ($table) {
            $table->id();
            $table->uuid('uuid');
            $table->string('name');
            $table->string('status');
            $table->timestamps();
        });
        Schema::table('users', fn ($t) => $t->unsignedBigInteger('client_account_id')->nullable());
        Schema::table('contacts', fn ($t) => $t->unsignedBigInteger('client_account_id')->nullable());
        $uuid = (string) Str::uuid();
        DB::table('client_accounts')->insert(['id' => 100, 'uuid' => $uuid, 'name' => 'Legacy client', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['client_account_id' => 100]);
        $contact = Contact::create(['name' => 'Legacy owned contact', 'phone_e164' => '+233241234567']);
        DB::table('contacts')->where('id', $contact->id)->update(['client_account_id' => 100]);
        $migration = require database_path('migrations/2026_10_25_200000_add_client_accounts.php');
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('clients', ['id' => 100, 'uuid' => $uuid]);
        $this->assertDatabaseHas('client_memberships', ['client_id' => 100, 'user_id' => $user->id]);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'client_id' => 100, 'ownership_key' => 100]);
        $this->assertDatabaseCount('client_memberships', 3);
    }

    public function test_global_suppression_blocks_client_calls_without_exposing_internal_notes(): void
    {
        $contact = $this->contact($this->a);
        app(Suppression::class)->add($contact->phone_e164, 'Internal confidential note', $this->admin->id, now());
        $this->actingAs($this->a)->get('/contacts/suppressions')->assertOk()->assertSee('Platform exclusion')->assertDontSee('Internal confidential note');
        $this->get('/contacts/'.$contact->id)->assertOk()->assertDontSee('Internal confidential note');
        $this->assertSame('suppressed', app(Readiness::class)->exclusion($contact->id, $contact->phone_e164, 'Test'));
    }
}
