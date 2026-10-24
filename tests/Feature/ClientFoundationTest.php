<?php

namespace Tests\Feature;

use App\Jobs\ImportContacts;
use App\Models\AudioFile;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignVersion;
use App\Models\ClientAccount;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ContactImport;
use App\Models\User;
use App\Services\Broadcast\AmiClient;
use App\Services\Broadcast\Gateway;
use App\Services\Clients\AccountContext;
use App\Services\Clients\Accounts;
use App\Services\Contacts\Imports;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClientFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ClientAccount $a;

    private ClientAccount $b;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $this->a = app(Accounts::class)->create($this->admin, 'Client Alpha');
        $this->b = app(Accounts::class)->create($this->admin, 'Client Beta');
        $this->alice = $this->client($this->a);
        $this->bob = $this->client($this->b);
    }

    private function client(ClientAccount $account): User
    {
        $user = User::factory()->create();
        $user->forceFill(['client_account_id' => $account->id])->save();
        $user->assignRole('Client');

        return $user;
    }

    private function contact(User $user): Contact
    {
        return Contact::create(['name' => $user->name, 'phone_e164' => '+233241234567', 'source' => 'Test', 'created_by' => $user->id]);
    }

    public function test_client_management_menu_and_access_are_available_to_administrators(): void
    {
        foreach (['Super Administrator', 'Administrator'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Client Management')->assertSee(route('clients.accounts'));
            $this->get('/client-accounts')->assertOk()->assertSee('Client Alpha');
        }
        $this->actingAs($this->alice)->get('/client-accounts')->assertForbidden();
        $analyst = User::factory()->create();
        $analyst->assignRole('Analyst');
        $this->actingAs($analyst)->get('/client-accounts')->assertForbidden();
    }

    public function test_accounts_are_admin_managed_and_status_changes_are_audited(): void
    {
        $this->actingAs($this->admin)->get('/client-accounts')->assertOk()->assertSee('Client Alpha');
        $this->post('/client-accounts', ['name' => 'Client Gamma'])->assertRedirect();
        $this->assertDatabaseHas('client_accounts', ['name' => 'Client Gamma', 'status' => 'active']);
        $this->post(route('clients.accounts.status', $this->a), ['status' => 'suspended', 'reason' => 'Account review'])->assertRedirect();
        $this->assertSame('suspended', $this->a->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'client_account.status_changed']);
        $manager = User::factory()->create();
        $manager->assignRole('Campaign Manager');
        $this->actingAs($manager)->get('/client-accounts')->assertForbidden();
        $this->post('/client-accounts', ['name' => 'No'])->assertForbidden();
    }

    public function test_client_models_and_child_queries_are_isolated_and_phone_deduplication_is_per_account(): void
    {
        $ca = $this->contact($this->alice);
        $cb = $this->contact($this->bob);
        $internal = Contact::create(['phone_e164' => '+233241234567', 'created_by' => $this->admin->id]);
        $ga = ContactGroup::create(['name' => 'Alpha group', 'owner_id' => $this->alice->id]);
        $gb = ContactGroup::create(['name' => 'Beta group', 'owner_id' => $this->bob->id]);
        $campaignA = Campaign::create(['name' => 'Alpha campaign', 'owner_id' => $this->alice->id]);
        $campaignB = Campaign::create(['name' => 'Beta campaign', 'owner_id' => $this->bob->id]);
        foreach ([[$campaignA, $ca], [$campaignB, $cb]] as [$campaign, $contact]) {
            CampaignRecipient::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'phone_e164' => $contact->phone_e164]);
        }
        $this->actingAs($this->alice);
        $this->assertSame([$ca->id], Contact::pluck('id')->all());
        $this->assertNull(Contact::find($cb->id));
        $this->assertNull(Contact::find($internal->id));
        $this->assertSame([$ga->id], ContactGroup::pluck('id')->all());
        $this->assertSame(1, CampaignRecipient::count());
        $this->assertSame([$this->a->id], ClientAccount::pluck('id')->all());
        $this->assertFalse(Gate::forUser($this->alice)->allows('audio.approve'));
        $this->actingAs($this->admin);
        $this->assertSame(3, Contact::count());
        $this->assertSame(2, ContactGroup::count());
    }

    public function test_client_cannot_access_internal_screens_downloads_or_privileges_even_with_extra_roles(): void
    {
        $audio = AudioFile::create(['uuid' => (string) Str::uuid(), 'name' => 'Internal audio', 'uploaded_by' => $this->admin->id]);
        $this->alice->assignRole('Super Administrator');
        $this->alice->givePermissionTo('audio.manage', 'contacts.manage', 'campaigns.manage');
        $this->actingAs($this->alice)->get('/client')->assertOk()->assertSee('Client Alpha')->assertDontSee('Client Beta');
        foreach (['/dashboard', '/contacts', '/contacts/groups', '/audio', '/campaigns', '/telephony', '/interactive', '/workspace', '/client-accounts', '/horizon', route('audio.preview', $audio)] as $url) {
            $this->assertContains($this->get($url)->status(), [403, 404], $url);
        }
        $this->assertFalse($this->alice->isSuperAdministrator());
        $this->assertFalse($this->alice->hasAnyPermission(['contacts.manage', 'audio.approve']));
        $this->assertFalse(Gate::forUser($this->alice)->allows('campaigns.manage'));
    }

    public function test_suspension_revokes_sessions_blocks_login_and_requires_explicit_campaign_resume(): void
    {
        $campaign = Campaign::create(['name' => 'Client work', 'owner_id' => $this->alice->id, 'status' => 'running']);
        DB::table('sessions')->insert(['id' => 'client-session', 'user_id' => $this->alice->id, 'payload' => '', 'last_activity' => time()]);
        $this->alice->forceFill(['remember_token' => 'remember-me'])->save();
        app(Accounts::class)->setStatus($this->admin, $this->a, 'suspended', 'Review required');
        $this->assertDatabaseMissing('sessions', ['id' => 'client-session']);
        $this->assertNull($this->alice->fresh()->remember_token);
        $this->assertSame('paused', $campaign->fresh()->status);
        $this->assertFalse(app(Accounts::class)->campaignActive($campaign));
        $this->actingAs($this->alice)->get('/client')->assertForbidden();
        $this->assertGuest();
        $this->post('/login', ['email' => $this->alice->email, 'password' => 'password'])->assertSessionHasErrors('email');
        app(Accounts::class)->setStatus($this->admin, $this->a, 'active', 'Review complete');
        $this->assertSame('paused', $campaign->fresh()->status);
        $this->post('/login', ['email' => $this->alice->email, 'password' => 'password'])->assertRedirect('/client');
    }

    public function test_context_is_restored_between_jobs_and_suspended_imports_are_blocked(): void
    {
        $ca = $this->contact($this->alice);
        $cb = $this->contact($this->bob);
        $context = app(AccountContext::class);
        $this->assertSame([$ca->id], $context->run($this->a->id, fn () => Contact::pluck('id')->all()));
        $this->assertSame([$cb->id], $context->run($this->b->id, fn () => Contact::pluck('id')->all()));
        $this->assertNull($context->id());
        try {
            $context->run($this->a->id, fn () => throw new \RuntimeException('job failed'));
        } catch (\RuntimeException $e) {
            $this->assertSame('job failed', $e->getMessage());
        }
        $this->assertNull($context->id());
        $group = ContactGroup::create(['name' => 'Import group', 'owner_id' => $this->alice->id]);
        $batch = ContactImport::create(['filename' => 'test.csv', 'headers' => ['phone'], 'owner_id' => $this->alice->id, 'contact_group_id' => $group->id, 'status' => 'queued', 'total' => 1]);
        app(Accounts::class)->setStatus($this->admin, $this->a, 'suspended', 'Pause import');
        (new ImportContacts($batch->id))->handle(app(Imports::class));
        $this->assertSame('blocked', $batch->fresh()->status);
        $this->assertNull($context->id());
    }

    public function test_client_user_provisioning_is_interactive_and_does_not_grant_staff_permissions(): void
    {
        $this->artisan('client:user', ['account' => $this->a->id, 'email' => 'new.client@example.com', '--name' => 'New client'])
            ->expectsQuestion('Strong password', 'StrongPassword123!')
            ->expectsOutputToContain('Client user created.')->assertExitCode(0);
        $user = User::where('email', 'new.client@example.com')->firstOrFail();
        $this->assertSame($this->a->id, $user->client_account_id);
        $this->assertTrue($user->hasRole('Client'));
        $this->assertFalse($user->can('campaigns.manage'));
        $this->assertTrue(Hash::check('StrongPassword123!', $user->password));
        app(Accounts::class)->setStatus($this->admin, $this->a, 'suspended', 'Review');
        $this->artisan('client:user', ['account' => $this->a->id, 'email' => 'blocked@example.com'])
            ->expectsOutputToContain('Choose an active client account.')->assertExitCode(1);
        $this->assertDatabaseMissing('users', ['email' => 'blocked@example.com']);
    }

    public function test_suspended_account_blocks_an_unsent_gateway_attempt(): void
    {
        $contact = $this->contact($this->alice);
        $campaign = Campaign::create(['name' => 'Client run', 'owner_id' => $this->alice->id, 'status' => 'running']);
        $recipient = CampaignRecipient::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'phone_e164' => $contact->phone_e164, 'status' => 'reserved']);
        $version = CampaignVersion::create(['campaign_id' => $campaign->id, 'version' => 1, 'snapshot' => ['broadcast' => ['purpose' => 'Test']], 'approved_by' => $this->admin->id, 'approved_at' => now()]);
        $attempt = CallAttempt::create(['uuid' => (string) Str::uuid(), 'campaign_recipient_id' => $recipient->id, 'campaign_version_id' => $version->id, 'state' => 'authorizing', 'token' => str_repeat('a', 64)]);
        $gateway = app(Gateway::class);
        DB::table('broadcast_gateway')->where('id', 1)->update(['owner' => $gateway->owner]);
        app(Accounts::class)->setStatus($this->admin, $this->a, 'suspended', 'Pause');
        $ami = new class extends AmiClient
        {
            public array $sent = [];

            public function send(array $headers): void
            {
                $this->sent[] = $headers;
            }
        };
        $gateway->authorized($ami, $attempt->uuid, true);
        $this->assertSame([], $ami->sent);
        $this->assertSame('account_suspended', $attempt->fresh()->outcome);
        $this->assertNull($attempt->fresh()->sent_at);
    }

    public function test_cross_account_membership_and_ownership_reassignment_are_rejected(): void
    {
        $contact = $this->contact($this->bob);
        $group = ContactGroup::create(['name' => 'Alpha', 'owner_id' => $this->alice->id]);
        $this->actingAs($this->admin)->post(route('contacts.groups.members', $group), ['contact_id' => $contact->id, 'action' => 'add'])->assertForbidden();
        $this->assertSame(0, $group->contacts()->count());
        $this->expectException(\LogicException::class);
        $group->update(['client_account_id' => $this->b->id]);
    }
}
