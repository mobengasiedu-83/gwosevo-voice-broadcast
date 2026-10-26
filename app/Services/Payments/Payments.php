<?php

namespace App\Services\Payments;

use App\Models\Client;
use App\Models\PaymentEvent;
use App\Models\PaymentIntent;
use App\Models\PaymentReconciliation;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletLedgerEntry;
use App\Services\Billing\Wallet;
use App\Services\Clients\Tenancy;
use App\Services\Foundation;
use App\Services\Notifications\Notifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Owns the online top-up lifecycle: initiation, signed callbacks, idempotent
 * wallet crediting, reconciliation and refunds.
 */
class Payments
{
    public function __construct(
        private PaymentGateway $gateway,
        private Wallet $wallet,
        private Foundation $foundation,
        private Tenancy $tenancy,
    ) {}

    /** @return array{min_minor:int,max_minor:int,ttl_minutes:int} */
    public function limits(): array
    {
        $min = max(1, (int) config('payments.min_amount_minor', 100));
        $max = max($min, (int) config('payments.max_amount_minor', 1000000));

        return [
            'min_minor' => $min,
            'max_minor' => $max,
            'ttl_minutes' => max(5, min(1440, (int) config('payments.pending_ttl_minutes', 30))),
        ];
    }

    public function initiate(User $actor, Client $client, string $amount, string $requestKey): PaymentIntent
    {
        Gate::forUser($actor)->authorize('dashboard.view');
        if ($this->tenancy->clientId($actor) !== (int) $client->id) {
            throw new AuthorizationException('You can only top up your own calling wallet.');
        }
        if ($client->status !== 'active') {
            throw ValidationException::withMessages(['amount' => 'Only active client accounts can top up calling credit.']);
        }
        $data = Validator::make(
            ['amount' => $amount, 'request_key' => $requestKey],
            [
                'amount' => ['required', 'string', 'max:32', 'regex:/\A\d{1,9}(\.\d{1,6})?\z/'],
                'request_key' => ['required', 'uuid'],
            ],
            ['amount.regex' => 'Enter a positive amount such as 50 or 50.00.']
        )->validate();

        $wallet = WalletAccount::where('client_id', $client->id)->first();
        if (! $wallet) {
            throw ValidationException::withMessages(['amount' => 'No calling wallet exists for this account yet.']);
        }
        if (! $this->gateway->enabled()) {
            throw ValidationException::withMessages(['amount' => 'Online top-ups are not available yet. Please contact support.']);
        }

        $limits = $this->limits();
        $minor = $this->wallet->parse($data['amount'], $wallet->minor_unit_scale);
        if ($minor < $limits['min_minor'] || $minor > $limits['max_minor']) {
            throw ValidationException::withMessages(['amount' => sprintf(
                'Online top-ups must be between %s and %s.',
                $this->money($limits['min_minor'], $wallet->currency, $wallet->minor_unit_scale),
                $this->money($limits['max_minor'], $wallet->currency, $wallet->minor_unit_scale),
            )]);
        }

        $requestKey = 'payment:'.$client->id.':'.$data['request_key'];
        $existing = PaymentIntent::where('request_key', $requestKey)->first();
        if ($existing) {
            return $existing;
        }

        try {
            $intent = PaymentIntent::create([
                'public_id' => (string) Str::uuid(),
                'request_key' => $requestKey,
                'client_id' => $client->id,
                'wallet_id' => $wallet->id,
                'initiated_by' => $actor->id,
                'provider' => $this->gateway->key(),
                'status' => 'pending',
                'amount_minor' => $minor,
                'currency' => $wallet->currency,
                'minor_unit_scale' => $wallet->minor_unit_scale,
                'metadata' => [],
                'expires_at' => now()->addMinutes($limits['ttl_minutes']),
            ]);
        } catch (QueryException $e) {
            $existing = PaymentIntent::where('request_key', $requestKey)->first();
            if ($existing) {
                return $existing;
            }
            throw $e;
        }

        try {
            $provider = $this->gateway->requireEnabled();
            $initiation = $provider->initiate(
                $intent,
                route('payments.webhook', ['provider' => $provider->key()]),
                route('wallet.payments.show', $intent->public_id),
            );
        } catch (Throwable $e) {
            $intent->forceFill([
                'status' => 'failed',
                'failed_at' => now(),
                'failure_code' => 'provider_error',
                'failure_message' => 'The payment provider could not be reached. No money was taken.',
            ])->save();
            $this->foundation->audit($actor, 'billing.payment_initiation_failed', $intent, [
                'provider' => $intent->provider, 'reason' => $e instanceof PaymentProviderException ? $e->reason : 'unavailable',
            ]);
            $intent = $intent->fresh();
            app(Notifier::class)->paymentStatus($intent);

            return $intent;
        }

        $intent->forceFill([
            'provider_reference' => $initiation->providerReference,
            'checkout_url' => $initiation->checkoutUrl,
            'metadata' => ['provider' => $initiation->metadata],
        ])->save();

        $this->foundation->audit($actor, 'billing.payment_initiated', $intent, [
            'provider' => $intent->provider, 'amount_minor' => $intent->amount_minor, 'currency' => $intent->currency,
        ]);

        return $intent;
    }

    /**
     * Verify and idempotently apply a provider callback. The event insert is
     * unique per provider event id, and each intent credit is unique per intent.
     */
    public function handleCallback(string $providerKey, Request $request): PaymentEvent
    {
        $notification = $this->gateway->verify($request, $providerKey);

        try {
            return DB::transaction(function () use ($providerKey, $notification) {
                $duplicate = PaymentEvent::where('provider', $providerKey)
                    ->where('provider_event_id', $notification->eventId)
                    ->first();
                if ($duplicate) {
                    return $duplicate;
                }

                $intent = PaymentIntent::where('public_id', $notification->reference)
                    ->where('provider', $providerKey)
                    ->lockForUpdate()
                    ->first();
                if (! $intent && $notification->providerReference) {
                    $intent = PaymentIntent::where('provider', $providerKey)
                        ->where('provider_reference', $notification->providerReference)
                        ->lockForUpdate()
                        ->first();
                }
                if (! $intent) {
                    return $this->recordEvent($providerKey, $notification, null, 'unknown_intent');
                }
                if ($intent->amount_minor !== $notification->amountMinor) {
                    return $this->recordEvent($providerKey, $notification, $intent, 'amount_mismatch');
                }
                if ($intent->currency !== $notification->currency || $intent->minor_unit_scale !== $notification->minorUnitScale) {
                    return $this->recordEvent($providerKey, $notification, $intent, 'currency_mismatch');
                }
                if ($intent->provider_reference !== null && $notification->providerReference !== null
                    && ! hash_equals((string) $intent->provider_reference, $notification->providerReference)) {
                    return $this->recordEvent($providerKey, $notification, $intent, 'provider_reference_mismatch');
                }

                $status = $this->normalizeStatus($notification->status);
                if (! $status || ! str_starts_with(strtolower($notification->eventType), 'payment')) {
                    return $this->recordEvent($providerKey, $notification, $intent, 'unsupported_status');
                }

                $updates = ['verified_at' => $intent->verified_at ?? now()];
                $result = 'processed';

                if ($status === 'succeeded') {
                    if (in_array($intent->status, ['succeeded', 'refunded'], true)) {
                        return $this->recordEvent($providerKey, $notification, $intent, $intent->status === 'refunded' ? 'ignored_final' : 'duplicate');
                    }

                    if ($intent->wallet_ledger_entry_id !== null) {
                        // A previous callback committed the credit but did not
                        // finish the intent update. Never credit twice.
                        $updates += ['status' => 'succeeded', 'credited_at' => $intent->credited_at ?? now()];
                    } else {
                        try {
                            $entry = $this->wallet->creditPayment($intent, $notification->eventId);
                        } catch (ValidationException|\RuntimeException $e) {
                            return $this->recordEvent($providerKey, $notification, $intent, 'credit_failed');
                        }
                        $updates += [
                            'status' => 'succeeded',
                            'wallet_ledger_entry_id' => $entry->id,
                            'credited_at' => now(),
                            'provider_reference' => $intent->provider_reference ?? $notification->providerReference,
                            'failure_code' => null,
                            'failure_message' => null,
                        ];
                    }
                } elseif ($status === 'failed') {
                    if (in_array($intent->status, ['succeeded', 'refunded'], true)) {
                        return $this->recordEvent($providerKey, $notification, $intent, 'ignored_final');
                    }
                    if ($intent->status === 'failed') {
                        return $this->recordEvent($providerKey, $notification, $intent, 'duplicate');
                    }
                    $updates += [
                        'status' => 'failed',
                        'failed_at' => now(),
                        'failure_code' => 'provider_declined',
                        'failure_message' => $this->safeMessage($notification->message) ?? 'The payment was declined by the provider.',
                    ];
                } elseif ($status === 'cancelled') {
                    if (in_array($intent->status, ['succeeded', 'refunded'], true)) {
                        return $this->recordEvent($providerKey, $notification, $intent, 'ignored_final');
                    }
                    if ($intent->status === 'cancelled') {
                        return $this->recordEvent($providerKey, $notification, $intent, 'duplicate');
                    }
                    $updates += ['status' => 'cancelled', 'cancelled_at' => now()];
                } else {
                    if ($intent->status !== 'pending') {
                        return $this->recordEvent($providerKey, $notification, $intent, 'ignored_final');
                    }
                    $result = 'pending';
                }

                $intent->forceFill($updates)->save();
                if ($result === 'processed') {
                    app(Notifier::class)->paymentStatus($intent);
                }

                return $this->recordEvent($providerKey, $notification, $intent, $result);
            });
        } catch (QueryException $e) {
            $event = PaymentEvent::where('provider', $providerKey)
                ->where('provider_event_id', $notification->eventId)
                ->first();
            if ($event) {
                return $event;
            }
            throw $e;
        }
    }

    private function normalizeStatus(string $status): ?string
    {
        return match (strtolower(trim($status))) {
            'succeeded', 'success', 'successful', 'paid', 'completed', 'approved' => 'succeeded',
            'failed', 'failure', 'declined', 'rejected' => 'failed',
            'cancelled', 'canceled', 'expired' => 'cancelled',
            'pending', 'processing', 'initiated', 'authorized' => 'pending',
            default => null,
        };
    }

    private function recordEvent(string $provider, PaymentNotification $notification, ?PaymentIntent $intent, string $result): PaymentEvent
    {
        return PaymentEvent::create([
            'provider' => $provider,
            'provider_event_id' => $notification->eventId,
            'event_type' => $notification->eventType,
            'payment_intent_id' => $intent?->id,
            'client_id' => $intent?->client_id,
            'status' => $notification->status,
            'reference' => $notification->reference,
            'provider_reference' => $notification->providerReference,
            'result' => $result,
            'amount_minor' => $notification->amountMinor,
            'currency' => $notification->currency,
            'minor_unit_scale' => $notification->minorUnitScale,
            'message' => $this->safeMessage($notification->message),
            'payload' => $notification->payload,
            'payload_hash' => $notification->payloadHash,
            'processed_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function safeMessage(?string $message): ?string
    {
        return $message === null || trim($message) === '' ? null : mb_substr(trim($message), 0, 255);
    }

    /**
     * Record a verified external refund and reverse the matching wallet credit.
     * Only full refunds of successfully credited top-ups can be recorded here.
     */
    public function refund(User $actor, PaymentIntent $intent, array $input): PaymentIntent
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $data = Validator::make($input, [
            'refund_reference' => ['required', 'string', 'min:3', 'max:64'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
            'approval' => ['required', 'string', 'min:3', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($actor, $intent, $data) {
            $intent = PaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();
            if ($intent->status !== 'succeeded' || ! $intent->credited_at || ! $intent->wallet_ledger_entry_id) {
                throw ValidationException::withMessages(['refund_reference' => 'Only a successfully credited top-up can be refunded.']);
            }
            $wallet = WalletAccount::findOrFail($intent->wallet_id);
            $entry = WalletLedgerEntry::findOrFail($intent->wallet_ledger_entry_id);
            $reversal = $this->wallet->reverse(
                $actor, $wallet, $entry,
                'Refund '.$data['refund_reference'].': '.$data['reason'],
                $data['approval'],
            );
            $intent->forceFill([
                'status' => 'refunded',
                'refunded_at' => now(),
                'refund_reference' => $data['refund_reference'],
                'metadata' => array_merge($intent->metadata ?? [], [
                    'refund_approval' => $data['approval'],
                    'reversal_ledger_entry_id' => $reversal->id,
                ]),
            ])->save();
            $this->foundation->audit($actor, 'billing.payment_refunded', $intent, [
                'amount_minor' => $intent->amount_minor, 'currency' => $intent->currency,
                'refund_reference' => $data['refund_reference'], 'reversal_ledger_entry_id' => $reversal->id,
            ]);

            return $intent->fresh();
        });
    }

    /** Read-only integrity check for one payment. */
    public function inspect(PaymentIntent $intent): array
    {
        $intent->loadMissing(['wallet', 'ledgerEntry', 'creditReversal']);
        $issues = [];
        $financial = in_array($intent->status, ['succeeded', 'refunded'], true);
        if ($financial && $intent->credited_at === null) {
            $issues[] = 'The payment is final but has no credit timestamp.';
        }
        if ($financial && $intent->provider_reference === null) {
            $issues[] = 'The payment is final but has no provider reference.';
        }
        if ($financial && ! $intent->ledgerEntry) {
            $issues[] = 'The payment is final but its wallet credit is missing.';
        }
        $entry = $intent->ledgerEntry;
        if ($entry) {
            if ((int) $entry->wallet_id !== (int) $intent->wallet_id) {
                $issues[] = 'The wallet credit belongs to another wallet.';
            }
            if ($entry->available_delta_minor !== $intent->amount_minor) {
                $issues[] = 'The wallet credit amount differs from the payment amount.';
            }
            if ($entry->currency !== $intent->currency || (int) $entry->minor_unit_scale !== (int) $intent->minor_unit_scale) {
                $issues[] = 'The wallet credit currency differs from the payment.';
            }
            if ($entry->type !== 'funding') {
                $issues[] = 'The wallet credit is not a funding entry.';
            }
        }
        if (! $financial && $intent->wallet_ledger_entry_id !== null) {
            $issues[] = 'An unconfirmed payment is linked to a wallet credit.';
        }
        if ($intent->status === 'succeeded' && $intent->creditReversal) {
            $issues[] = 'The wallet credit was reversed but the payment is still marked paid.';
        }
        if ($intent->status === 'refunded' && ! $intent->creditReversal) {
            $issues[] = 'The payment is refunded but the wallet credit has no reversal.';
        }

        return ['ok' => $issues === [], 'issues' => $issues];
    }

    private function money(int $minor, string $currency, int $scale): string
    {
        $scale = max(0, min(6, $scale));

        return $currency.' '.number_format($minor / (10 ** $scale), $scale, '.', ',');
    }

    /**
     * Compare payment intents, wallet credits and callback outcomes. The run is
     * read-only unless $persist is true, and never mutates money automatically.
     */
    public function reconcile(?User $actor, bool $persist = true): array
    {
        if ($persist && $actor) {
            Gate::forUser($actor)->authorize('billing.manage');
        }
        $issueCount = 0;
        $issues = [];
        $add = function (string $code, ?PaymentIntent $intent, string $detail) use (&$issueCount, &$issues): void {
            $issueCount++;
            if (count($issues) < 50) {
                $issues[] = [
                    'code' => $code,
                    'payment_intent_id' => $intent?->id,
                    'reference' => $intent?->public_id,
                    'detail' => $detail,
                ];
            }
        };
        $final = ['succeeded', 'refunded'];

        PaymentIntent::whereIn('status', $final)
            ->where(function ($query) {
                $query->whereNull('credited_at')->orWhereNull('provider_reference')->orWhereNull('wallet_ledger_entry_id');
            })
            ->chunkById(200, function ($intents) use ($add) {
                foreach ($intents as $intent) {
                    if ($intent->credited_at === null) {
                        $add('final_without_credit', $intent, 'Final payment has no credit timestamp.');
                    }
                    if ($intent->provider_reference === null) {
                        $add('final_without_provider_reference', $intent, 'Final payment has no provider reference.');
                    }
                    if ($intent->wallet_ledger_entry_id === null) {
                        $add('final_without_ledger_entry', $intent, 'Final payment has no wallet ledger entry.');
                    }
                }
            });

        PaymentIntent::whereNotIn('status', $final)->whereNotNull('wallet_ledger_entry_id')
            ->chunkById(200, function ($intents) use ($add) {
                foreach ($intents as $intent) {
                    $add('nonfinal_credit_link', $intent, 'An unconfirmed payment is linked to a wallet credit.');
                }
            });

        PaymentIntent::whereIn('status', $final)->with(['ledgerEntry', 'creditReversal'])
            ->chunkById(200, function ($intents) use ($add) {
                foreach ($intents as $intent) {
                    $entry = $intent->ledgerEntry;
                    if (! $entry || (int) $intent->wallet_ledger_entry_id !== (int) $entry->id) {
                        if ($intent->wallet_ledger_entry_id !== null) {
                            $add('ledger_link_mismatch', $intent, 'The linked wallet ledger entry does not exist.');
                        }

                        continue;
                    }
                    if ((int) $entry->wallet_id !== (int) $intent->wallet_id) {
                        $add('wallet_mismatch', $intent, 'The wallet credit belongs to another wallet.');
                    }
                    if ($entry->available_delta_minor !== $intent->amount_minor) {
                        $add('amount_mismatch', $intent, 'The wallet credit amount differs from the payment amount.');
                    }
                    if ($entry->currency !== $intent->currency || (int) $entry->minor_unit_scale !== $intent->minor_unit_scale) {
                        $add('currency_mismatch', $intent, 'The wallet credit currency differs from the payment.');
                    }
                    if ($intent->status === 'succeeded' && $intent->creditReversal) {
                        $add('reversed_but_paid', $intent, 'The wallet credit was reversed but the payment is still marked paid.');
                    }
                    if ($intent->status === 'refunded' && ! $intent->creditReversal) {
                        $add('refund_without_reversal', $intent, 'The payment is refunded but the wallet credit has no reversal.');
                    }
                }
            });
        $staleMinutes = $this->limits()['ttl_minutes'] * 2;
        $stale = PaymentIntent::where('status', 'pending')->where('created_at', '<', now()->subMinutes($staleMinutes))->count();
        if ($stale > 0) {
            $add('stale_pending', null, $stale.' pending payment(s) are older than '.$staleMinutes.' minutes.');
        }

        WalletLedgerEntry::where('idempotency_key', 'like', 'payment:%')
            ->select(['id', 'wallet_id', 'idempotency_key', 'available_delta_minor'])
            ->chunkById(500, function ($entries) use ($add) {
                $prefix = 'payment:';
                $ids = $entries->map(fn ($entry) => substr((string) $entry->idempotency_key, strlen($prefix)))->all();
                $intents = PaymentIntent::whereIn('public_id', $ids)->get()->keyBy('public_id');
                foreach ($entries as $entry) {
                    $publicId = substr((string) $entry->idempotency_key, strlen($prefix));
                    $intent = $intents->get($publicId);
                    if (! $intent) {
                        $add('orphan_wallet_credit', null, 'Wallet ledger entry '.$entry->id.' has no payment intent.');
                    } elseif ((int) $intent->wallet_ledger_entry_id !== (int) $entry->id) {
                        $add('ledger_link_mismatch', $intent, 'Ledger entry '.$entry->id.' is not the entry linked from the payment.');
                    }
                }
            });

        $eventExceptions = PaymentEvent::whereNotIn('result', ['processed', 'pending', 'duplicate', 'ignored_final'])->count();
        if ($eventExceptions > 0) {
            $add('callback_exceptions', null, $eventExceptions.' callback(s) were rejected or need review.');
        }

        $summary = [
            'status' => $issueCount === 0 ? 'ok' : 'attention',
            'intents_checked' => PaymentIntent::count(),
            'events_checked' => PaymentEvent::count(),
            'event_exceptions' => $eventExceptions,
            'stale_pending' => $stale,
            'issues_found' => $issueCount,
            'issues' => $issues,
            'truncated' => $issueCount > count($issues),
        ];

        if ($persist) {
            $byStatus = PaymentIntent::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
            $reconciliation = PaymentReconciliation::create([
                'provider' => $this->gateway->enabled() ? $this->gateway->key() : null,
                'actor_id' => $actor?->id,
                'status' => $summary['status'],
                'intents_checked' => $summary['intents_checked'],
                'credited_count' => (int) ($byStatus['succeeded'] ?? 0),
                'pending_count' => (int) ($byStatus['pending'] ?? 0),
                'failed_count' => (int) ($byStatus['failed'] ?? 0),
                'refunded_count' => (int) ($byStatus['refunded'] ?? 0),
                'issue_count' => $issueCount,
                'details' => $summary,
                'created_at' => now(),
            ]);
            if ($actor) {
                $this->foundation->audit($actor, 'billing.payments_reconciled', $reconciliation, [
                    'status' => $summary['status'], 'issues_found' => $issueCount,
                ]);
            }
            $summary['reconciliation_id'] = $reconciliation->id;
        }

        return $summary;
    }
}
