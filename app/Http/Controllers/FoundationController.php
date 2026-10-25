<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactConsent;
use App\Models\ContactGroup;
use App\Services\Broadcast\Engine;
use App\Services\Clients\Tenancy;
use App\Services\Contacts\Suppression;
use App\Services\Foundation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class FoundationController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return view('foundation.index', [
            'contacts' => $user->can('contacts.view') ? Contact::with(['consents', 'groups'])->latest()->paginate(20, ['*'], 'contacts_page') : null,
            'campaigns' => $user->can('campaigns.view') ? Campaign::when(app(Tenancy::class)->current() === null && ! $user->can('campaigns.approve') && ! $user->can('audit.view') && ! $user->can('reports.view') && ! $user->can('operations.view'), fn ($q) => $q->where('owner_id', $user->id))->latest()->paginate(20, ['*'], 'campaigns_page') : null,
            'audits' => $user->can('audit.view') ? AuditLog::latest('id')->paginate(20, ['*'], 'audit_page') : null,
        ]);
    }

    public function contact(Request $request, Foundation $service)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'phone' => 'required|string|max:40', 'source' => 'required|string|max:255']);
        try {
            $service->createContact($request->user(), $data);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['phone' => 'This normalized phone number already exists.']);
        }

        return back()->with('status', 'Contact created.');
    }

    public function consent(Request $request, Contact $contact, Foundation $service)
    {
        Gate::authorize('contacts.manage');
        $data = $request->validate(['purpose' => 'required|string|max:255', 'legal_basis' => 'required|string|max:255', 'source' => 'required|string|max:255', 'evidence_ref' => 'required|string|max:255', 'granted_at' => 'required|date|before_or_equal:now']);
        DB::transaction(function () use ($request, $contact, $data, $service) {
            $contact->consents()->create($data);
            $service->audit($request->user(), 'consent.granted', $contact, ['purpose' => $data['purpose']]);
        });

        return back()->with('status', 'Consent evidence recorded.');
    }

    public function revoke(Request $request, ContactConsent $consent, Foundation $service)
    {
        Gate::authorize('contacts.manage');
        DB::transaction(function () use ($request, $consent, $service) {
            app(Engine::class)->mutex();
            $consent = ContactConsent::whereKey($consent->id)->lockForUpdate()->firstOrFail();
            if (! $consent->revoked_at) {
                $consent->update(['revoked_at' => now()]);
                $service->audit($request->user(), 'consent.revoked', $consent);
            }
        });

        return back()->with('status', 'Consent withdrawn.');
    }

    public function suppress(Request $request, Contact $contact, Foundation $service)
    {
        Gate::authorize('contacts.manage');
        $data = $request->validate(['reason' => 'required|string|max:255']);
        abort_unless($contact->phone_e164, 422);
        DB::transaction(function () use ($request, $contact, $data, $service) {
            app(Engine::class)->mutex();
            app(Suppression::class)->add($contact->phone_e164, $data['reason'], $request->user()->id, now());
            $service->audit($request->user(), 'contact.suppressed', $contact);
        });

        return back()->with('status', 'Suppression recorded.');
    }

    public function campaign(Request $request, Foundation $service)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'purpose' => 'required|string|max:255', 'type' => 'required|in:one_way,interactive']);
        $service->createCampaign($request->user(), $data);

        return back()->with('status', 'Draft campaign created.');
    }

    public function updateCampaign(Request $request, Campaign $campaign, Foundation $service)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'purpose' => 'required|string|max:255', 'type' => 'required|in:one_way,interactive']);
        $service->updateCampaign($request->user(), $campaign, $data);

        return back()->with('status', 'Draft updated.');
    }

    public function group(Request $request, Contact $contact, Foundation $service)
    {
        Gate::authorize('contacts.manage');
        $data = $request->validate(['name' => 'required|string|max:255']);
        DB::transaction(function () use ($request, $contact, $data, $service) {
            $group = ContactGroup::firstOrCreate(['owner_id' => $request->user()->id, 'name' => $data['name']]);
            $contact->groups()->syncWithoutDetaching([$group->id]);
            $service->audit($request->user(), 'contact.grouped', $contact, ['group_id' => $group->id]);
        });

        return back()->with('status', 'Contact added to group.');
    }

    public function review(Request $request, Campaign $campaign, Foundation $service)
    {
        $data = $request->validate(['decision' => 'required|in:submit,approve,reject', 'reason' => 'required|string|max:2000']);
        $service->transition($request->user(), $campaign, $data['decision'], $data['reason']);

        return back()->with('status', 'Campaign review recorded.');
    }
}
