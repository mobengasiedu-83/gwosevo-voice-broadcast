<?php

namespace Tests\Feature;

use App\Jobs\ImportContacts;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ContactImport;
use App\Models\User;
use App\Services\Contacts\ImportReader;
use App\Services\Contacts\Imports;
use App\Services\Foundation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Tests\TestCase;

class ContactsWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private ContactGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->manager = $this->user('Campaign Manager');
        $this->group = ContactGroup::create(['name' => 'Customer surveys', 'owner_id' => $this->manager->id, 'description' => 'Survey audience', 'category' => 'Surveys']);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    private function contact(): Contact
    {
        return app(Foundation::class)->createContact($this->manager, ['name' => 'Customer', 'phone' => '0241234567', 'source' => 'Form']);
    }

    private function stage(array $rows): ContactImport
    {
        return app(Imports::class)->stage($this->manager, $this->group, 'contacts.csv', ['name', 'phone', 'source'], $rows);
    }

    public function test_groups_dashboard_directory_and_permissions(): void
    {
        $this->get('/contacts/groups')->assertRedirect('/login');
        $this->actingAs($this->manager)->get('/contacts/groups')->assertOk()->assertSee('Customer surveys')->assertSee('Create new group')->assertSee('Upload contacts to group');
        $this->get('/contacts?q=missing')->assertOk();
        $this->get('/contacts/consents')->assertOk();
        $this->get('/contacts/suppressions')->assertOk();
        $this->get('/contacts/imports')->assertOk();
        $other = $this->user('Campaign Manager');
        $this->actingAs($other)->get('/contacts/groups')->assertDontSee('Customer surveys');
        $this->get(route('contacts.groups.show', $this->group))->assertForbidden();
        $this->post(route('contacts.groups.save', $this->group), ['name' => 'Steal', 'active' => 1, 'category' => 'General'])->assertForbidden();
        $this->actingAs($this->user('Auditor'))->get('/contacts/groups')->assertOk()->assertSee('Customer surveys');
        $this->post('/contacts/groups', ['name' => 'No', 'active' => 1, 'category' => 'General'])->assertForbidden();
    }

    public function test_group_create_edit_memberships_and_filters(): void
    {
        $this->actingAs($this->manager)->post('/contacts/groups', ['name' => 'VIP', 'description' => 'Priority', 'category' => 'VIP', 'active' => 1])->assertRedirect();
        $this->get('/contacts/groups?category=VIP')->assertSee('Priority')->assertDontSee('Survey audience');
        $c = $this->contact();
        $this->post(route('contacts.groups.members', $this->group), ['contact_id' => $c->id, 'action' => 'add'])->assertRedirect();
        $this->post(route('contacts.groups.members', $this->group), ['contact_id' => $c->id, 'action' => 'add'])->assertRedirect();
        $this->assertSame(1, $this->group->contacts()->count());
        $this->get(route('contacts.groups.show', $this->group))->assertOk()->assertSee('+233241234567');
        $this->get('/contacts?group_id='.$this->group->id)->assertSee('Customer');
        $this->get('/contacts?q=0241234567')->assertSee('Customer');
        $this->post(route('contacts.groups.members', $this->group), ['contact_id' => $c->id, 'action' => 'remove'])->assertRedirect();
        $this->assertSame(0, $this->group->contacts()->count());
        $this->post(route('contacts.groups.save', $this->group), ['name' => 'Renamed', 'category' => 'Surveys', 'active' => 0])->assertRedirect();
        $this->get('/contacts/groups?active=1')->assertDontSee('Renamed');
    }

    public function test_csv_upload_mapping_preview_quarantine_and_async_confirm(): void
    {
        Queue::fake();
        $existing = $this->contact();
        $file = UploadedFile::fake()->createWithContent('people.csv', "name,phone,source\nExisting,0241234567,Old\nNew,0531005130,New\nDuplicate,+233531005130,Again\nBad,no-number,Form\n");
        $this->actingAs($this->manager)->post('/contacts/imports', ['group_id' => $this->group->id, 'file' => $file])->assertRedirect();
        $batch = ContactImport::firstOrFail();
        $this->get(route('contacts.import', $batch))->assertOk()->assertSee('Map columns');
        $this->getJson(route('contacts.import', $batch))->assertOk()->assertJsonPath('status', 'mapping')->assertJsonMissingPath('headers')->assertJsonMissingPath('rows');
        $this->post(route('contacts.import.map', $batch), ['phone' => 1, 'name' => 0, 'source' => 2])->assertRedirect();
        $this->assertSame(1, $batch->fresh()->invalid);
        $this->assertSame(2, $batch->fresh()->duplicates);
        $this->get(route('contacts.import', $batch))->assertOk()->assertSee('+233531005130');
        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('contact_consents', 0);
        $this->post(route('contacts.import.confirm', $batch), ['confirm' => 1])->assertRedirect();
        $this->post(route('contacts.import.confirm', $batch), ['confirm' => 1])->assertRedirect();
        Queue::assertPushed(ImportContacts::class, 1);
        app(Imports::class)->process($batch->id);
        app(Imports::class)->process($batch->id);
        $this->assertDatabaseCount('contacts', 2);
        $this->assertSame(2, $this->group->contacts()->count());
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(2, $batch->fresh()->imported);
        $this->assertSame('Customer', $existing->fresh()->name);
        $this->assertDatabaseCount('contact_consents', 0);
        $this->get(route('contacts.import.errors', $batch))->assertOk()->assertDownload();
    }

    public function test_import_scope_invalid_mapping_inactive_group_and_permission_revocation(): void
    {
        $b = $this->stage([['New', '0531005130', 'Form']]);
        $this->actingAs($this->user('Campaign Manager'))->get(route('contacts.import', $b))->assertForbidden();
        $this->get(route('contacts.import.errors', $b))->assertForbidden();
        $this->actingAs($this->manager)->post(route('contacts.import.map', $b), ['phone' => 49])->assertSessionHasErrors();
        $this->post(route('contacts.import.confirm', $b), ['confirm' => 1])->assertUnprocessable();
        app(Imports::class)->map($this->manager, $b, ['phone' => 1]);
        $b->update(['status' => 'queued']);
        $this->group->update(['active' => false]);
        app(Imports::class)->process($b->id);
        $this->assertSame('blocked', $b->fresh()->status);
        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_contact_consent_suppression_history_and_immutable_phone(): void
    {
        $c = $this->contact();
        $this->actingAs($this->manager)->get(route('contacts.show', $c))->assertOk()->assertSee('No consent evidence');
        $this->post(route('contacts.save', $c), ['name' => 'Updated', 'source' => 'Office', 'phone' => '0531005130'])->assertRedirect();
        $this->assertSame('+233241234567', $c->fresh()->phone_e164);
        $this->post(route('contacts.consent', $c), ['purpose' => 'Updates', 'legal_basis' => 'Consent', 'source' => 'Form', 'evidence_ref' => 'signed/1', 'granted_at' => now()->subDay()->format('Y-m-d H:i:s')])->assertRedirect();
        $this->get('/contacts/consents?state=active')->assertSee('signed/1');
        $this->post(route('consents.revoke', $c->consents()->first()), [])->assertRedirect();
        $this->get('/contacts/consents?state=revoked')->assertSee('signed/1');
        $this->post('/contacts/suppressions', ['phone' => '0241234567', 'reason' => 'Requested opt-out'])->assertRedirect();
        $this->get('/contacts?suppressed=1')->assertSee('Updated');
        $this->get('/contacts/suppressions')->assertSee('Requested opt-out');
        $this->get(route('contacts.show', $c))->assertSee('contact · updated');
    }

    public function test_xlsx_first_sheet_shared_strings_and_formulas_rejected(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'contacts-xlsx');
        $z = new \ZipArchive;
        $z->open($path, \ZipArchive::OVERWRITE);
        $z->addFromString('xl/workbook.xml', '<workbook xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Contacts" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $z->addFromString('xl/sharedStrings.xml', '<sst><si><t>phone</t></si><si><t>0241234567</t></si></sst>');
        $sheet = '<worksheet><sheetData><row><c r="A1" t="s"><v>0</v></c></row><row><c r="A2" t="s"><v>1</v></c></row></sheetData></worksheet>';
        $z->addFromString('xl/worksheets/sheet1.xml', $sheet);
        $z->close();
        try {
            $file = new UploadedFile($path, 'contacts.xlsx', null, null, true);
            [$headers,$rows] = app(ImportReader::class)->read($file);
            $this->assertSame(['phone'], $headers);
            $this->assertSame([['0241234567']], $rows);
            $z->open($path);
            $z->addFromString('xl/worksheets/sheet1.xml', str_replace('<v>1</v>', '<f>1+1</f><v>1</v>', $sheet));
            $z->close();
            $this->expectException(ValidationException::class);
            app(ImportReader::class)->read($file);
        } finally {
            unlink($path);
        }
    }

    public function test_error_export_escapes_spreadsheet_formulas_and_html_is_escaped(): void
    {
        $b = $this->stage([['=HYPERLINK("bad")', 'invalid', 'Form']]);
        app(Imports::class)->map($this->manager, $b, ['phone' => 1, 'name' => 0]);
        $response = $this->actingAs($this->manager)->get(route('contacts.import.errors', $b));
        $this->assertStringContainsString("'=HYPERLINK", $response->streamedContent());
        $this->group->update(['name' => '<script>alert(1)</script>']);
        $this->get('/contacts/groups')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_immediate_suppression_cannot_be_delayed_by_an_existing_future_entry(): void
    {
        $c = $this->contact();
        $this->actingAs($this->manager)->post('/contacts/suppressions', ['phone' => '0241234567', 'reason' => 'Future', 'effective_at' => now()->addDay()->format('Y-m-d H:i:s')])->assertRedirect();
        $this->post(route('contacts.suppress', $c), ['reason' => 'Opt out now'])->assertRedirect();
        $this->assertLessThanOrEqual(time(), strtotime(DB::table('suppression_entries')->value('effective_at')));
        $this->post('/contacts/suppressions', ['phone' => '0241234567', 'reason' => 'Later', 'effective_at' => now()->addDays(2)->format('Y-m-d H:i:s')])->assertRedirect();
        $this->assertLessThanOrEqual(time(), strtotime(DB::table('suppression_entries')->value('effective_at')));
    }

    public function test_import_processes_in_bounded_chunks_and_resume_is_idempotent(): void
    {
        Queue::fake();
        $rows = [];
        for ($i = 0; $i < 251; $i++) {
            $rows[] = ['Contact '.$i, '024'.str_pad((string) $i, 7, '0', STR_PAD_LEFT), 'Form'];
        }
        $b = $this->stage($rows);
        app(Imports::class)->map($this->manager, $b, ['phone' => 1, 'name' => 0]);
        $b->update(['status' => 'queued']);
        app(Imports::class)->process($b->id);
        $this->assertSame(250, $b->fresh()->imported);
        $this->assertSame('processing', $b->fresh()->status);
        Queue::assertPushed(ImportContacts::class, 1);
        app(Imports::class)->process($b->id);
        app(Imports::class)->process($b->id);
        $this->assertSame(251, $b->fresh()->imported);
        $this->assertSame('completed', $b->fresh()->status);
        $this->assertSame(251, $this->group->contacts()->count());
        $this->assertDatabaseCount('contact_consents', 0);
    }

    public function test_legacy_xls_import_preserves_text_phone_and_rejects_formulas(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'contacts-xls');
        $book = new Spreadsheet;
        try {
            $sheet = $book->getActiveSheet();
            $sheet->setCellValue('A1', 'phone');
            $sheet->setCellValueExplicit('A2', '0241234567', DataType::TYPE_STRING);
            $writer = new Xls($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
            $file = new UploadedFile($path, 'legacy.xls', null, null, true);
            [$headers,$rows] = app(ImportReader::class)->read($file);
            $this->assertSame(['phone'], $headers);
            $this->assertSame([['0241234567']], $rows);
            $sheet->setCellValue('A2', '=1+1');
            $writer->save($path);
            $this->expectException(ValidationException::class);
            app(ImportReader::class)->read($file);
        } finally {
            $book->disconnectWorksheets();
            unlink($path);
        }
    }

    public function test_excel_missing_zero_is_restored_and_deduplicated_across_formats(): void
    {
        $b = $this->stage([['Excel numeric', '241234567', 'Sheet'], ['National', '0241234567', 'Form'], ['International', '233241234567', 'Form'], ['Invalid', '123456789', 'Sheet']]);
        app(Imports::class)->map($this->manager, $b, ['phone' => 1, 'name' => 0]);
        $rows = DB::table('contact_import_rows')->where('contact_import_id', $b->id)->orderBy('id')->get();
        $this->assertSame('+233241234567', $rows[0]->phone_e164);
        $this->assertSame('valid', $rows[0]->state);
        $this->assertSame('duplicate', $rows[1]->state);
        $this->assertSame('duplicate', $rows[2]->state);
        $this->assertSame('invalid', $rows[3]->state);
        $b->update(['status' => 'queued']);
        app(Imports::class)->process($b->id);
        $this->assertDatabaseCount('contacts', 1);
        $this->assertSame('+233241234567', Contact::firstOrFail()->phone_e164);
    }
}
