<?php

namespace Tests\Feature;

use App\Jobs\ImportContacts;
use App\Models\ClientAccount;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ContactImport;
use App\Models\User;
use App\Services\Contacts\Imports;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ClientContactsTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $admin;

    private ClientAccount $account;

    private ContactGroup $group;

    private ContactGroup $otherGroup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administrator');
        $this->account = ClientAccount::create(['name' => 'Alpha', 'created_by' => $this->admin->id]);
        $other = ClientAccount::create(['name' => 'Beta', 'created_by' => $this->admin->id]);
        $this->alice = $this->client($this->account);
        $this->bob = $this->client($other);
        $this->group = ContactGroup::create(['name' => 'Alpha group', 'owner_id' => $this->alice->id]);
        $this->otherGroup = ContactGroup::create(['name' => 'Beta secret group', 'owner_id' => $this->bob->id]);
    }

    private function client(ClientAccount $account): User
    {
        $user = User::factory()->create();
        $user->forceFill(['client_account_id' => $account->id])->save();
        $user->assignRole('Client');

        return $user;
    }

    private function contact(User $u, string $name): Contact
    {
        return Contact::create(['created_by' => $u->id, 'name' => $name, 'phone_e164' => '+233241234567', 'source' => 'Test']);
    }

    public function test_client_can_create_contact_in_group_and_reuse_number_without_overwriting(): void
    {
        $this->actingAs($this->alice)->post('/client/contacts', ['name' => 'Alice contact', 'phone' => '0241234567', 'group_id' => $this->group->id, 'client_account_id' => $this->bob->client_account_id])->assertRedirect();
        $c = Contact::firstOrFail();
        $this->assertSame($this->account->id, $c->client_account_id);
        $this->assertSame('+233241234567', $c->phone_e164);
        $this->assertSame(1, $this->group->contacts()->count());
        $this->post('/client/contacts', ['name' => 'Do not overwrite', 'phone' => '+233241234567', 'group_id' => $this->group->id])->assertRedirect();
        $this->assertSame(1, Contact::count());
        $this->assertSame('Alice contact', $c->fresh()->name);
        $this->assertSame(1, $this->group->contacts()->count());
        $this->get('/client/contacts/'.$c->id)->assertOk()->assertSee('Group memberships');
    }

    public function test_directory_groups_membership_and_forms_are_account_scoped(): void
    {
        $a = $this->contact($this->alice, 'Alpha contact');
        $b = $this->contact($this->bob, 'Beta secret contact');
        $this->actingAs($this->alice)->get('/client/contacts')->assertOk()->assertSee('Alpha contact')->assertDontSee('Beta secret contact');
        $this->get('/client/groups')->assertOk()->assertSee('Alpha group')->assertDontSee('Beta secret group');
        $this->get('/client/contacts/'.$b->id)->assertNotFound();
        $this->post('/client/contacts/'.$a->id.'/groups', ['group_id' => $this->otherGroup->id, 'action' => 'add'])->assertNotFound();
        $this->post('/client/contacts/'.$a->id.'/groups', ['group_id' => $this->group->id, 'action' => 'add'])->assertRedirect();
        $this->get('/client/groups/'.$this->group->id)->assertOk()->assertSee('Alpha contact');
        $this->post('/client/contacts/'.$a->id.'/groups', ['group_id' => $this->group->id, 'action' => 'remove'])->assertRedirect();
        $this->assertSame(0, $this->group->contacts()->count());
        $this->post('/client/contacts/'.$a->id, ['name' => 'Changed', 'source' => 'Manual', 'phone_e164' => '+233201111111'])->assertRedirect();
        $this->assertSame('+233241234567', $a->fresh()->phone_e164);
    }

    public function test_clients_can_manage_groups_in_their_account_without_internal_permissions(): void
    {
        $this->actingAs($this->alice)->post('/client/groups', ['name' => 'New group', 'category' => 'Test', 'active' => 1])->assertRedirect();
        $g = ContactGroup::where('name', 'New group')->firstOrFail();
        $this->assertSame($this->account->id, $g->client_account_id);
        $this->post('/client/groups/'.$g->id, ['name' => 'Renamed', 'category' => 'VIP', 'active' => 0])->assertRedirect();
        $this->assertFalse($g->fresh()->active);
        $this->post('/client/contacts', ['name' => 'Blocked', 'phone' => '0241234567', 'group_id' => $g->id])->assertStatus(422);
        $this->get('/contacts/groups')->assertForbidden();
        $this->assertFalse($this->alice->can('contacts.manage'));
    }

    public function test_csv_import_mapping_confirmation_and_job_are_idempotent_and_tenant_scoped(): void
    {
        Bus::fake([ImportContacts::class]);
        $this->contact($this->bob, 'Other tenant existing number');
        $file = UploadedFile::fake()->createWithContent('contacts.csv', "name,phone\nFirst,241234567\nDuplicate,0241234567\nInvalid,123\n");
        $this->actingAs($this->alice)->post('/client/imports', ['group_id' => $this->group->id, 'file' => $file])->assertRedirect();
        $batch = ContactImport::firstOrFail();
        $this->get('/client/imports/'.$batch->id)->assertOk()->assertSee('Map columns');
        $this->post('/client/imports/'.$batch->id.'/map', ['phone' => 1, 'name' => 0])->assertRedirect();
        $this->assertSame(1, $batch->fresh()->invalid);
        $this->assertSame(1, $batch->fresh()->duplicates);
        $this->post('/client/imports/'.$batch->id.'/confirm', ['confirm' => 1])->assertRedirect();
        $this->post('/client/imports/'.$batch->id.'/confirm', ['confirm' => 1])->assertRedirect();
        Bus::assertDispatchedTimes(ImportContacts::class, 1);
        (new ImportContacts($batch->id))->handle(app(Imports::class));
        (new ImportContacts($batch->id))->handle(app(Imports::class));
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(1, Contact::count());
        $this->assertSame('+233241234567', Contact::first()->phone_e164);
        $this->assertSame(1, $this->group->contacts()->count());
        $this->getJson('/client/imports/'.$batch->id)->assertOk()->assertJson(['status' => 'completed', 'imported' => 1]);
        $this->get('/client/imports/'.$batch->id.'/errors')->assertOk()->assertDownload();
        $this->actingAs($this->bob)->get('/client/imports/'.$batch->id)->assertNotFound();
        $this->post('/client/imports/'.$batch->id.'/map', ['phone' => 0])->assertNotFound();
        $this->get('/client/imports/'.$batch->id.'/errors')->assertNotFound();
    }

    public function test_same_account_users_share_contacts_and_can_resume_imports(): void
    {
        $batch = app(Imports::class)->stage($this->alice, $this->group, 'sample.csv', ['phone'], [['0241234567']]);
        $peer = $this->client($this->account);
        $this->actingAs($peer)->post('/client/imports/'.$batch->id.'/map', ['phone' => 0])->assertRedirect();
        Bus::fake([ImportContacts::class]);
        $this->post('/client/imports/'.$batch->id.'/confirm', ['confirm' => 1])->assertRedirect();
        (new ImportContacts($batch->id))->handle(app(Imports::class));
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_dnd_only_exposes_own_contacts_flags_and_cannot_be_removed_by_client(): void
    {
        $a = $this->contact($this->alice, 'Own contact');
        $b = $this->contact($this->bob, 'Other contact');
        $this->actingAs($this->alice)->post('/client/contacts/'.$a->id.'/dnd', ['reason' => 'Asked to stop'])->assertRedirect();
        $this->get('/client/contacts?dnd=1')->assertOk()->assertSee('DND — excluded')->assertDontSee('Asked to stop')->assertDontSee('Other contact');
        $this->post('/client/contacts/'.$b->id.'/dnd', ['reason' => 'unauthorized'])->assertNotFound();
        $this->assertSame(1, DB::table('suppression_entries')->count());
        $this->get('/client/contacts/'.$a->id)->assertOk()->assertSee('Contact support');
        $this->get('/contacts/suppressions')->assertForbidden();
    }

    public function test_suspension_blocks_new_client_import_chunks_and_guests_and_staff_cannot_use_routes(): void
    {
        $batch = app(Imports::class)->stage($this->alice, $this->group, 'sample.csv', ['phone'], [['0241234567']]);
        app(Imports::class)->map($this->alice, $batch, ['phone' => 0]);
        $batch->update(['status' => 'queued']);
        $this->account->update(['status' => 'suspended']);
        (new ImportContacts($batch->id))->handle(app(Imports::class));
        $this->assertSame('blocked', $batch->fresh()->status);
        $this->assertSame(0, Contact::count());
        $this->get('/client/contacts')->assertRedirect('/login');
        $this->actingAs($this->admin)->get('/client/contacts')->assertForbidden();
        $this->actingAs($this->alice)->get('/client/imports')->assertForbidden();
    }

    public function test_xlsx_upload_restores_numeric_phone_prefix_and_blocks_other_account_targets(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'client-xlsx');
        $book = new Spreadsheet;
        try {
            $book->getActiveSheet()->setCellValue('A1', 'phone')->setCellValue('A2', 241234567);
            (new Xlsx($book))->save($path);
            $this->actingAs($this->alice)->post('/client/imports', ['group_id' => $this->otherGroup->id, 'file' => new UploadedFile($path, 'contacts.xlsx', null, null, true)])->assertNotFound();
            $this->post('/client/imports', ['group_id' => $this->group->id, 'file' => new UploadedFile($path, 'contacts.xlsx', null, null, true)])->assertRedirect();
            $batch = ContactImport::firstOrFail();
            $this->post('/client/imports/'.$batch->id.'/map', ['phone' => 0])->assertRedirect();
            $this->assertSame('+233241234567', DB::table('contact_import_rows')->where('contact_import_id', $batch->id)->value('phone_e164'));
            $this->get('/client/imports')->assertOk()->assertSee('contacts.xlsx');
            $this->get('/client/imports/template')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }
}
