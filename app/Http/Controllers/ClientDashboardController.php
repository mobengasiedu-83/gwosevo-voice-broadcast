<?php

namespace App\Http\Controllers;

use App\Models\AudioFile;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\ContactImport;
use App\Services\Clients\Tenancy;
use App\Services\Dashboard\Metrics;
use App\Services\Dashboard\Scope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientDashboardController extends Controller
{
    public function __invoke(Request $request, Tenancy $tenancy, Scope $scope, Metrics $metrics)
    {
        Gate::authorize('dashboard.view');
        $membership = $tenancy->membership($request->user());
        abort_unless($membership && $tenancy->usable($request->user()), 403);
        $filters = $request->validate(['date' => 'nullable|date_format:Y-m-d', 'timezone' => 'nullable|timezone', 'campaign_id' => 'nullable|integer|min:1']);
        $filters['timezone'] ??= 'Africa/Accra';
        $filters['date'] ??= now($filters['timezone'])->toDateString();
        $campaigns = $scope->campaigns($request->user());
        if (! empty($filters['campaign_id'])) {
            abort_unless((clone $campaigns)->whereKey($filters['campaign_id'])->exists(), 403);
        }
        $clientId = $membership->client_id;
        $data = $metrics->overview($request->user(), $filters);
        $data += [
            'client' => $membership->client,
            'membership' => $membership,
            'filters' => $filters,
            'totals' => [
                'campaigns' => (clone $campaigns)->count(),
                'contacts' => Contact::where('client_id', $clientId)->count(),
                'groups' => ContactGroup::where('client_id', $clientId)->count(),
                'recordings' => AudioFile::where('client_id', $clientId)->count(),
            ],
            'campaign_options' => (clone $campaigns)->orderBy('name')->limit(500)->get(['id', 'name']),
            'recent_campaigns' => (clone $campaigns)->latest('id')->limit(8)->get(),
            'campaign_statuses' => (clone $campaigns)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'recording_statuses' => AudioFile::where('client_id', $clientId)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status'),
            'recent_imports' => ContactImport::where('client_id', $clientId)->latest('id')->limit(5)->get(),
            // Display event names only, never internal audit details or another member's contact.
            'activity' => AuditLog::where('client_id', $clientId)->where('actor_id', $request->user()->id)->whereIn('action', ['identity.signed_in', 'identity.contact_verified', 'client.registered', 'campaign.created', 'contact.created'])->latest('id')->limit(8)->get(['action', 'created_at']),
            'generated_at' => now()->toIso8601String(),
        ];
        if ($request->expectsJson()) {
            return response()->json(['html' => view('client-dashboard.panel', $data)->render(), 'generated_at' => $data['generated_at']])->header('Cache-Control', 'private, no-store');
        }

        return response()->view('client-dashboard.index', $data)->header('Cache-Control', 'private, no-store');
    }
}
