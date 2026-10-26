<?php

namespace App\Http\Controllers;

use App\Models\BillingDispute;
use App\Models\PaymentIntent;
use App\Services\Billing\Disputes;
use App\Services\Clients\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Client-facing billing dispute history and submission for its own account. */
class ClientDisputeController extends Controller
{
    public function index(Request $request, Tenancy $tenancy)
    {
        Gate::authorize('dashboard.view');
        $membership = $tenancy->membership($request->user());
        abort_unless($membership && $tenancy->usable($request->user()), 403);
        $filters = $request->validate(['status' => 'nullable|in:open,under_review,resolved,rejected,all']);
        $disputes = BillingDispute::with(['payment:id,public_id,amount_minor,currency,minor_unit_scale', 'attempt:id,uuid', 'creditEntry:id,available_delta_minor,currency,minor_unit_scale'])
            ->where('client_id', $membership->client_id)
            ->when(($filters['status'] ?? 'all') !== 'all', fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('id')->paginate(20)->withQueryString();
        $payments = PaymentIntent::where('client_id', $membership->client_id)
            ->orderByDesc('id')->limit(20)->get(['public_id', 'amount_minor', 'currency', 'minor_unit_scale', 'created_at', 'status']);

        return response()->view('client-dashboard.disputes', [
            'membership' => $membership, 'client' => $membership->client, 'wallet' => $membership->client->wallet,
            'disputes' => $disputes, 'payments' => $payments, 'filters' => $filters,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Tenancy $tenancy, Disputes $disputes)
    {
        Gate::authorize('dashboard.view');
        $membership = $tenancy->membership($request->user());
        abort_unless($membership && $tenancy->usable($request->user()), 403);
        $data = $request->validate([
            'category' => 'required|in:'.implode(',', BillingDispute::CATEGORIES),
            'subject' => 'required|string|min:5|max:160',
            'description' => 'required|string|min:10|max:4000',
            'payment_reference' => 'nullable|uuid',
            'attempt_reference' => 'nullable|uuid',
            'ledger_entry_id' => 'nullable|integer|min:1',
        ]);
        $dispute = $disputes->open($request->user(), $membership->client, $data, 'client');

        return redirect()->route('wallet.disputes.index')->with('status', 'Dispute '.$dispute->public_id.' submitted. Finance will record a decision here.');
    }
}
