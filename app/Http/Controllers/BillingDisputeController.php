<?php

namespace App\Http\Controllers;

use App\Models\BillingDispute;
use App\Models\Client;
use App\Services\Billing\Disputes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Module 15 administrator dispute queue and resolution credits. */
class BillingDisputeController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('billing.manage');
        $filters = $request->validate([
            'status' => 'nullable|in:'.implode(',', BillingDispute::STATUSES).',all',
            'q' => 'nullable|string|max:100',
        ]);
        $disputes = BillingDispute::with(['client:id,name', 'payment:id,public_id,amount_minor,currency,minor_unit_scale',
            'attempt:id,uuid', 'ledgerEntry:id,type,reason', 'creditEntry:id,type,available_delta_minor,currency,minor_unit_scale',
            'opener:id,name', 'resolver:id,name'])
            ->when(($filters['status'] ?? 'all') !== 'all', fn ($q, $status) => $q->where('status', $filters['status']))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn ($inner) => $inner->where('public_id', 'like', $like)
                    ->orWhere('subject', 'like', $like)
                    ->orWhereHas('client', fn ($client) => $client->where('name', 'like', $like)));
            })
            ->orderByRaw("CASE WHEN status IN ('open','under_review') THEN 0 ELSE 1 END")
            ->orderByDesc('id')->paginate(25)->withQueryString();
        $counts = BillingDispute::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $clients = Client::withDisplayEmail()->orderBy('name')->get(['id', 'name', 'status']);

        return response()->view('billing.disputes', compact('disputes', 'counts', 'clients', 'filters'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Disputes $disputes)
    {
        Gate::authorize('billing.manage');
        $data = $request->validate([
            'client_id' => 'required|integer|exists:clients,id',
            'category' => 'required|in:'.implode(',', BillingDispute::CATEGORIES),
            'subject' => 'required|string|min:5|max:160',
            'description' => 'required|string|min:10|max:4000',
            'payment_reference' => 'nullable|uuid',
            'attempt_reference' => 'nullable|uuid',
            'ledger_entry_id' => 'nullable|integer|min:1',
            'amount' => 'nullable|string|max:32',
        ]);
        $client = Client::findOrFail($data['client_id']);
        $dispute = $disputes->open($request->user(), $client, $data, 'admin');

        return redirect()->route('billing.disputes')->with('status', 'Dispute '.$dispute->public_id.' opened. Resolution credits post to the ledger as adjustments.');
    }

    public function resolve(Request $request, BillingDispute $dispute, Disputes $disputes)
    {
        Gate::authorize('billing.manage');
        $data = $request->validate([
            'status' => 'required|in:resolved,rejected',
            'resolution' => 'required|string|min:3|max:4000',
            'credit_amount' => 'nullable|string|max:32',
            'approval' => 'nullable|string|max:255',
        ]);
        $disputes->resolve($request->user(), $dispute, $data);

        return redirect()->route('billing.disputes')->with('status', 'Dispute '.$dispute->public_id.' recorded. Any credit was posted as a new ledger adjustment.');
    }
}
