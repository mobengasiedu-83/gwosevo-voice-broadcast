<?php

namespace App\Http\Controllers;

use App\Models\PaymentIntent;
use App\Services\Clients\Tenancy;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\Payments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Client-facing online top-up initiation, status and receipts. */
class PaymentController extends Controller
{
    public function index(Request $request, Tenancy $tenancy, Payments $payments, PaymentGateway $gateway)
    {
        Gate::authorize('dashboard.view');
        $membership = $tenancy->membership($request->user());
        abort_unless($membership && $tenancy->usable($request->user()), 403);
        $filters = $request->validate([
            'status' => 'nullable|in:'.implode(',', PaymentIntent::STATUSES),
            'page' => 'nullable|integer|min:1|max:100000',
        ]);
        $history = PaymentIntent::where('client_id', $membership->client_id)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20, ['public_id', 'status', 'amount_minor', 'currency', 'minor_unit_scale', 'provider',
                'created_at', 'credited_at', 'refunded_at', 'failure_message'])
            ->withQueryString();

        return response()->view('client-dashboard.payments', [
            'client' => $membership->client, 'membership' => $membership,
            'payments' => $history, 'filters' => $filters,
            'wallet' => $membership->client->wallet,
            'limits' => $payments->limits(),
            'onlineEnabled' => $gateway->enabled(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Tenancy $tenancy, Payments $payments)
    {
        Gate::authorize('dashboard.view');
        $membership = $tenancy->membership($request->user());
        abort_unless($membership && $tenancy->usable($request->user()), 403);
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:32'],
            'request_key' => ['nullable', 'uuid'],
        ]);
        $intent = $payments->initiate(
            $request->user(),
            $membership->client,
            $data['amount'],
            $data['request_key'] ?? (string) Str::uuid(),
        );

        if ($intent->isOpen() && $this->safeRedirect($intent->checkout_url)) {
            return redirect()->away($intent->checkout_url);
        }
        if (! $intent->isOpen() && $intent->status !== 'succeeded') {
            return redirect()->route('wallet.payments.show', $intent->public_id)
                ->withErrors(['amount' => $intent->failure_message ?? $intent->clientMessage()]);
        }

        return redirect()->route('wallet.payments.show', $intent->public_id)
            ->with('status', $intent->status === 'succeeded' ? $intent->clientMessage() : 'Payment started. Complete it with the provider, then return here for the confirmed status.');
    }

    public function show(Request $request, Tenancy $tenancy, string $payment)
    {
        $intent = $this->owned($request, $tenancy, $payment);

        return response()->view('client-dashboard.payment', [
            'client' => $intent->client, 'intent' => $intent,
            'checkoutUrl' => $intent->isOpen() && $this->safeRedirect($intent->checkout_url) ? $intent->checkout_url : null,
            'events' => $intent->events()->orderByDesc('id')->limit(20)->get(['id', 'event_type', 'status', 'result', 'created_at']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function receipt(Request $request, Tenancy $tenancy, string $payment)
    {
        $intent = $this->owned($request, $tenancy, $payment);
        abort_unless($intent->isPaid(), 404);

        return response()->view('client-dashboard.payment-receipt', [
            'client' => $intent->client, 'intent' => $intent,
        ])->header('Cache-Control', 'private, no-store');
    }

    private function owned(Request $request, Tenancy $tenancy, string $publicId): PaymentIntent
    {
        Gate::authorize('dashboard.view');
        $membership = $tenancy->membership($request->user());
        abort_unless($membership && $tenancy->usable($request->user()), 403);
        $intent = PaymentIntent::with(['client', 'wallet', 'events'])
            ->where('client_id', $membership->client_id)
            ->where('public_id', $publicId)
            ->firstOrFail();
        abort_unless($intent->wallet && (int) $intent->wallet->client_id === (int) $membership->client_id, 403);

        return $intent;
    }

    private function safeRedirect(?string $url): bool
    {
        if (! $url) {
            return false;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return false;
        }

        return in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']);
    }
}
