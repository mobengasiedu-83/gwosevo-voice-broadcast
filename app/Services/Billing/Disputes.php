<?php

namespace App\Services\Billing;

use App\Models\BillingDispute;
use App\Models\CallAttempt;
use App\Models\Client;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletLedgerEntry;
use App\Services\Clients\Tenancy;
use App\Services\Foundation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Opening and resolving billing disputes with append-only audit and credit links. */
class Disputes
{
    public function open(User $actor, Client $client, array $input, string $source = 'admin'): BillingDispute
    {
        if ($source === 'client') {
            Gate::forUser($actor)->authorize('dashboard.view');
            abort_unless(app(Tenancy::class)->clientId($actor) === $client->id, 404);
        } else {
            Gate::forUser($actor)->authorize('billing.manage');
        }
        $data = Validator::make($input, [
            'category' => 'required|in:'.implode(',', BillingDispute::CATEGORIES),
            'subject' => 'required|string|min:5|max:160',
            'description' => 'required|string|min:10|max:4000',
            'payment_reference' => 'nullable|uuid',
            'attempt_reference' => 'nullable|uuid',
            'ledger_entry_id' => 'nullable|integer|min:1',
            'amount' => 'nullable|string|max:32',
        ])->validate();

        return DB::transaction(function () use ($actor, $client, $data, $source) {
            $wallet = WalletAccount::where('client_id', $client->id)->first();
            $payment = null;
            if (! empty($data['payment_reference'])) {
                $payment = PaymentIntent::where('client_id', $client->id)
                    ->where('public_id', $data['payment_reference'])->first();
                abort_unless($payment, 404);
            }
            $attempt = null;
            if (! empty($data['attempt_reference'])) {
                $attempt = CallAttempt::withoutGlobalScopes()->where('uuid', $data['attempt_reference'])->first();
                abort_unless($attempt && app(Tenancy::class)->ownerOf($attempt) === $client->id, 404);
            }
            $entry = null;
            if (! empty($data['ledger_entry_id'])) {
                $entry = WalletLedgerEntry::where('client_id', $client->id)->find($data['ledger_entry_id']);
                abort_unless($entry, 404);
            }

            $currency = $wallet?->currency ?? $payment?->currency ?? $entry?->currency;
            $scale = $wallet?->minor_unit_scale ?? $payment?->minor_unit_scale ?? $entry?->minor_unit_scale;
            $amount = null;
            if (! empty($data['amount'])) {
                if ($scale === null) {
                    throw ValidationException::withMessages(['amount' => 'Open a wallet or reference a payment before recording a disputed amount.']);
                }
                $amount = app(Wallet::class)->parse($data['amount'], (int) $scale);
                if ($amount < 1) {
                    throw ValidationException::withMessages(['amount' => 'The disputed amount must be positive.']);
                }
            } elseif ($payment) {
                $amount = $payment->amount_minor;
            }

            $dispute = BillingDispute::create([
                'client_id' => $client->id,
                'wallet_id' => $wallet?->id ?? $payment?->wallet_id,
                'payment_intent_id' => $payment?->id,
                'call_attempt_id' => $attempt?->id,
                'wallet_ledger_entry_id' => $entry?->id,
                'opened_by' => $actor->id,
                'source' => $source,
                'category' => $data['category'],
                'status' => 'open',
                'subject' => $data['subject'],
                'description' => $data['description'],
                'amount_minor' => $amount,
                'currency' => $currency,
                'minor_unit_scale' => $scale,
                'credit_amount_minor' => 0,
            ]);
            app(Foundation::class)->audit($actor, 'billing.dispute_opened', $dispute, [
                'source' => $source, 'category' => $dispute->category, 'amount_minor' => $amount,
                'payment_intent_id' => $dispute->payment_intent_id, 'call_attempt_id' => $dispute->call_attempt_id,
            ]);

            return $dispute;
        });
    }

    public function resolve(User $actor, BillingDispute $dispute, array $input): BillingDispute
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $data = Validator::make($input, [
            'status' => 'required|in:resolved,rejected',
            'resolution' => 'required|string|min:3|max:4000',
            'credit_amount' => 'nullable|string|max:32',
            'approval' => 'nullable|string|max:255',
        ])->validate();
        if ($data['status'] === 'rejected' && trim((string) ($data['credit_amount'] ?? '')) !== '') {
            throw ValidationException::withMessages(['credit_amount' => 'A rejected dispute cannot include a credit.']);
        }

        return DB::transaction(function () use ($actor, $dispute, $data) {
            $dispute = BillingDispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();
            if (! $dispute->isOpen()) {
                throw ValidationException::withMessages(['status' => 'This dispute has already been resolved.']);
            }
            $credit = 0;
            $entry = null;
            $amount = trim((string) ($data['credit_amount'] ?? ''));
            if ($amount !== '' && $data['status'] === 'resolved') {
                $wallet = WalletAccount::where('client_id', $dispute->client_id)->lockForUpdate()->first();
                if (! $wallet) {
                    throw ValidationException::withMessages(['credit_amount' => 'This client has no wallet to credit. Open a wallet first.']);
                }
                $credit = app(Wallet::class)->parse($amount, $wallet->minor_unit_scale);
                if ($credit < 0) {
                    throw ValidationException::withMessages(['credit_amount' => 'A dispute credit cannot be negative.']);
                }
                if ($credit > 0) {
                    $approval = trim((string) ($data['approval'] ?? '')) ?: 'DISPUTE-'.$dispute->public_id;
                    $entry = app(Wallet::class)->post(
                        $actor, $wallet, 'adjustment', Wallet::amount($credit, $wallet->minor_unit_scale),
                        'Dispute '.$dispute->public_id.': '.$data['resolution'],
                        $approval, 'billing:dispute:'.$dispute->public_id,
                    );
                }
            }
            $dispute->update([
                'status' => $data['status'],
                'resolution' => $data['resolution'],
                'credit_amount_minor' => $credit,
                'credit_ledger_entry_id' => $entry?->id,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ]);
            app(Foundation::class)->audit($actor, 'billing.dispute_'.$data['status'], $dispute, [
                'credit_minor' => $credit, 'ledger_entry_id' => $entry?->id,
                'approval_reference' => $data['approval'] ?? null,
            ]);

            return $dispute->fresh(['client', 'wallet', 'payment', 'attempt', 'creditEntry', 'opener', 'resolver']);
        });
    }
}
