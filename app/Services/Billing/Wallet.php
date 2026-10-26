<?php

namespace App\Services\Billing;

use App\Models\AuditLog;
use App\Models\CallAttempt;
use App\Models\CallRating;
use App\Models\Client;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletLedgerEntry;
use App\Models\WalletReservation;
use App\Services\Clients\Tenancy;
use App\Services\Foundation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;

/**
 * Prepaid wallet: admission checks, atomic per-attempt reservations, append-only
 * ledger entries and idempotent settlement.
 *
 * Ledger rules
 * - available_minor/reserved_minor are integer minor units; no floats.
 * - Every balance movement appends exactly one immutable ledger entry carrying
 *   the idempotency key and the resulting balances.
 * - Reservations are unique per call attempt and settle at most once.
 * - Corrections are reversing entries with a reason, actor, timestamp and
 *   approval reference; historical entries are never rewritten.
 *
 * Enforcement applies to accounts that have a wallet. Accounts without a wallet
 * keep the pre-wallet manual/unpriced behaviour until Finance opens one.
 */
class Wallet
{
    /** Reasons an admission check can refuse to hold credit. */
    public const REASONS = ['wallet_missing', 'tariff_missing', 'tariff_not_priced', 'currency_mismatch', 'insufficient_credit'];

    public const TYPES = ['funding', 'adjustment', 'reversal', 'reservation', 'release', 'settlement'];

    public static function amount(int $minor, ?int $scale): string
    {
        if ($scale === null) {
            return '0';
        }
        $scale = max(0, min(6, (int) $scale));
        $factor = 10 ** $scale;
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return $sign.intdiv($abs, $factor).($scale ? '.'.str_pad((string) ($abs % $factor), $scale, '0', STR_PAD_LEFT) : '');
    }

    /** Human-readable admission refusal used by the campaign UI. */
    public static function message(string $reason): string
    {
        return match ($reason) {
            'tariff_missing' => 'Assign an approved call tariff before launching a wallet-backed campaign.',
            'tariff_not_priced' => 'The assigned call tariff cannot be priced. Assign a reviewed tariff version.',
            'currency_mismatch' => 'The wallet currency and minor-unit scale must match the assigned tariff.',
            'insufficient_credit' => 'Insufficient calling credit for one reserved call. Ask Finance to top up the wallet before launching.',
            default => 'A calling wallet is not configured for this account.',
        };
    }

    /** Reserved minutes for one attempt: the configured maximum, rounded up. */
    public function exposureMinutes(): int
    {
        $seconds = (int) config('billing.max_call_seconds');
        if ($seconds < 60 || $seconds > 86400) {
            throw new LogicException('BILLING_MAX_CALL_SECONDS must be between 60 and 86400.');
        }

        return max(1, intdiv($seconds + 59, 60));
    }

    /** Maximum charge exposure for a frozen tariff snapshot, in minor units. */
    public function exposureMinor(?array $tariff): ?int
    {
        $rate = filter_var($tariff['rate_minor_per_minute'] ?? null, FILTER_VALIDATE_INT);
        if ($rate === false || $rate < 0) {
            return null;
        }

        return $this->exposureMinutes() * $rate;
    }

    /**
     * Evaluate whether a client campaign may admit another attempt.
     *
     * Accounts without a wallet are "not applicable": they are not charged by the
     * wallet and keep the existing manual billing process. A wallet-backed account
     * must have a priceable, matching tariff and enough available credit.
     */
    public function admission(?int $clientId, ?array $tariff): array
    {
        $base = ['applicable' => false, 'allowed' => true, 'reason' => null, 'exposure_minor' => 0, 'available_minor' => 0, 'wallet' => null];
        if ($clientId === null) {
            return array_merge($base, ['reason' => 'wallet_missing']);
        }
        $wallet = WalletAccount::where('client_id', $clientId)->first();
        if (! $wallet) {
            return array_merge($base, ['reason' => 'wallet_missing']);
        }
        $base = ['applicable' => true, 'allowed' => false, 'reason' => null, 'exposure_minor' => 0, 'available_minor' => $wallet->available_minor, 'wallet' => $wallet];
        if (! $tariff) {
            return array_merge($base, ['reason' => 'tariff_missing']);
        }
        if (! $this->matches($wallet, $tariff)) {
            return array_merge($base, ['reason' => 'currency_mismatch']);
        }
        $exposure = $this->exposureMinor($tariff);
        if ($exposure === null) {
            return array_merge($base, ['reason' => 'tariff_not_priced']);
        }
        if ($wallet->available_minor < $exposure) {
            return array_merge($base, ['reason' => 'insufficient_credit', 'exposure_minor' => $exposure]);
        }

        return array_merge($base, ['allowed' => true, 'exposure_minor' => $exposure]);
    }

    /** Hold the maximum exposure for one attempt. Null when the account is not wallet-backed. */
    public function reserve(CallAttempt $attempt): ?WalletReservation
    {
        return DB::transaction(function () use ($attempt) {
            $clientId = app(Tenancy::class)->ownerOf($attempt);
            if ($clientId === null) {
                return null;
            }
            $wallet = WalletAccount::where('client_id', $clientId)->lockForUpdate()->first();
            if (! $wallet) {
                return null;
            }
            $existing = WalletReservation::where('call_attempt_id', $attempt->id)->first();
            if ($existing) {
                if ($existing->state === 'held') {
                    return $existing;
                }
                throw new WalletDeclined('reservation_'.$existing->state);
            }
            $tariff = $attempt->tariff_snapshot;
            if (! $tariff) {
                throw new WalletDeclined('tariff_missing');
            }
            if (! $this->matches($wallet, $tariff)) {
                throw new WalletDeclined('currency_mismatch');
            }
            $exposure = $this->exposureMinor($tariff);
            if ($exposure === null) {
                throw new WalletDeclined('tariff_not_priced');
            }
            if ($wallet->available_minor < $exposure) {
                throw new WalletDeclined('insufficient_credit', ['available_minor' => $wallet->available_minor, 'exposure_minor' => $exposure]);
            }
            $wallet->available_minor -= $exposure;
            $wallet->reserved_minor += $exposure;
            $wallet->save();
            $reservation = WalletReservation::create([
                'wallet_id' => $wallet->id, 'client_id' => $wallet->client_id, 'call_attempt_id' => $attempt->id,
                'amount_minor' => $exposure, 'currency' => $wallet->currency, 'minor_unit_scale' => $wallet->minor_unit_scale,
                'state' => 'held', 'reserved_at' => now(),
            ]);
            $this->entry($wallet, 'reservation', -$exposure, $exposure, $reservation, $attempt, null,
                'Reserved maximum exposure for attempt '.$attempt->uuid, null, 'billing:reservation:'.$attempt->id);

            return $reservation;
        });
    }

    /** Release a held reservation after a definitively unanswered call. */
    public function release(CallAttempt $attempt, string $reason): bool
    {
        return DB::transaction(function () use ($attempt, $reason) {
            $reservation = WalletReservation::where('call_attempt_id', $attempt->id)->lockForUpdate()->first();
            if (! $reservation || $reservation->state !== 'held') {
                return false;
            }
            $wallet = WalletAccount::whereKey($reservation->wallet_id)->lockForUpdate()->firstOrFail();
            $amount = $reservation->amount_minor;
            if ($wallet->reserved_minor < $amount) {
                app(Rating::class)->issue($attempt, 'wallet_reserved_underflow', 'Reserved balance is lower than the held reservation; finance review required.');

                return false;
            }
            $wallet->reserved_minor -= $amount;
            $wallet->available_minor += $amount;
            $wallet->save();
            $reservation->update(['state' => 'released', 'released_at' => now(), 'release_reason' => mb_substr($reason, 0, 255)]);
            $this->entry($wallet, 'release', $amount, -$amount, $reservation, $attempt, null,
                'Reservation released: '.$reason, null, 'billing:release:'.$attempt->id);

            return true;
        });
    }

    /**
     * Release holds that never received final rating evidence.
     *
     * Settlement after a release still charges available credit, so expiry only
     * returns held credit to the available balance; it never invents a charge,
     * rewrites a ledger entry or deletes the reservation history.
     */
    public function expire(int $hours = 24, int $limit = 500): int
    {
        $hours = max(1, min(24 * 30, $hours));
        $limit = max(1, min(1000, $limit));
        $attemptIds = WalletReservation::where('state', 'held')
            ->where('reserved_at', '<=', now()->subHours($hours))
            ->orderBy('id')->limit($limit)->pluck('call_attempt_id')->all();
        $released = 0;
        foreach ($attemptIds as $attemptId) {
            $attempt = CallAttempt::withoutGlobalScopes()->find($attemptId);
            // A rated attempt is settled by Rating; only unrated holds expire here.
            if (! $attempt || CallRating::where('call_attempt_id', $attempt->id)->exists()) {
                continue;
            }
            if ($this->release($attempt, 'Hold expired after '.$hours.'h without final rating evidence')) {
                $released++;
            }
        }

        return $released;
    }

    /**
     * Charge a final rating exactly once: release the hold, debit the actual
     * charge and record any uncovered shortfall as a finance exception.
     *
     * Returns not_applicable|no_reservation|duplicate|held|settled|settled_shortfall.
     */
    public function settle(CallRating $rating): string
    {
        return DB::transaction(function () use ($rating) {
            $attempt = CallAttempt::withoutGlobalScopes()->find($rating->call_attempt_id);
            if (! $attempt) {
                return 'not_applicable';
            }
            $clientId = app(Tenancy::class)->ownerOf($attempt);
            if ($clientId === null) {
                return 'not_applicable';
            }
            $wallet = WalletAccount::where('client_id', $clientId)->lockForUpdate()->first();
            if (! $wallet) {
                return 'not_applicable';
            }
            $reservation = WalletReservation::where('call_attempt_id', $attempt->id)->lockForUpdate()->first();
            if (! $reservation) {
                // A rating for a call that predates the wallet must not rewrite history.
                return 'no_reservation';
            }
            if ($reservation->state === 'settled') {
                return 'duplicate';
            }
            $charge = (int) $rating->amount_minor;
            if ($rating->currency !== null && ! $this->matches($wallet, ['currency' => $rating->currency, 'minor_unit_scale' => $rating->minor_unit_scale])) {
                app(Rating::class)->issue($attempt, 'wallet_currency_mismatch', 'Rating currency '.$rating->currency.' does not match wallet '.$wallet->currency.'.');

                return 'held';
            }
            $released = 0;
            if ($reservation->state === 'held') {
                $released = $reservation->amount_minor;
                $wallet->reserved_minor -= $released;
                $wallet->available_minor += $released;
            }
            $payable = min($charge, $wallet->available_minor);
            $shortfall = $charge - $payable;
            $wallet->available_minor -= $payable;
            $wallet->save();
            $reservation->update([
                'state' => 'settled', 'settled_at' => now(), 'settled_minor' => $charge,
                'shortfall_minor' => $shortfall, 'settlement_key' => 'billing:settlement:'.$attempt->id,
            ]);
            $this->entry($wallet, 'settlement', $released - $payable, -$released, $reservation, $attempt, null,
                'Settled rated attempt '.$attempt->uuid.($shortfall ? ' with uncovered shortfall' : ''), null, 'billing:settlement:'.$attempt->id);
            if ($shortfall) {
                app(Rating::class)->issue($attempt, 'wallet_shortfall', 'Rated charge '.self::amount($charge, $wallet->minor_unit_scale).' '.$wallet->currency
                    .' exceeded available credit; uncovered '.self::amount($shortfall, $wallet->minor_unit_scale).' '.$wallet->currency.'.');
                AuditLog::create([
                    'client_id' => $wallet->client_id, 'actor_id' => null, 'action' => 'billing.wallet_shortfall',
                    'entity_type' => $attempt->getMorphClass(), 'entity_id' => $attempt->id,
                    'details' => ['charge_minor' => $charge, 'paid_minor' => $payable, 'shortfall_minor' => $shortfall, 'currency' => $wallet->currency],
                    'created_at' => now(),
                ]);
            }

            return $shortfall ? 'settled_shortfall' : 'settled';
        });
    }

    public function open(User $actor, Client $client, string $currency, int $scale, string $reason): WalletAccount
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $data = Validator::make(['currency' => $currency, 'minor_unit_scale' => $scale, 'reason' => $reason], [
            'currency' => 'required|regex:/\A[A-Z]{3}\z/', 'minor_unit_scale' => 'required|integer|min:0|max:6',
            'reason' => 'required|string|min:3|max:255',
        ])->validate();

        return DB::transaction(function () use ($actor, $client, $data) {
            $locked = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'closed') {
                throw ValidationException::withMessages(['client_id' => 'A closed client account cannot open a wallet.']);
            }
            if (WalletAccount::where('client_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['client_id' => 'This client already has a wallet.']);
            }
            $wallet = WalletAccount::create([
                'client_id' => $locked->id, 'currency' => $data['currency'], 'minor_unit_scale' => (int) $data['minor_unit_scale'],
                'available_minor' => 0, 'reserved_minor' => 0,
            ]);
            app(Foundation::class)->audit($actor, 'billing.wallet_opened', $wallet, [
                'currency' => $wallet->currency, 'minor_unit_scale' => $wallet->minor_unit_scale, 'reason' => $data['reason'],
            ]);

            return $wallet;
        });
    }

    /** Approved funding or adjustment. Positive amounts credit, negative amounts debit. */
    public function post(User $actor, WalletAccount $wallet, string $kind, string $amount, string $reason, string $approval, ?string $key = null): WalletLedgerEntry
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $data = Validator::make(compact('kind', 'amount', 'reason', 'approval'), [
            'kind' => 'required|in:funding,adjustment', 'amount' => 'required|string|max:32',
            'reason' => 'required|string|min:3|max:255', 'approval' => 'required|string|min:3|max:255',
        ])->validate();
        $delta = $this->parse($data['amount'], $wallet->minor_unit_scale, true);
        if ($delta === 0) {
            throw ValidationException::withMessages(['amount' => 'Enter a non-zero amount.']);
        }
        if ($data['kind'] === 'funding' && $delta < 0) {
            throw ValidationException::withMessages(['amount' => 'Funding must be a positive amount. Post a reviewed adjustment to debit a wallet.']);
        }
        $key = $key ?: 'admin:'.Str::uuid();
        Validator::make(['key' => $key], ['key' => 'required|string|max:120'])->validate();

        return DB::transaction(function () use ($actor, $wallet, $data, $delta, $key) {
            $wallet = WalletAccount::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $existing = WalletLedgerEntry::where('idempotency_key', $key)->first();
            if ($existing) {
                if ((int) $existing->wallet_id !== (int) $wallet->id) {
                    throw ValidationException::withMessages(['amount' => 'That idempotency key belongs to another wallet.']);
                }

                return $existing;
            }
            if ($delta < 0 && $wallet->available_minor + $delta < 0) {
                throw ValidationException::withMessages(['amount' => 'The debit exceeds available credit. Record a reviewed receivable instead of forcing a negative balance.']);
            }
            $wallet->available_minor += $delta;
            $wallet->save();
            $entry = $this->entry($wallet, $data['kind'], $delta, 0, null, null, $actor, $data['reason'], $data['approval'], $key);
            app(Foundation::class)->audit($actor, 'billing.wallet_'.$data['kind'], $wallet, [
                'entry_id' => $entry->id, 'amount_minor' => $delta, 'currency' => $wallet->currency, 'approval_reference' => $data['approval'],
            ]);

            return $entry;
        });
    }

    /** Reverse an approved funding/adjustment entry with an opposite ledger entry. */
    public function reverse(User $actor, WalletAccount $wallet, WalletLedgerEntry $entry, string $reason, string $approval): WalletLedgerEntry
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $data = Validator::make(compact('reason', 'approval'), [
            'reason' => 'required|string|min:3|max:255', 'approval' => 'required|string|min:3|max:255',
        ])->validate();

        return DB::transaction(function () use ($actor, $wallet, $entry, $data) {
            $wallet = WalletAccount::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $entry = WalletLedgerEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ((int) $entry->wallet_id !== (int) $wallet->id) {
                throw ValidationException::withMessages(['reason' => 'That ledger entry belongs to another wallet.']);
            }
            if (! in_array($entry->type, ['funding', 'adjustment'], true)) {
                throw ValidationException::withMessages(['reason' => 'Only funding and adjustment entries can be reversed. Correct settlements with a reviewed adjustment referencing the attempt.']);
            }
            if (WalletLedgerEntry::where('reversal_of_id', $entry->id)->exists()) {
                throw ValidationException::withMessages(['reason' => 'This entry has already been reversed.']);
            }
            $delta = -$entry->available_delta_minor;
            if ($delta < 0 && $wallet->available_minor + $delta < 0) {
                throw ValidationException::withMessages(['reason' => 'Reversing this entry would exceed available credit. Record a reviewed receivable instead.']);
            }
            $wallet->available_minor += $delta;
            $wallet->save();
            $reversal = $this->entry($wallet, 'reversal', $delta, 0, null, null, $actor, $data['reason'], $data['approval'],
                'billing:reversal:'.$entry->id, $entry);
            app(Foundation::class)->audit($actor, 'billing.wallet_reversal', $wallet, [
                'entry_id' => $reversal->id, 'reversal_of' => $entry->id, 'amount_minor' => $delta, 'approval_reference' => $data['approval'],
            ]);

            return $reversal;
        });
    }

    /** Reconcile stored balances and holds with the immutable ledger. */
    public function verify(WalletAccount $wallet): array
    {
        $available = (int) WalletLedgerEntry::where('wallet_id', $wallet->id)->sum('available_delta_minor');
        $reserved = (int) WalletLedgerEntry::where('wallet_id', $wallet->id)->sum('reserved_delta_minor');
        $held = (int) WalletReservation::where('wallet_id', $wallet->id)->where('state', 'held')->sum('amount_minor');

        return [
            'available_match' => $available === $wallet->available_minor,
            'reserved_match' => $reserved === $wallet->reserved_minor,
            'held_match' => $held === $wallet->reserved_minor,
            'ledger_available_minor' => $available, 'ledger_reserved_minor' => $reserved, 'held_minor' => $held,
        ];
    }

    /** Parse a decimal major-unit string into integer minor units. */
    public function parse(string $value, int $scale, bool $signed = false): int
    {
        $scale = max(0, min(6, $scale));
        $value = trim($value);
        $pattern = $scale > 0 ? '/\A(-?)(\d{1,16})(?:\.(\d{1,'.$scale.'}))?\z/' : '/\A(-?)(\d{1,16})\z/';
        if (! preg_match($pattern, $value, $m)) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount with at most '.$scale.' decimal place'.($scale === 1 ? '' : 's').'.']);
        }
        if ($m[1] === '-' && ! $signed) {
            throw ValidationException::withMessages(['amount' => 'Enter a positive amount.']);
        }
        $minor = (int) ($m[2].str_pad($m[3] ?? '', $scale, '0'));
        if ($minor > 1000000000000000) {
            throw ValidationException::withMessages(['amount' => 'The amount is too large.']);
        }

        return $m[1] === '-' ? -$minor : $minor;
    }

    private function matches(WalletAccount $wallet, array $snapshot): bool
    {
        return ($snapshot['currency'] ?? null) === $wallet->currency
            && (int) ($snapshot['minor_unit_scale'] ?? -1) === $wallet->minor_unit_scale;
    }

    private function entry(WalletAccount $wallet, string $type, int $availableDelta, int $reservedDelta, ?WalletReservation $reservation, ?CallAttempt $attempt, ?User $actor, string $reason, ?string $approval, string $key, ?WalletLedgerEntry $reversalOf = null): WalletLedgerEntry
    {
        if (WalletLedgerEntry::where('idempotency_key', $key)->exists()) {
            throw new RuntimeException('A ledger entry already exists for '.$key.' without a completed state transition.');
        }

        return WalletLedgerEntry::create([
            'wallet_id' => $wallet->id, 'client_id' => $wallet->client_id,
            'call_attempt_id' => $attempt?->id, 'wallet_reservation_id' => $reservation?->id,
            'type' => $type, 'available_delta_minor' => $availableDelta, 'reserved_delta_minor' => $reservedDelta,
            'available_after_minor' => $wallet->available_minor, 'reserved_after_minor' => $wallet->reserved_minor,
            'currency' => $wallet->currency, 'minor_unit_scale' => $wallet->minor_unit_scale,
            'reason' => mb_substr($reason, 0, 255), 'approval_reference' => $approval ? mb_substr($approval, 0, 255) : null,
            'actor_id' => $actor?->id, 'idempotency_key' => $key, 'reversal_of_id' => $reversalOf?->id, 'created_at' => now(),
        ]);
    }
}
