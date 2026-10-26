<?php

namespace Tests\Feature;

use App\Jobs\ImportContacts;
use App\Models\Client;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ContactImport;
use App\Models\User;
use App\Services\Broadcast\Readiness;
use App\Services\Clients\Accounts;
use App\Services\Clients\Tenancy;
use App\Services\Contacts\Imports;
use App\Services\Foundation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClientContactsModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private User $member;

    private User $foreign;

    private Client $client;

    private ContactGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $accounts = app(Accounts::class);
        $this->client = $accounts->create($this->admin, 'Shared account');
        $accounts->status($this->admin, $this->client, 'active', 'Ready');
        $other = $accounts->create($this->admin, 'Foreign account');
        $accounts->status($this->admin, $other, 'active', 'Ready');
        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->foreign = User::factory()->create();
        $accounts->member($this->admin, $this->client, $this->owner, 'owner');
        $accounts->member($this->admin, $this->client, $this->member);
        $accounts->member($this->admin, $other, $this->foreign, 'owner');
        $this->group = ContactGroup::create(['client_id' => $this->client->id, 'owner_id' => $this->owner->id, 'name' => 'Shared group']);
    }

    public function test_colleagues_can_manage_shared_groups_and_see_contact_memberships(): void
    {
        $contact = app(Tenancy::class)->run($this->client->id, fn () => app(Foundation::class)->createContact($this->owner, ['name' => 'Customer', 'phone' => '0241234567', 'source' => 'Form']));
        $this->group->contacts()->attach($contact);
        $this->actingAs($this->member)->get('/contacts/groups/'.$this->group->id)->assertOk()->assertSee('Group settings')->assertSee('Remove from group')->assertSee('Upload contacts to group');
        $this->get('/contacts/'.$contact->id)->assertOk()->assertSee('Shared group')->assertDontSee('No visible group memberships')->assertSee('Suppress for this account now');
        $this->post('/contacts/groups/'.$this->group->id.'/save', ['name' => 'Renamed group', 'description' => 'Shared audience', 'category' => 'General', 'active' => 0])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($this->group->fresh()->active);
        $this->post('/contacts/groups/'.$this->group->id.'/members', ['contact_id' => $contact->id, 'action' => 'remove'])->assertRedirect();
        $this->assertSame(0, $this->group->contacts()->count());
        $this->actingAs($this->foreign)->get('/contacts/groups/'.$this->group->id)->assertNotFound();
        $this->post('/contacts/groups/'.$this->group->id.'/save', ['name' => 'Stolen'])->assertNotFound();
    }

    public function test_client_csv_workflow_preserves_dnd_consent_and_account_boundary_on_retries(): void
    {
        Queue::fake();
        $this->actingAs($this->member)->post('/contacts', ['name' => 'Existing person', 'phone' => '0241234567', 'source' => 'Manual'])->assertRedirect();
        $contact = Contact::where('phone_e164', '+233241234567')->firstOrFail();
        $this->post('/contacts/'.$contact->id.'/suppress', ['reason' => 'Opted out'])->assertRedirect();
        $this->post('/contacts/imports', ['group_id' => $this->group->id, 'file' => UploadedFile::fake()->createWithContent('audience.csv', "name,phone,source\nUpdated name,0241234567,Upload\nDuplicate,233241234567,Upload\nInvalid,not-a-number,Upload\nNew,0551234567,Upload\n")])->assertRedirect();
        $batch = ContactImport::latest('id')->firstOrFail();
        $this->assertSame($this->client->id, $batch->client_id);
        $this->post('/contacts/imports/'.$batch->id.'/map', ['phone' => 1, 'name' => 0, 'source' => 2])->assertRedirect();
        $this->assertSame(2, $batch->fresh()->duplicates);
        $this->assertSame(1, $batch->fresh()->invalid);
        $this->get('/contacts/imports/'.$batch->id.'/errors')->assertOk()->assertDownload();
        $this->post('/contacts/imports/'.$batch->id.'/confirm', ['confirm' => 1])->assertRedirect();
        Queue::assertPushed(ImportContacts::class);
        app(Imports::class)->process($batch->id);
        app(Imports::class)->process($batch->id);
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(2, $batch->fresh()->imported);
        $this->assertSame(2, $this->group->contacts()->count());
        $this->assertSame('Existing person', $contact->fresh()->name);
        $this->assertDatabaseCount('contact_consents', 0);
        $this->assertDatabaseHas('suppression_entries', ['client_id' => $this->client->id, 'phone_e164' => $contact->phone_e164]);
        $this->assertSame('suppressed', app(Readiness::class)->exclusion($contact->id, $contact->phone_e164, 'Updates'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'contacts.import_completed')->where('entity_id', $batch->id)->count());
        $this->actingAs($this->foreign)->get('/contacts/imports/'.$batch->id)->assertNotFound();
        $this->get('/contacts/imports/'.$batch->id.'/errors')->assertNotFound();
        $this->post('/contacts/imports/'.$batch->id.'/confirm', ['confirm' => 1])->assertNotFound();
        $this->get('/contacts/suppressions')->assertOk()->assertDontSee('Opted out');
    }

    public function test_queued_group_deactivation_blocks_processing_and_notifies_without_raw_errors(): void
    {
        $batch = app(Imports::class)->stage($this->member, $this->group, 'test.csv', ['phone'], [['0241234567']]);
        app(Imports::class)->map($this->member, $batch, ['phone' => 0]);
        $batch->update(['status' => 'queued']);
        $this->group->update(['active' => false]);
        app(Imports::class)->process($batch->id);
        app(Imports::class)->process($batch->id);
        $this->assertSame('blocked', $batch->fresh()->status);
        $this->assertDatabaseCount('contacts', 0);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'contacts.import_blocked')->count());
        $this->actingAs($this->member)->get('/account/notifications')->assertOk()->assertSee('Contact import blocked');
    }

    public function test_failed_jobs_record_one_safe_notice_and_preserve_captured_account(): void
    {
        $batch = app(Imports::class)->stage($this->member, $this->group, 'failed.csv', ['phone'], [['0241234567']]);
        $batch->update(['status' => 'queued']);
        $job = new ImportContacts($batch->id);
        $forged = clone $job;
        $forged->clientId = 999999;
        $forged->failed(new \RuntimeException('private-worker-secret'));
        $this->assertSame('queued', $batch->fresh()->status);
        $job->failed(new \RuntimeException('private-worker-secret'));
        $job->failed(new \RuntimeException('private-worker-secret'));
        $this->assertSame('failed', $batch->fresh()->status);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'contacts.import_failed')->count());
        $this->actingAs($this->member)->get('/account/notifications')->assertOk()->assertSee('Contact import failed')->assertDontSee('private-worker-secret');
    }
}
