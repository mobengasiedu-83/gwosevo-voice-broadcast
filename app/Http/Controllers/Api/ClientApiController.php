<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ImportContacts;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ContactImport;
use App\Services\Clients\Campaigns;
use App\Services\Contacts\Imports;
use App\Services\Integrations\Access;
use App\Services\Reports\Analytics;
use Illuminate\Http\Request;

class ClientApiController extends Controller
{
    public function contacts(Request $r)
    {
        return response()->json(Contact::where('client_account_id', $r->user()->client_account_id)->orderBy('id')->paginate(50, ['id', 'name', 'phone_e164', 'created_at']));
    }

    public function groups(Request $r)
    {
        return response()->json(ContactGroup::where('client_account_id', $r->user()->client_account_id)->where('active', true)->orderBy('id')->paginate(50, ['id', 'name']));
    }

    public function import(Request $r, Imports $imports)
    {
        $data = $r->validate(['group_id' => 'required|integer', 'rows' => 'required|array|min:1|max:1000', 'rows.*.phone' => 'required|string|max:40', 'rows.*.name' => 'nullable|string|max:255', 'rows.*.source' => 'nullable|string|max:255']);
        $group = ContactGroup::where('client_account_id', $r->user()->client_account_id)->findOrFail($data['group_id']);
        app(Access::class)->consume($r->user()->client_account_id, 'import_rows_per_day', count($data['rows']));
        $rows = array_map(fn ($row) => [$row['phone'], $row['name'] ?? '', $row['source'] ?? 'API import'], $data['rows']);
        $batch = $imports->stage($r->user(), $group, 'api-import.json', ['phone', 'name', 'source'], $rows);
        $imports->map($r->user(), $batch, ['phone' => 0, 'name' => 1, 'source' => 2]);
        return response()->json($this->batch($batch->fresh()), 201);
    }

    private function batch(ContactImport $batch): array
    {
        return $batch->only(['id', 'status', 'total', 'invalid', 'duplicates', 'imported', 'created_at']);
    }

    public function importStatus(Request $r, int $id)
    {
        $batch = ContactImport::where('client_account_id', $r->user()->client_account_id)->findOrFail($id);
        return response()->json($this->batch($batch));
    }

    public function confirmImport(Request $r, int $id, Imports $imports)
    {
        $batch = ContactImport::where('client_account_id', $r->user()->client_account_id)->lockForUpdate()->findOrFail($id);
        $imports->access($r->user(), $batch);
        abort_unless($batch->status === 'preview', 422, 'Only a previewed import can be confirmed.');
        $batch->update(['status' => 'queued']);
        ImportContacts::dispatch($batch->id)->afterCommit();
        return response()->json($this->batch($batch), 202);
    }

    public function campaigns(Request $r)
    {
        return response()->json(Campaign::where('client_account_id', $r->user()->client_account_id)->orderBy('id')->paginate(50, ['id', 'name', 'type', 'status', 'created_at']));
    }

    public function campaign(Request $r, int $id)
    {
        $campaign = Campaign::where('client_account_id', $r->user()->client_account_id)->findOrFail($id);
        return response()->json($campaign->only(['id', 'name', 'type', 'status', 'scheduled_at', 'created_at']));
    }

    public function draft(Request $r, Campaigns $campaigns)
    {
        $data = $r->validate(['name' => 'required|string|max:240', 'purpose' => 'required|string|max:255', 'source_group_id' => 'required|integer', 'audio_id' => 'required|integer', 'timezone' => 'required|timezone', 'window_start' => 'required|date_format:H:i', 'window_end' => 'required|date_format:H:i|after:window_start', 'max_attempts' => 'required|integer|min:1|max:5', 'retry_delay_seconds' => 'required|integer|min:60|max:86400', 'retry_outcomes' => 'nullable|array|max:3', 'retry_outcomes.*' => 'in:busy,no_answer,failed', 'excluded_dates_text' => 'nullable|string|max:2000', 'expires_on' => 'nullable|date_format:Y-m-d']);
        app(Access::class)->consume($r->user()->client_account_id, 'drafts_per_day');
        $campaign = $campaigns->save($r->user(), null, $data);
        $campaign->update(['status' => 'draft']);
        return response()->json($campaign->only(['id', 'name', 'status', 'type']), 201);
    }

    public function reports(Request $r, Analytics $analytics)
    {
        $filters = $analytics->filters($r->user(), $r->query());
        $rows = $analytics->query($r->user(), $filters)->orderBy(match ($filters['kind']) {'campaigns' => 'c.id', 'attempts' => 'a.id', 'billing' => 'l.id', 'ivr' => 'd.id'})->paginate(50);
        return response()->json(['filters' => $filters, 'summary' => $analytics->summary($r->user(), $filters), 'headers' => $analytics->headers($filters['kind']), 'data' => $rows->getCollection()->map(fn ($row) => $analytics->row($filters['kind'], $row)), 'page' => $rows->currentPage(), 'last_page' => $rows->lastPage()]);
    }
}
