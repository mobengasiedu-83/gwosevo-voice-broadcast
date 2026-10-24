<?php

namespace App\Http\Controllers;

use App\Jobs\ImportContacts;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ContactImport;
use App\Services\Broadcast\Engine;
use App\Services\Clients\Portal;
use App\Services\Contacts\ImportReader;
use App\Services\Contacts\Imports;
use App\Services\Contacts\Suppression;
use App\Services\Foundation;
use App\Services\GhanaPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientContactsController extends Controller
{
    private function shared(Request $r): array
    {
        app(Portal::class)->authorize($r->user());

        return ['account' => $r->user()->clientAccount()->firstOrFail(), 'unread' => app(Portal::class)->unread($r->user())];
    }

    private function owned(Request $r, $record): void
    {
        $id = app(Portal::class)->authorize($r->user());
        abort_unless((int) $record->client_account_id === $id, 404);
    }

    private function groupsQuery(Request $r)
    {
        return ContactGroup::where('client_account_id', app(Portal::class)->authorize($r->user()));
    }

    private function contactsQuery(Request $r)
    {
        return Contact::where('client_account_id', app(Portal::class)->authorize($r->user()));
    }

    private function suppressed($phones)
    {
        // Expose only a DND flag for the client's own contacts, never another account's reasons or identity.
        return DB::table('suppression_entries')->whereIn('phone_e164', $phones)->where('effective_at', '<=', now())->pluck('phone_e164')->flip();
    }

    public function directory(Request $r)
    {
        $f = $r->validate(['q' => 'nullable|string|max:100', 'group_id' => 'nullable|integer', 'dnd' => 'nullable|in:1']);
        $query = $this->contactsQuery($r);
        if (! empty($f['q'])) {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            try {
                $phone = GhanaPhone::normalize($f['q']);
            } catch (ValidationException $e) {
                $phone = null;
            }
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('phone_e164', 'like', $term)->when($phone, fn ($q) => $q->orWhere('phone_e164', $phone)));
        }
        if (! empty($f['group_id'])) {
            $group = $this->groupsQuery($r)->findOrFail($f['group_id']);
            $query->whereHas('groups', fn ($q) => $q->where('contact_groups.id', $group->id));
        }
        if (! empty($f['dnd'])) {
            $query->whereIn('phone_e164', DB::table('suppression_entries')->where('effective_at', '<=', now())->select('phone_e164'));
        }
        $contacts = $query->with('groups')->latest()->paginate(25)->withQueryString();

        return response()->view('clients.contacts.directory', $this->shared($r) + ['contacts' => $contacts, 'groups' => $this->groupsQuery($r)->orderBy('name')->get(), 'suppressed' => $this->suppressed($contacts->pluck('phone_e164'))])->header('Cache-Control', 'private, no-store');
    }

    public function createContact(Request $r)
    {
        app(Portal::class)->authorize($r->user());
        $d = $r->validate(['name' => 'required|string|max:255', 'phone' => 'required|string|max:40', 'source' => 'nullable|string|max:255', 'group_id' => 'nullable|integer']);
        $phone = GhanaPhone::normalize($d['phone']);
        $group = ! empty($d['group_id']) ? $this->groupsQuery($r)->findOrFail($d['group_id']) : null;
        if ($group) {
            abort_unless($group->active, 422, 'Activate this group first.');
        }
        $created = DB::transaction(function () use ($r, $d, $phone, $group) {
            app(Engine::class)->mutex();
            $c = $this->contactsQuery($r)->firstOrCreate(['phone_e164' => $phone], ['name' => $d['name'], 'source' => ($d['source'] ?? null) ?: 'Manual entry', 'source_phone' => $d['phone'], 'created_by' => $r->user()->id]);
            if ($group) {
                $g = $this->groupsQuery($r)->whereKey($group->id)->lockForUpdate()->firstOrFail();
                abort_unless($g->active, 422, 'Activate this group first.');
                $g->contacts()->syncWithoutDetaching([$c->id]);
            }
            app(Foundation::class)->audit($r->user(), 'client.contact_saved', $c, ['group_id' => $group?->id, 'created' => $c->wasRecentlyCreated]);

            return $c;
        });

        return redirect()->route('client.contacts.show', $created)->with('status', 'Contact saved. Existing numbers are reused without overwriting their details.');
    }

    public function contact(Request $r, Contact $contact)
    {
        $this->owned($r, $contact);

        return response()->view('clients.contacts.contact', $this->shared($r) + ['contact' => $contact->load('groups'), 'groups' => $this->groupsQuery($r)->orderBy('name')->get(), 'dnd' => $this->suppressed([$contact->phone_e164])->isNotEmpty()])->header('Cache-Control', 'private, no-store');
    }

    public function updateContact(Request $r, Contact $contact)
    {
        $this->owned($r, $contact);
        $d = $r->validate(['name' => 'required|string|max:255', 'source' => 'required|string|max:255']);
        DB::transaction(function () use ($r, $contact, $d) {
            $contact->update($d);
            app(Foundation::class)->audit($r->user(), 'client.contact_updated', $contact);
        });

        return back()->with('status', 'Contact details updated.');
    }

    public function membership(Request $r, Contact $contact)
    {
        $this->owned($r, $contact);
        $d = $r->validate(['group_id' => 'required|integer', 'action' => 'required|in:add,remove']);
        $group = $this->groupsQuery($r)->findOrFail($d['group_id']);
        DB::transaction(function () use ($r, $contact, $d, $group) {
            app(Engine::class)->mutex();
            $group = $this->groupsQuery($r)->whereKey($group->id)->lockForUpdate()->firstOrFail();
            if ($d['action'] === 'add') {
                abort_unless($group->active, 422, 'Activate this group first.');
                $group->contacts()->syncWithoutDetaching([$contact->id]);
            } else {
                $group->contacts()->detach($contact->id);
            }
            app(Foundation::class)->audit($r->user(), 'client.membership_'.$d['action'], $contact, ['group_id' => $group->id]);
        });

        return back()->with('status', 'Group membership updated. Existing campaign snapshots are unchanged.');
    }

    public function groups(Request $r)
    {
        $d = $r->validate(['q' => 'nullable|string|max:100', 'active' => 'nullable|in:0,1']);
        $query = $this->groupsQuery($r);
        if (! empty($d['q'])) {
            $query->where('name', 'like', '%'.addcslashes($d['q'], '%_\\').'%');
        }
        if (isset($d['active'])) {
            $query->where('active', $d['active']);
        }

        return view('clients.contacts.groups', $this->shared($r) + ['groups' => $query->withCount('contacts')->latest()->paginate(15)->withQueryString()]);
    }

    public function saveGroup(Request $r, ?ContactGroup $group = null)
    {
        $id = app(Portal::class)->authorize($r->user());
        if ($group) {
            $this->owned($r, $group);
        }
        $d = $r->validate(['name' => ['required', 'string', 'max:255', Rule::unique('contact_groups')->where('client_account_id', $id)->ignore($group?->id)], 'description' => 'nullable|string|max:2000', 'category' => 'required|string|max:40', 'active' => 'required|boolean']);
        $saved = DB::transaction(function () use ($r, $d, $group) {
            app(Engine::class)->mutex();
            if ($this->groupsQuery($r)->where('name', $d['name'])->when($group, fn ($q) => $q->where('id', '!=', $group->id))->exists()) {
                throw ValidationException::withMessages(['name' => 'This group name is already in use.']);
            }
            $g = $group ?: new ContactGroup(['owner_id' => $r->user()->id]);
            $g->fill($d)->save();
            app(Foundation::class)->audit($r->user(), 'client.group_saved', $g);

            return $g;
        });

        return redirect()->route('client.groups.show', $saved)->with('status', 'Group saved.');
    }

    public function group(Request $r, ContactGroup $group)
    {
        $this->owned($r, $group);
        $members = $group->contacts()->orderBy('contacts.name')->paginate(25);

        return view('clients.contacts.group', $this->shared($r) + ['group' => $group, 'members' => $members, 'suppressed' => $this->suppressed($members->pluck('phone_e164'))]);
    }

    public function dnd(Request $r, Contact $contact)
    {
        $this->owned($r, $contact);
        $d = $r->validate(['reason' => 'required|string|max:255']);
        DB::transaction(function () use ($r, $contact, $d) {
            app(Engine::class)->mutex();
            app(Suppression::class)->add($contact->phone_e164, $d['reason'], $r->user()->id, now());
            app(Foundation::class)->audit($r->user(), 'client.contact_dnd', $contact);
        });

        return back()->with('status', 'Number excluded from broadcasts. DND applies globally; only internal staff can review its removal.');
    }

    public function imports(Request $r)
    {
        $id = app(Portal::class)->authorize($r->user());

        return view('clients.contacts.imports', $this->shared($r) + ['batches' => ContactImport::where('client_account_id', $id)->latest()->paginate(20), 'groups' => $this->groupsQuery($r)->where('active', true)->orderBy('name')->get()]);
    }

    public function upload(Request $r, ImportReader $reader, Imports $imports)
    {
        app(Portal::class)->authorize($r->user());
        $d = $r->validate(['group_id' => 'required|integer', 'file' => 'required|file|max:10240']);
        $group = $this->groupsQuery($r)->findOrFail($d['group_id']);
        [$headers, $rows] = $reader->read($r->file('file'));
        $batch = $imports->stage($r->user(), $group, $r->file('file')->getClientOriginalName(), $headers, $rows);

        return redirect()->route('client.imports.show', $batch);
    }

    public function batch(Request $r, ContactImport $batch, Imports $imports)
    {
        $this->owned($r, $batch);
        $imports->access($r->user(), $batch);
        if ($r->expectsJson()) {
            return response()->json($batch->only(['status', 'total', 'imported', 'duplicates', 'invalid']))->header('Cache-Control', 'private, no-store');
        }

        return response()->view('clients.contacts.import', $this->shared($r) + ['batch' => $batch, 'rows' => DB::table('contact_import_rows')->where('contact_import_id', $batch->id)->orderBy('row_number')->paginate(50)])->header('Cache-Control', 'private, no-store');
    }

    public function map(Request $r, ContactImport $batch, Imports $imports)
    {
        $this->owned($r, $batch);
        $d = $r->validate(['phone' => 'required|integer|min:0|max:49', 'name' => 'nullable|integer|min:0|max:49', 'source' => 'nullable|integer|min:0|max:49']);
        $imports->map($r->user(), $batch, array_filter($d, fn ($v) => $v !== null));

        return back()->with('status', 'Preview ready. Invalid and repeated rows will be skipped.');
    }

    public function confirm(Request $r, ContactImport $batch, Imports $imports)
    {
        $this->owned($r, $batch);
        $imports->access($r->user(), $batch);
        $r->validate(['confirm' => 'required|accepted']);
        DB::transaction(function () use ($r, $batch) {
            app(Engine::class)->mutex();
            $b = ContactImport::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if (in_array($b->status, ['queued', 'processing', 'completed'])) {
                return;
            }
            abort_unless(in_array($b->status, ['preview', 'failed', 'blocked']), 422);
            $g = $this->groupsQuery($r)->findOrFail($b->contact_group_id);
            abort_unless($g->active && $b->mapping !== null, 422, 'Activate the group and validate the mapping first.');
            $b->update(['status' => 'queued']);
            app(Foundation::class)->audit($r->user(), 'client.import_confirmed', $b);
            DB::afterCommit(function () use ($b) {
                try {
                    ImportContacts::dispatch($b->id);
                } catch (\Throwable $e) {
                    ContactImport::whereKey($b->id)->where('status', 'queued')->update(['status' => 'failed']);
                    throw ValidationException::withMessages(['import' => 'Queue unavailable. Restore the default queue worker, then resume this import.']);
                }
            });
        });

        return back()->with('status', 'Import queued. Refresh this page to see progress.');
    }

    public function errors(Request $r, ContactImport $batch, Imports $imports)
    {
        $this->owned($r, $batch);
        $imports->access($r->user(), $batch);

        // Reuse the existing escaped CSV export after enforcing the client boundary.
        return app(ContactWorkspaceController::class)->errors($r, $batch, $imports);
    }

    public function template(Request $r)
    {
        app(Portal::class)->authorize($r->user());

        return response("name,phone,source\nExample Contact,0241234567,Customer form\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="contacts-template.csv"', 'Cache-Control' => 'private, no-store']);
    }
}
