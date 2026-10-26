<?php

namespace App\Http\Controllers;

use App\Models\PaymentEvent;
use App\Models\PaymentIntent;
use App\Models\PaymentReconciliation;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\Payments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Finance console for online payments, reconciliation and refunds. */
class PaymentAdminController extends Controller
{
    public function index(Request $request, Payments $payments, PaymentGateway $gateway)
    {
        Gate::authorize('billing.manage');
        $filters = $request->validate([
            'status' => 'nullable|in:'.implode(',', PaymentIntent::STATUSES),
            'provider' => 'nullable|string|max:32',
            'q' => 'nullable|string|max:100',
        ]);
        $query = PaymentIntent::with(['client:id,name', 'wallet:id,currency,minor_unit_scale'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['provider'] ?? null, fn ($q, $provider) => $q->where('provider', $provider))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
                $q->where(fn ($inner) => $inner->where('public_id', 'like', $like)
                    ->orWhere('provider_reference', 'like', $like)
                    ->orWhereHas('client', fn ($client) => $client->where('name', 'like', $like)));
            });
        $intents = $query->orderByDesc('id')
            ->paginate(25, ['id', 'public_id', 'client_id', 'wallet_id', 'provider', 'provider_reference',
                'amount_minor', 'currency', 'minor_unit_scale', 'status', 'created_at', 'credited_at'])
            ->withQueryString();
        $counts = PaymentIntent::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $reconciliations = PaymentReconciliation::with('actor:id,name')->orderByDesc('id')->limit(5)->get();

        return response()->view('billing.payments', [
            'intents' => $intents, 'counts' => $counts, 'filters' => $filters,
            'reconciliations' => $reconciliations, 'providerEnabled' => $gateway->enabled(),
            'limits' => $payments->limits(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Payments $payments, string $payment)
    {
        Gate::authorize('billing.manage');
        $intent = PaymentIntent::with(['client', 'wallet', 'initiator:id,name', 'ledgerEntry', 'creditReversal'])
            ->where('public_id', $payment)->firstOrFail();
        $events = PaymentEvent::where('payment_intent_id', $intent->id)
            ->orderByDesc('id')
            ->paginate(20, ['id', 'provider_event_id', 'event_type', 'status', 'result', 'amount_minor',
                'minor_unit_scale', 'payload_hash', 'processed_at', 'created_at'], 'events_page')
            ->withQueryString();

        return response()->view('billing.payment', [
            'intent' => $intent, 'events' => $events, 'inspection' => $payments->inspect($intent),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function reconcile(Request $request, Payments $payments)
    {
        Gate::authorize('billing.manage');
        $result = $payments->reconcile($request->user(), true);

        return redirect()->route('billing.payments')
            ->with('status', $result['status'] === 'ok'
                ? 'Reconciliation complete: '.$result['intents_checked'].' payment(s) and '.$result['events_checked'].' callback(s) agree with the wallet ledger.'
                : 'Reconciliation complete with '.$result['issues_found'].' issue(s). No balances were changed automatically.');
    }

    public function refund(Request $request, Payments $payments, string $payment)
    {
        Gate::authorize('billing.manage');
        $intent = PaymentIntent::where('public_id', $payment)->firstOrFail();
        $data = $request->validate([
            'refund_reference' => ['required', 'string', 'min:3', 'max:64'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'approval' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $payments->refund($request->user(), $intent, $data);

        return redirect()->route('billing.payments.show', $intent->public_id)
            ->with('status', 'Refund recorded and the wallet credit reversed. The original ledger entries were not edited.');
    }
}
