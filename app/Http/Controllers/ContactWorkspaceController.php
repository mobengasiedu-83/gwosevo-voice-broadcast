<?php

namespace App\Http\Controllers;

use App\Jobs\ImportContacts;
use App\Models\Contact;
use App\Models\ContactConsent;
use App\Models\ContactGroup;
use App\Models\ContactImport;
use App\Services\Broadcast\Engine;
use App\Services\Clients\Tenancy;
use App\Services\Contacts\ImportReader;
use App\Services\Contacts\Imports;
use App\Services\Contacts\Suppression;
use App\Services\Foundation;
use App\Services\GhanaPhone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ContactWorkspaceController extends Controller
{
    private function normalizedSearch(?string $value): ?string
    {
        try {
            return $value ? GhanaPhone::normalize($value) : null;
        } catch (ValidationException $e) {
            return null;
        }
    }

    private function groupsQuery(Request $r)
    {
        return ContactGroup::query()->when(app(Tenancy::class)->current() === null && (! $r->user()->can('audit.view')), fn ($q) => $q->where('owner_id', $r->user()->id));
    }

    private function groupAccess(Request $r, ContactGroup $group, bool $write = false): void
    {
        Gate::authorize($write ? 'contacts.manage' : 'contacts.view');
        abort_unless(app(Tenancy::class)->sameAccount($r->user(), $group) || $group->owner_id === $r->user()->id || $r->user()->isSuperAdministrator() || (! $write && $r->user()->can('audit.view')), 403);
    }

    public function groups(Request $r)
    {
        Gate::authorize('contacts.view');
        $f = $r->validate(['q' => 'nullable|string|max:100', 'category' => 'nullable|string|max:40', 'active' => 'nullable|in:1,0', 'sort' => 'nullable|in:newest,oldest,name']);
        $query = $this->groupsQuery($r);
        $total = (clone $query)->count();
        if (! empty($f['q'])) {
            $query->where('name', 'like', '%'.addcslashes($f['q'], '%_\\').'%');
        }if (! empty($f['category'])) {
            $query->where('category', $f['category']);
        }if (isset($f['active'])) {
            $query->where('active', $f['active']);
        }
        if (($f['sort'] ?? '') === 'name') {
            $query->orderBy('name');
        } else {
            $query->orderBy('id', ($f['sort'] ?? '') === 'oldest' ? 'asc' : 'desc');
        }
        $groups = $query->withCount('contacts')->paginate(15)->withQueryString();
        $stats = ['groups' => $total, 'contacts' => Contact::whereNotNull('phone_e164')->count(), 'imports' => ContactImport::when(app(Tenancy::class)->current() === null && ! $r->user()->isSuperAdministrator(), fn ($q) => $q->where('owner_id', $r->user()->id))->where('created_at', '>=', now()->startOfMonth())->count(), 'suppressed' => app(Suppression::class)->visible()->where('effective_at', '<=', now())->count()];

        return response()->view('contacts.groups', ['groups' => $groups, 'stats' => $stats, 'f' => $f, 'categories' => $this->groupsQuery($r)->select('category')->distinct()->orderBy('category')->pluck('category'), 'uploadGroups' => ContactGroup::when(app(Tenancy::class)->current() === null && ! $r->user()->isSuperAdministrator(), fn ($q) => $q->where('owner_id', $r->user()->id))->where('active', true)->orderBy('name')->get()])->header('Cache-Control', 'private, no-store');
    }

    public function saveGroup(Request $r, Foundation $audit, ?ContactGroup $group = null)
    {
        Gate::authorize('contacts.manage');
        if ($group) {
            $this->groupAccess($r, $group, true);
        }
        $d = $r->validate(['name' => ['required', 'string', 'max:255', Rule::unique('contact_groups')->where('owner_id', $group?->owner_id ?? $r->user()->id)->ignore($group?->id)], 'description' => 'nullable|string|max:2000', 'category' => 'required|string|max:40', 'active' => 'required|boolean']);
        DB::transaction(function () use ($r, $d, $group, $audit) {
            $g = $group ? ContactGroup::whereKey($group->id)->lockForUpdate()->firstOrFail() : new ContactGroup(['owner_id' => $r->user()->id]);
            $g->fill($d)->save();
            $audit->audit($r->user(), $group ? 'contact_group.updated' : 'contact_group.created', $g);
        });

        return redirect()->route('contacts.groups')->with('status', 'Group saved. Existing campaign approval snapshots remain unchanged.');
    }

    public function group(Request $r, ContactGroup $group)
    {
        $this->groupAccess($r, $group);
        $q = $r->validate(['q' => 'nullable|string|max:100']);
        $members = $group->contacts()->when(! empty($q['q']), fn ($b) => $b->where(fn ($c) => $c->where('contacts.name', 'like', '%'.$q['q'].'%')->orWhere('phone_e164', 'like', '%'.$q['q'].'%')))->orderBy('contacts.name')->paginate(25)->withQueryString();

        return response()->view('contacts.group', compact('group', 'members', 'q'))->header('Cache-Control', 'private, no-store');
    }

    public function membership(Request $r, ContactGroup $group, Foundation $audit)
    {
        $this->groupAccess($r, $group, true);
        $d = $r->validate(['contact_id' => 'required|integer|min:1', 'action' => 'required|in:add,remove']);
        Contact::findOrFail($d['contact_id']);
        DB::transaction(function () use ($r, $group, $d, $audit) {
            $g = ContactGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            if ($d['action'] === 'add') {
                $g->contacts()->syncWithoutDetaching([$d['contact_id']]);
            } else {
                $g->contacts()->detach($d['contact_id']);
            }$audit->audit($r->user(), 'contact_group.member_'.$d['action'], $g, ['contact_id' => (int) $d['contact_id']]);
        });

        return back()->with('status', 'Membership updated. Approved campaign audiences are unchanged.');
    }

    public function directory(Request $r)
    {
        Gate::authorize('contacts.view');
        $f = $r->validate(['q' => 'nullable|string|max:100', 'group_id' => 'nullable|integer', 'consent' => 'nullable|in:granted,revoked', 'suppressed' => 'nullable|in:1']);
        $query = Contact::with('groups')->whereNotNull('phone_e164');
        if (! empty($f['q'])) {
            $q = '%'.addcslashes($f['q'], '%_\\').'%';
            $phone = $this->normalizedSearch($f['q']);
            $query->where(fn ($b) => $b->where('name', 'like', $q)->orWhere('phone_e164', 'like', $q)->when($phone, fn ($b) => $b->orWhere('phone_e164', $phone)));
        }
        if (! empty($f['group_id'])) {
            $g = $this->groupsQuery($r)->findOrFail($f['group_id']);
            $query->whereHas('groups', fn ($b) => $b->where('contact_groups.id', $g->id));
        }
        if (! empty($f['consent'])) {
            $query->whereHas('consents', fn ($b) => $f['consent'] === 'granted' ? $b->whereNull('revoked_at')->where('granted_at', '<=', now()) : $b->whereNotNull('revoked_at'));
        }
        if (! empty($f['suppressed'])) {
            $query->whereIn('phone_e164', app(Suppression::class)->visible()->where('effective_at', '<=', now())->select('phone_e164'));
        }
        $contacts = $query->withCount(['consents as active_consents_count' => fn ($b) => $b->whereNull('revoked_at')->where('granted_at', '<=', now())])->latest()->paginate(25)->withQueryString();
        $suppressed = app(Suppression::class)->visible()->whereIn('phone_e164', $contacts->pluck('phone_e164'))->where('effective_at', '<=', now())->pluck('phone_e164')->flip();

        return response()->view('contacts.directory', ['contacts' => $contacts, 'f' => $f, 'groups' => $this->groupsQuery($r)->orderBy('name')->get(), 'suppressed' => $suppressed])->header('Cache-Control', 'private, no-store');
    }

    public function contact(Request $r, Contact $contact)
    {
        Gate::authorize('contacts.view');

        return response()->view('contacts.contact', ['contact' => $contact->load(['consents', 'groups']), 'suppression' => app(Suppression::class)->visible()->where('phone_e164', $contact->phone_e164)->first(), 'groups' => ContactGroup::when(app(Tenancy::class)->current() === null && ! $r->user()->isSuperAdministrator(), fn ($q) => $q->where('owner_id', $r->user()->id))->orderBy('name')->get(), 'history' => DB::table('audit_logs')->where('entity_type', $contact->getMorphClass())->where('entity_id', $contact->id)->orderByDesc('id')->paginate(20)])->header('Cache-Control', 'private, no-store');
    }

    public function linkGroup(Request $r, Contact $contact, Foundation $audit)
    {
        Gate::authorize('contacts.manage');
        $d = $r->validate(['group_id' => 'required|integer|min:1']);
        $group = ContactGroup::findOrFail($d['group_id']);
        $this->groupAccess($r, $group, true);
        DB::transaction(function () use ($r, $contact, $group, $audit) {
            $g = ContactGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $g->contacts()->syncWithoutDetaching([$contact->id]);
            $audit->audit($r->user(), 'contact.grouped', $contact, ['group_id' => $g->id]);
        });

        return back()->with('status', 'Contact added to group.');
    }

    public function updateContact(Request $r, Contact $contact, Foundation $audit)
    {
        Gate::authorize('contacts.manage');
        $d = $r->validate(['name' => 'required|string|max:255', 'source' => 'required|string|max:255']);
        DB::transaction(function () use ($r, $contact, $d, $audit) {
            $contact->update($d);
            $audit->audit($r->user(), 'contact.updated', $contact);
        });

        return back()->with('status', 'Contact updated. Phone changes require a new contact and new consent evidence.');
    }

    public function suppressions(Request $r)
    {
        Gate::authorize('contacts.view');
        $f = $r->validate(['q' => 'nullable|string|max:100']);
        $query = app(Suppression::class)->visible();
        if (! empty($f['q'])) {
            $phone = $this->normalizedSearch($f['q']);
            $query->where(fn ($b) => $b->where('phone_e164', 'like', '%'.addcslashes($f['q'], '%_\\').'%')->when($phone, fn ($b) => $b->orWhere('phone_e164', $phone)));
        }

        return response()->view('contacts.suppressions', ['entries' => $query->orderByDesc('id')->paginate(25)->withQueryString(), 'f' => $f])->header('Cache-Control', 'private, no-store');
    }

    public function suppress(Request $r, Engine $engine, Foundation $audit)
    {
        Gate::authorize('contacts.manage');
        $d = $r->validate(['phone' => 'required|string|max:40', 'reason' => 'required|string|max:255', 'effective_at' => 'nullable|date']);
        $phone = GhanaPhone::normalize($d['phone']);
        DB::transaction(function () use ($r, $engine, $audit, $phone, $d) {
            $engine->mutex();
            app(Suppression::class)->add($phone, $d['reason'], $r->user()->id, $d['effective_at'] ?? now());
            $audit->audit($r->user(), 'number.suppressed', $r->user(), ['scope' => app(Tenancy::class)->current() === null ? 'global' : 'client']);
        });

        return back()->with('status', 'Suppression recorded. Matching numbers are excluded at dispatch from the effective time.');
    }

    public function consents(Request $r)
    {
        Gate::authorize('contacts.view');
        $f = $r->validate(['q' => 'nullable|string|max:100', 'state' => 'nullable|in:active,revoked']);
        $query = ContactConsent::join('contacts as c', 'c.id', '=', 'contact_consents.contact_id')->select('contact_consents.*', 'c.name', 'c.phone_e164');
        if (! empty($f['q'])) {
            $q = '%'.addcslashes($f['q'], '%_\\').'%';
            $query->where(fn ($b) => $b->where('c.name', 'like', $q)->orWhere('c.phone_e164', 'like', $q)->orWhere('purpose', 'like', $q));
        }if (($f['state'] ?? '') === 'active') {
            $query->whereNull('revoked_at')->where('granted_at', '<=', now());
        }if (($f['state'] ?? '') === 'revoked') {
            $query->whereNotNull('revoked_at');
        }

        return response()->view('contacts.consents', ['consents' => $query->orderByDesc('contact_consents.id')->paginate(25)->withQueryString(), 'f' => $f])->header('Cache-Control', 'private, no-store');
    }

    public function imports(Request $r)
    {
        Gate::authorize('contacts.manage');

        return response()->view('contacts.imports', ['batches' => ContactImport::when(app(Tenancy::class)->current() === null && ! $r->user()->isSuperAdministrator(), fn ($q) => $q->where('owner_id', $r->user()->id))->latest()->paginate(25), 'groups' => ContactGroup::when(app(Tenancy::class)->current() === null && ! $r->user()->isSuperAdministrator(), fn ($q) => $q->where('owner_id', $r->user()->id))->where('active', true)->orderBy('name')->get()])->header('Cache-Control', 'private, no-store');
    }

    public function upload(Request $r, ImportReader $reader, Imports $imports)
    {
        Gate::authorize('contacts.manage');
        $d = $r->validate(['group_id' => 'required|integer|min:1', 'file' => 'required|file|max:10240']);
        $g = ContactGroup::findOrFail($d['group_id']);
        $this->groupAccess($r, $g, true);
        [$headers,$rows] = $reader->read($r->file('file'));
        $b = $imports->stage($r->user(), $g, $r->file('file')->getClientOriginalName(), $headers, $rows);

        return redirect()->route('contacts.import', $b);
    }

    public function batch(Request $r, ContactImport $batch, Imports $imports)
    {
        $imports->access($r->user(), $batch);

        if ($r->expectsJson()) {
            return response()->json($batch->only(['status', 'total', 'imported', 'duplicates', 'invalid']))->header('Cache-Control', 'private, no-store');
        }

        return response()->view('contacts.import', ['batch' => $batch, 'rows' => DB::table('contact_import_rows')->where('contact_import_id', $batch->id)->orderBy('row_number')->paginate(50)])->header('Cache-Control', 'private, no-store');
    }

    public function map(Request $r, ContactImport $batch, Imports $imports)
    {
        $imports->access($r->user(), $batch);
        $d = $r->validate(['phone' => 'required|integer|min:0|max:49', 'name' => 'nullable|integer|min:0|max:49', 'source' => 'nullable|integer|min:0|max:49']);
        $imports->map($r->user(), $batch, array_filter($d, fn ($v) => $v !== null));

        return back()->with('status', 'Preview validated. Invalid and duplicate rows are quarantined; no consent is created by importing.');
    }

    public function confirm(Request $r, ContactImport $batch, Imports $imports, Foundation $audit)
    {
        $imports->access($r->user(), $batch);
        $r->validate(['confirm' => 'required|accepted']);
        DB::transaction(function () use ($r, $batch, $audit) {
            $b = ContactImport::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if (in_array($b->status, ['queued', 'processing', 'completed'])) {
                return;
            }abort_unless(in_array($b->status, ['preview', 'failed', 'blocked']), 422);
            $g = ContactGroup::findOrFail($b->contact_group_id);
            $this->groupAccess($r, $g, true);
            abort_unless($g->active, 422, 'Activate the group first.');
            $b->update(['status' => 'queued']);
            $audit->audit($r->user(), 'contacts.import_confirmed', $b);
            DB::afterCommit(function () use ($b) {
                try {
                    ImportContacts::dispatch($b->id);
                } catch (\Throwable $e) {
                    ContactImport::whereKey($b->id)->where('status', 'queued')->update(['status' => 'failed']);
                    throw ValidationException::withMessages(['import' => 'Queue unavailable. Restore the worker connection and resume this batch.']);
                }
            });
        });

        return back()->with('status', 'Import queued. Progress appears here and in import history.');
    }

    public function errors(Request $r, ContactImport $batch, Imports $imports)
    {
        $imports->access($r->user(), $batch);

        return response()->streamDownload(function () use ($batch) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['row', 'error', 'original cells'], ',', '"', '');
            foreach (DB::table('contact_import_rows')->where('contact_import_id', $batch->id)->whereIn('state', ['invalid', 'duplicate'])->orderBy('row_number')->cursor() as $row) {
                $raw = implode(' | ', json_decode($row->raw, true));
                if (preg_match('/^[\s]*[=+@-]/u', $raw)) {
                    $raw = "'".$raw;
                }fputcsv($out, [$row->row_number, $row->error, $raw], ',', '"', '');
            }fclose($out);
        }, 'import-'.$batch->id.'-errors.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function template()
    {
        Gate::authorize('contacts.manage');

        return response("name,phone,source\nExample Contact,0241234567,Customer form\n", 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="contacts-template.csv"', 'Cache-Control' => 'private, no-store']);
    }
}
