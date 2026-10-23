<?php

namespace App\Services\Contacts;

use App\Jobs\ImportContacts;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ContactImport;
use App\Models\User;
use App\Services\Foundation;
use App\Services\GhanaPhone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Imports
{
    public function access(User $u, ContactImport $batch): void
    {
        Gate::forUser($u)->authorize('contacts.manage');
        abort_unless($batch->owner_id === $u->id || $u->isSuperAdministrator(), 403);
    }

    public function stage(User $u, ContactGroup $group, string $filename, array $headers, array $rows): ContactImport
    {
        Gate::forUser($u)->authorize('contacts.manage');
        abort_unless($group->owner_id === $u->id || $u->isSuperAdministrator(), 403);
        abort_unless($group->active, 422, 'Activate the group before importing.');

        return DB::transaction(function () use ($u, $group, $filename, $headers, $rows) {
            $b = ContactImport::create(['owner_id' => $u->id, 'contact_group_id' => $group->id, 'filename' => mb_substr(basename($filename), 0, 255), 'headers' => $headers, 'total' => count($rows)]);
            foreach (array_chunk($rows, 250, true) as $chunk) {
                $insert = [];
                foreach ($chunk as $n => $row) {
                    $insert[] = ['contact_import_id' => $b->id, 'row_number' => $n + 2, 'raw' => json_encode($row, JSON_THROW_ON_ERROR)];
                }DB::table('contact_import_rows')->insert($insert);
            }
            app(Foundation::class)->audit($u, 'contacts.import_staged', $b, ['rows' => $b->total, 'group_id' => $group->id]);

            return $b;
        });
    }

    public function map(User $u, ContactImport $batch, array $mapping): void
    {
        $this->access($u, $batch);
        DB::transaction(function () use ($batch, $mapping, $u) {
            $b = ContactImport::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($b->status, ['mapping', 'preview']), 422);
            foreach (['phone', 'name', 'source'] as $field) {
                if (isset($mapping[$field]) && ($mapping[$field] < 0 || $mapping[$field] >= count($b->headers))) {
                    throw ValidationException::withMessages(['phone' => 'Choose a valid column.']);
                }
            }
            $seen = [];
            $invalid = 0;
            $duplicates = 0;
            $existing = Contact::whereNotNull('phone_e164')->pluck('phone_e164')->flip();
            foreach (DB::table('contact_import_rows')->where('contact_import_id', $b->id)->orderBy('id')->cursor() as $row) {
                $raw = json_decode($row->raw, true);
                $phone = null;
                $error = null;
                $state = 'valid';
                try {
                    $phone = GhanaPhone::normalizeImported(trim((string) ($raw[$mapping['phone']] ?? '')));
                } catch (ValidationException $e) {
                    $error = 'Invalid Ghana phone number.';
                    $state = 'invalid';
                    $invalid++;
                }
                $name = isset($mapping['name']) ? trim((string) ($raw[$mapping['name']] ?? '')) : '';
                $source = isset($mapping['source']) ? trim((string) ($raw[$mapping['source']] ?? '')) : '';
                if (mb_strlen($name) > 255 || mb_strlen($source) > 255) {
                    if ($state !== 'invalid') {
                        $invalid++;
                    }$state = 'invalid';
                    $error = 'Name and source must be at most 255 characters.';
                }
                if ($state !== 'invalid' && isset($seen[$phone])) {
                    $state = 'duplicate';
                    $error = 'Duplicate within this upload.';
                    $duplicates++;
                } elseif ($state !== 'invalid') {
                    $seen[$phone] = true;
                    if (isset($existing[$phone])) {
                        $state = 'existing';
                        $duplicates++;
                    }
                }
                DB::table('contact_import_rows')->where('id', $row->id)->update(['phone_e164' => $phone, 'name' => $name ?: null, 'source' => $source ?: 'Contact import', 'state' => $state, 'error' => $error]);
            }
            $b->update(['mapping' => $mapping, 'status' => 'preview', 'invalid' => $invalid, 'duplicates' => $duplicates]);
            app(Foundation::class)->audit($u, 'contacts.import_validated', $b, ['invalid' => $invalid, 'duplicates' => $duplicates]);
        });
    }

    public function process(int $id): void
    {
        DB::transaction(function () use ($id) {
            $b = ContactImport::whereKey($id)->lockForUpdate()->firstOrFail();
            if (! in_array($b->status, ['queued', 'processing'])) {
                return;
            }
            $u = User::findOrFail($b->owner_id);
            $group = ContactGroup::whereKey($b->contact_group_id)->lockForUpdate()->firstOrFail();
            if (! $u->can('contacts.manage') || $group->owner_id !== $u->id || ! $group->active) {
                $b->update(['status' => 'blocked']);

                return;
            }
            $b->update(['status' => 'processing']);
            $rows = DB::table('contact_import_rows')->where('contact_import_id', $id)->whereNull('committed_at')->whereIn('state', ['valid', 'existing'])->orderBy('id')->limit(250)->get();
            $new = 0;
            foreach ($rows as $r) {
                $c = Contact::firstOrCreate(['phone_e164' => $r->phone_e164], ['uuid' => (string) Str::uuid(), 'name' => $r->name, 'source' => $r->source, 'source_phone' => $r->phone_e164, 'created_by' => $u->id]);
                $group->contacts()->syncWithoutDetaching([$c->id]);
                $created = $c->wasRecentlyCreated;
                DB::table('contact_import_rows')->where('id', $r->id)->update(['state' => $created ? 'imported' : 'linked', 'committed_at' => now()]);
                $new++;
            }
            $b->increment('imported', $new);
            $more = DB::table('contact_import_rows')->where('contact_import_id', $id)->whereNull('committed_at')->whereIn('state', ['valid', 'existing'])->exists();
            if (! $more) {
                $b->update(['status' => 'completed']);
                app(Foundation::class)->audit($u, 'contacts.import_completed', $b, ['linked_rows' => $b->imported]);
            } else {
                DB::afterCommit(fn () => ImportContacts::dispatch($id));
            }
        });
    }
}
