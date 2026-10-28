<?php

namespace App\Services\Billing;

use App\Models\AuditLog;
use App\Models\CallAttempt;
use App\Models\CallRating;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignVersion;
use App\Models\Client;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletLedgerEntry;
use App\Models\WalletReservation;
use App\Services\Broadcast\Readiness;
use App\Services\Clients\Tenancy;
use App\Services\Foundation;
use App\Services\Notifications\Notifier;
use Carbon\CarbonImmutable;
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
 * Client accounts must have a wallet before calls can be admitted.
 * Internal campaigns without a client remain outside client wallet billing.
 */
class Wallet
{
    /** Reasons an admission check can refuse to hold credit. */
    public const REASONS = ['wallet_missing', 'tariff_missing', 'tariff_not_priced', 'currency_mismatch', 'insufficient_credit', 'duration_missing'];

    public const TYPES = ['answer_charge', 'funding', 'adjustment', 'reversal', 'reservation', 'release', 'settlement'];

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
            'duration_missing' => 'The approved recording has no measured duration. Re-process it in the Audio Library before launching.',
            default => 'A calling wallet is not configured for this account.',
        };
    }

    /** Client-facing explanation of a credit or tariff state. */
    public static function clientMessage(string $reason): string
    {
        return match ($reason) {
            'insufficient_credit' => 'Available credit is below the maximum cost of one full call. New calls will not start until Finance tops up the wallet.',
            'duration_missing' => 'The selected recording has no measured length yet. Re-process it so its credit cost can be calculated.',
            'tariff_missing' => 'A call tariff is not assigned yet. Finance must configure billing before calls can be admitted.',
            'tariff_not_priced' => 'The assigned call tariff cannot be priced. Finance must assign a reviewed tariff version.',
            'currency_mismatch' => 'The wallet currency does not match the assigned call tariff. Finance must correct the billing configuration.',
            default => 'Credit checks are unavailable until Finance completes billing configuration.',
        };
    }

    /**
     * Reserved minutes for one attempt, rounded up like `ceil_minute_v1`
     * settlement: the measured recording length when supplied, otherwise the
     * configured maximum call duration.
     */
    public function exposureMinutes(?int $durationMs = null): int
    {
        if ($durationMs !== null) {
            if ($durationMs < 1 || $durationMs > 86400000) {
                throw new LogicException('Invalid recording duration for credit reservation.');
            }

            return max(1, intdiv($durationMs + 59999, 60000));
        }
        $seconds = (int) config('billing.max_call_seconds');
        if ($seconds < 60 || $seconds > 86400) {
            throw new LogicException('BILLING_MAX_CALL_SECONDS must be between 60 and 86400.');
        }

        return max(1, intdiv($seconds + 59, 60));
    }

    /** Maximum charge exposure for a frozen tariff snapshot, in minor units. */
    public function exposureMinor(?array $tariff, ?int $durationMs = null): ?int
    {
        $rate = filter_var($tariff['rate_minor_per_minute'] ?? null, FILTER_VALIDATE_INT);
        if ($rate === false || $rate < 0) {
            return null;
        }

        return $this->exposureMinutes($durationMs) * $rate;
    }

    /**
     * Evaluate whether a client campaign may admit another attempt.
     *
     * Client accounts require a wallet, a matching tariff and sufficient credit.
     * One-way campaigns pass the measured recording length so the hold covers the
     * recording itself (`ceil(minutes) × rate`) multiplied by the eligible
     * audience; interactive campaigns pass no duration and keep the configured
     * maximum-call exposure. Internal campaigns without a client remain outside
     * client wallet billing.
     */
    public function admission(?int $clientId, ?array $tariff, ?int $durationMs = null, int $recipients = 1): array
    {
        $base = ['applicable' => false, 'allowed' => true, 'reason' => null, 'exposure_minor' => 0, 'available_minor' => 0, 'wallet' => null];
        if ($clientId === null) {
            return array_merge($base, ['reason' => 'wallet_missing']);
        }
        $wallet = WalletAccount::where('client_id', $clientId)->first();
        if (! $wallet) {
            return array_merge($base, ['applicable' => true, 'allowed' => false, 'reason' => 'wallet_missing']);
        }
        $base = ['applicable' => true, 'allowed' => false, 'reason' => null, 'exposure_minor' => 0, 'available_minor' => $wallet->available_minor, 'wallet' => $wallet];
        if (! $tariff) {
            return array_merge($base, ['reason' => 'tariff_missing']);
        }
        if (! $this->matches($wallet, $tariff)) {
            return array_merge($base, ['reason' => 'currency_mismatch']);
        }
        if ($durationMs !== null && $durationMs < 1) {
            return array_merge($base, ['reason' => 'duration_missing']);
        }
        $exposure = $this->exposureMinor($tariff, $durationMs);
        if ($exposure === null) {
            return array_merge($base, ['reason' => 'tariff_not_priced']);
        }
        $exposure *= max(0, $recipients);
        if ($wallet->available_minor < $exposure) {
            return array_merge($base, ['reason' => 'insufficient_credit', 'exposure_minor' => $exposure]);
        }

        return array_merge($base, ['allowed' => true, 'exposure_minor' => $exposure]);
    }

    /** Hold client credit for one attempt. Only internal attempts without a client are exempt. */
    public function reserve(CallAttempt $attempt): ?WalletReservation
    {
        try {
            return DB::transaction(function () use ($attempt) {
                $clientId = app(Tenancy::class)->ownerOf($attempt);
                if ($clientId === null) {
                    return null;
                }
                $wallet = WalletAccount::where('client_id', $clientId)->lockForUpdate()->first();
                if (! $wallet) {
                    throw new WalletDeclined('wallet_missing');
                }
                $existing = WalletReservation::where('call_attempt_id', $attempt->id)->first();
                if ($existing) {
                    if ($existing->state === 'held') {
                        return $existing;
                    }
                    throw new WalletDeclined('reservation_'.$existing->state);
                }
                $budget = $attempt->campaign_version_id ? WalletReservation::where('campaign_version_id', $attempt->campaign_version_id)
                    ->where('campaign_recipient_id', $attempt->campaign_recipient_id)->whereNull('call_attempt_id')->where('state', 'held')->lockForUpdate()->first() : null;
                if ($budget) {
                    if ($budget->wallet_id !== $wallet->id || $budget->tariff_snapshot != $attempt->tariff_snapshot) {
                        throw new WalletDeclined('currency_mismatch');
                    }
                    $budget->update(['call_attempt_id' => $attempt->id]);

                    return $budget;
                }
                $tariff = $attempt->tariff_snapshot;
                if (! $tariff) {
                    throw new WalletDeclined('tariff_missing');
                }
                if (! $this->matches($wallet, $tariff)) {
                    throw new WalletDeclined('currency_mismatch');
                }
                $duration = $this->attemptDurationMs($attempt);
                if ($duration !== null && $duration < 1) {
                    throw new WalletDeclined('duration_missing');
                }
                $exposure = $this->exposureMinor($tariff, $duration);
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
                    'wallet_id' => $wallet->id,
                    'client_id' => $wallet->client_id,
                    'call_attempt_id' => $attempt->id,
                    'amount_minor' => $exposure,
                    'currency' => $wallet->currency,
                    'minor_unit_scale' => $wallet->minor_unit_scale,
                    'state' => 'held',
                    'reserved_at' => now(),
                ]);
                $this->entry(
                    $wallet,
                    'reservation',
                    -$exposure,
                    $exposure,
                    $reservation,
                    $attempt,
                    null,
                    ($duration === null ? 'Reserved maximum exposure for attempt ' : 'Reserved recording cost for attempt ').$attempt->uuid,
                    null,
                    'billing:reservation:'.$attempt->id
                );

                return $reservation;
            });
        } catch (WalletDeclined $e) {
            if ($e->reason === 'insufficient_credit') {
                $clientId = app(Tenancy::class)->ownerOf($attempt);
                $wallet = $clientId ? WalletAccount::where('client_id', $clientId)->first() : null;
                if ($wallet) {
                    app(Notifier::class)->lowBalance($wallet, (int) ($e->context['exposure_minor'] ?? 0));
                }
            }
            throw $e;
        }
    }

    /**
     * Measured recording length frozen into a one-way launch snapshot.
     *
     * Returns null for interactive attempts, which keep the configured maximum
     * call exposure, and 0 when a one-way snapshot lost its measurement so the
     * hold is refused instead of silently over-reserved.
     */
    public function attemptDurationMs(CallAttempt $attempt): ?int
    {
        $version = $attempt->campaign_version_id ? CampaignVersion::find($attempt->campaign_version_id) : null;
        $snapshot = $version?->snapshot;

        return ($snapshot['type'] ?? null) === 'one_way' ? (int) ($snapshot['broadcast']['audio_duration_ms'] ?? 0) : null;
    }

    /**
     * Reserve every eligible first call atomically at campaign launch.
     *
     * The hold is the calculated recording cost only: the approved recording's
     * billable minutes (rounded up like `ceil_minute_v1` settlement) at the
     * frozen tariff rate, multiplied by the eligible contacts in the campaign
     * audience. The configured maximum call duration is never used for one-way
     * campaigns, so launching a 45-second recording for 500 contacts holds 500
     * billed minutes rather than 500 maximum-length calls.
     */
    public function reserveCampaign(Campaign $campaign, CampaignVersion $version, User $actor): void
    {
        if ($campaign->client_id === null || $campaign->type !== 'one_way') {
            return;
        }
        DB::transaction(function () use ($campaign, $version, $actor) {
            $s = $version->snapshot['broadcast'];
            $tariff = $s['reservation_tariff'] ?? app(Tariffs::class)->snapshot($campaign->client_id);
            $duration = (int) ($s['audio_duration_ms'] ?? 0);
            if ($duration < 1) {
                throw ValidationException::withMessages(['broadcast' => self::message('duration_missing')]);
            }
            $wallet = WalletAccount::where('client_id', $campaign->client_id)->lockForUpdate()->firstOrFail();
            if (! $tariff || ! $this->matches($wallet, $tariff)) {
                throw ValidationException::withMessages(['broadcast' => 'Wallet and campaign tariff must match.']);
            }
            $perRecipient = $this->exposureMinor($tariff, $duration);
            if ($perRecipient === null) {
                throw ValidationException::withMessages(['broadcast' => self::message('tariff_not_priced')]);
            }
            $recipients = CampaignRecipient::where('campaign_id', $campaign->id)->where('status', 'pending')->orderBy('id')->get()
                ->reject(fn (CampaignRecipient $recipient) => app(Readiness::class)->exclusion($recipient->contact_id, $recipient->phone_e164, $campaign->purpose) !== null)
                ->reject(fn (CampaignRecipient $recipient) => WalletReservation::where('campaign_version_id', $version->id)
                    ->where('campaign_recipient_id', $recipient->id)->exists())
                ->values();
            // A reviewed zero-rate tariff needs no hold, and a fully excluded
            // audience holds nothing because no attempt can be dispatched.
            if ($recipients->isEmpty() || $perRecipient < 1) {
                return;
            }
            $total = $perRecipient * $recipients->count();
            if ($wallet->available_minor < $total) {
                throw ValidationException::withMessages(['broadcast' => 'Insufficient credit: '
                    .self::amount($total, $wallet->minor_unit_scale).' '.$wallet->currency.' is required to hold the recording cost for '
                    .number_format($recipients->count()).' eligible contacts. Ask Finance to top up the wallet before launching.']);
            }
            foreach ($recipients as $recipient) {
                $wallet->available_minor -= $perRecipient;
                $wallet->reserved_minor += $perRecipient;
                $wallet->save();
                $reservation = WalletReservation::create([
                    'wallet_id' => $wallet->id,
                    'client_id' => $campaign->client_id,
                    'campaign_version_id' => $version->id,
                    'campaign_recipient_id' => $recipient->id,
                    'tariff_snapshot' => $tariff,
                    'call_attempt_id' => null,
                    'amount_minor' => $perRecipient,
                    'currency' => $wallet->currency,
                    'minor_unit_scale' => $wallet->minor_unit_scale,
                    'state' => 'held',
                    'reserved_at' => now(),
                ]);
                $this->entry(
                    $wallet,
                    'reservation',
                    -$perRecipient,
                    $perRecipient,
                    $reservation,
                    null,
                    $actor,
                    'Recording-cost hold for campaign '.$campaign->id.', recipient '.$recipient->id,
                    null,
                    'billing:campaign-reservation:'.$version->id.':'.$recipient->id
                );
            }
        });
    }

    /** Return credit for recipients cancelled/excluded before an attempt was allocated. */
    public function releaseCampaignBudget(int $campaignId, string $reason, ?int $recipientId = null): void
    {
        DB::transaction(function () use ($campaignId, $reason, $recipientId) {
            $campaign = Campaign::findOrFail($campaignId);
            if ($campaign->client_id === null) {
                return;
            }
            $wallet = WalletAccount::where('client_id', $campaign->client_id)->lockForUpdate()->first();
            if (! $wallet) {
                return;
            }
            $query = WalletReservation::where('wallet_id', $wallet->id)->whereNull('call_attempt_id')->where('state', 'held')
                ->whereIn('campaign_version_id', CampaignVersion::where('campaign_id', $campaignId)->select('id'));
            if ($recipientId !== null) {
                $query->where('campaign_recipient_id', $recipientId);
            }
            foreach ($query->lockForUpdate()->get() as $reservation) {
                if ($wallet->reserved_minor < $reservation->amount_minor) {
                    throw new LogicException('Wallet held balance is inconsistent.');
                }
                $wallet->reserved_minor -= $reservation->amount_minor;
                $wallet->available_minor += $reservation->amount_minor;
                $wallet->save();
                $reservation->update(['state' => 'released', 'released_at' => now(), 'release_reason' => $reason]);
                $this->entry(
                    $wallet,
                    'release',
                    $reservation->amount_minor,
                    -$reservation->amount_minor,
                    $reservation,
                    null,
                    null,
                    $reason,
                    null,
                    'billing:campaign-release:'.$reservation->id
                );
            }
        });
    }

    /** Deduct the full frozen recording cost once on answer, regardless of call duration. */
    public function answer(CallAttempt $attempt): string
    {
        if (! $attempt->answered_at || ! $attempt->sent_at || ! $attempt->tariff_snapshot) {
            return 'not_applicable';
        }

        if ($attempt->recordingMinutes() === null) {
            app(Rating::class)->issue($attempt, 'recording_duration_missing', 'Approved campaign snapshot has no recording duration');

            return 'held';
        }

        return DB::transaction(function () use ($attempt) {
            $clientId = app(Tenancy::class)->ownerOf($attempt);
            $wallet = WalletAccount::where('client_id', $clientId)->lockForUpdate()->first();
            if (! $wallet || $clientId === null) {
                return 'not_applicable';
            }
            $reservation = WalletReservation::where('call_attempt_id', $attempt->id)->lockForUpdate()->first();
            if (! $reservation) {
                app(Rating::class)->issue($attempt, 'wallet_reservation_missing', 'Answered call has no wallet reservation');

                return 'no_reservation';
            }
            $key = 'billing:answer:'.$attempt->id;
            if ($reservation->state === 'settled' || WalletLedgerEntry::where('idempotency_key', $key)->exists()) {
                return 'duplicate';
            }
            if (! $this->matches($wallet, $attempt->tariff_snapshot)) {
                app(Rating::class)->issue($attempt, 'wallet_currency_mismatch', 'Answer charge currency differs from wallet');

                return 'held';
            }
            $charge = $attempt->recordingMinutes() * (int) $attempt->tariff_snapshot['rate_minor_per_minute'];
            $fromReserved = $reservation->state === 'held' ? min($charge, $reservation->amount_minor, $wallet->reserved_minor) : 0;
            $fromAvailable = min($charge - $fromReserved, $wallet->available_minor);
            $wallet->reserved_minor -= $fromReserved;
            $wallet->available_minor -= $fromAvailable;
            $wallet->save();
            $reservation->update(['answer_paid_minor' => $fromReserved + $fromAvailable, 'answer_reserved_minor' => $fromReserved]);
            $this->entry($wallet, 'answer_charge', -$fromAvailable, -$fromReserved, $reservation, $attempt, null,
                'Full recording charge on answer '.$attempt->uuid, null, $key);
            if ($fromReserved + $fromAvailable < $charge) {
                app(Rating::class)->issue($attempt, 'wallet_shortfall', 'Recording answer charge exceeds remaining wallet credit');
            }

            return 'charged';
        });
    }

    /** Release a held reservation after a definitively unanswered call. */
    public function release(CallAttempt $attempt, string $reason, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($attempt, $reason, $actor) {
            $reservation = WalletReservation::where('call_attempt_id', $attempt->id)->lockForUpdate()->first();
            if (! $reservation || $reservation->state !== 'held') {
                return false;
            }
            $wallet = WalletAccount::whereKey($reservation->wallet_id)->lockForUpdate()->firstOrFail();
            $amount = $reservation->amount_minor - $reservation->answer_reserved_minor;
            if ($wallet->reserved_minor < $amount) {
                app(Rating::class)->issue($attempt, 'wallet_reserved_underflow', 'Reserved balance is lower than the held reservation; finance review required.');

                return false;
            }
            $wallet->reserved_minor -= $amount;
            $wallet->available_minor += $amount;
            $wallet->save();
            $reservation->update(['state' => 'released', 'released_at' => now(), 'release_reason' => mb_substr($reason, 0, 255)]);
            $this->entry(
                $wallet,
                'release',
                $amount,
                -$amount,
                $reservation,
                $attempt,
                $actor,
                'Reservation released: '.$reason,
                null,
                'billing:release:'.$attempt->id
            );
            if ($actor) {
                app(Foundation::class)->audit($actor, 'billing.wallet_hold_expired', $reservation, [
                    'attempt_id' => $attempt->id,
                    'amount_minor' => $amount,
                    'reason' => $reason,
                ]);
            }

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
    public function expire(int $hours = 24, int $limit = 500, ?User $actor = null): int
    {
        $hours = max(1, min(24 * 30, $hours));
        $limit = max(1, min(1000, $limit));
        $attemptIds = WalletReservation::where('state', 'held')->whereNotNull('call_attempt_id')
            ->where('reserved_at', '<=', now()->subHours($hours))
            ->orderBy('id')->limit($limit)->pluck('call_attempt_id')->all();
        $released = 0;
        foreach ($attemptIds as $attemptId) {
            $attempt = CallAttempt::withoutGlobalScopes()->find($attemptId);
            // A rated attempt is settled by Rating; only unrated holds expire here.
            if (! $attempt || CallRating::where('call_attempt_id', $attempt->id)->exists()) {
                continue;
            }
            if ($attempt->answered_at) {
                app(Rating::class)->internal($attempt, $actor);

                continue;
            }
            if ($this->release($attempt, 'Hold expired after '.$hours.'h without final rating evidence', $actor)) {
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
                $released = $reservation->amount_minor - $reservation->answer_reserved_minor;
                $wallet->reserved_minor -= $released;
                $wallet->available_minor += $released;
            }
            $remaining = max(0, $charge - $reservation->answer_paid_minor);
            $payable = min($remaining, $wallet->available_minor);
            $shortfall = $remaining - $payable;
            $wallet->available_minor -= $payable;
            $wallet->save();
            $reservation->update([
                'state' => 'settled',
                'settled_at' => now(),
                'settled_minor' => $charge,
                'shortfall_minor' => $shortfall,
                'settlement_key' => 'billing:settlement:'.$attempt->id,
            ]);
            $this->entry(
                $wallet,
                'settlement',
                $released - $payable,
                -$released,
                $reservation,
                $attempt,
                null,
                'Settled rated attempt '.$attempt->uuid.($shortfall ? ' with uncovered shortfall' : ''),
                null,
                'billing:settlement:'.$attempt->id
            );
            if ($shortfall) {
                app(Rating::class)->issue($attempt, 'wallet_shortfall', 'Rated charge '.self::amount($charge, $wallet->minor_unit_scale).' '.$wallet->currency
                    .' exceeded available credit; uncovered '.self::amount($shortfall, $wallet->minor_unit_scale).' '.$wallet->currency.'.');
                AuditLog::create([
                    'client_id' => $wallet->client_id,
                    'actor_id' => null,
                    'action' => 'billing.wallet_shortfall',
                    'entity_type' => $attempt->getMorphClass(),
                    'entity_id' => $attempt->id,
                    'details' => ['charge_minor' => $charge, 'paid_minor' => $payable, 'shortfall_minor' => $shortfall, 'currency' => $wallet->currency],
                    'created_at' => now(),
                ]);
            }
            if ($wallet->available_minor < $reservation->amount_minor) {
                app(Notifier::class)->lowBalance($wallet, (int) $reservation->amount_minor);
            }

            return $shortfall ? 'settled_shortfall' : 'settled';
        });
    }

    public function open(User $actor, Client $client, string $currency, int $scale, string $reason): WalletAccount
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $data = Validator::make(['currency' => $currency, 'minor_unit_scale' => $scale, 'reason' => $reason], [
            'currency' => 'required|regex:/\A[A-Z]{3}\z/',
            'minor_unit_scale' => 'required|integer|min:0|max:6',
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
            $tariff = app(Tariffs::class)->snapshot($locked->id);
            if ($tariff && (($tariff['currency'] ?? null) !== $data['currency'] || (int) ($tariff['minor_unit_scale'] ?? -1) !== (int) $data['minor_unit_scale'])) {
                throw ValidationException::withMessages(['currency' => 'The wallet currency and minor-unit scale must match the assigned call tariff ('
                    .$tariff['currency'].' / '.$tariff['minor_unit_scale'].').']);
            }
            $wallet = WalletAccount::create([
                'client_id' => $locked->id,
                'currency' => $data['currency'],
                'minor_unit_scale' => (int) $data['minor_unit_scale'],
                'available_minor' => 0,
                'reserved_minor' => 0,
            ]);
            app(Foundation::class)->audit($actor, 'billing.wallet_opened', $wallet, [
                'currency' => $wallet->currency,
                'minor_unit_scale' => $wallet->minor_unit_scale,
                'reason' => $data['reason'],
            ]);

            return $wallet;
        });
    }

    /**
     * Credit a verified online top-up. There is no human actor: the verified
     * provider callback is the approval, and the intent id is the unique key.
     */
    public function creditPayment(PaymentIntent $intent, string $eventId): WalletLedgerEntry
    {
        if ($intent->amount_minor < 1 || ! in_array($intent->status, ['pending', 'failed', 'cancelled'], true)) {
            throw new RuntimeException('Only an open or failed payment can be credited.');
        }
        $key = 'payment:'.$intent->public_id;

        return DB::transaction(function () use ($intent, $eventId, $key) {
            $wallet = WalletAccount::whereKey($intent->wallet_id)->lockForUpdate()->firstOrFail();
            if ((int) $wallet->client_id !== (int) $intent->client_id) {
                throw new RuntimeException('The payment wallet belongs to another client.');
            }
            if ($wallet->currency !== $intent->currency || (int) $wallet->minor_unit_scale !== (int) $intent->minor_unit_scale) {
                throw new RuntimeException('The payment currency no longer matches the wallet.');
            }
            $existing = WalletLedgerEntry::where('idempotency_key', $key)->first();
            if ($existing) {
                if ((int) $existing->wallet_id !== (int) $wallet->id || (int) $existing->available_delta_minor !== (int) $intent->amount_minor) {
                    throw new RuntimeException('A different ledger entry already uses this payment reference.');
                }

                return $existing;
            }
            $wallet->available_minor += $intent->amount_minor;
            $wallet->save();

            return $this->entry(
                $wallet,
                'funding',
                $intent->amount_minor,
                0,
                null,
                null,
                null,
                'Online top-up '.$intent->public_id,
                'Provider event '.$eventId,
                $key,
            );
        });
    }

    /** Approved funding or adjustment. Positive amounts credit, negative amounts debit. */
    public function post(User $actor, WalletAccount $wallet, string $kind, string $amount, string $reason, string $approval, ?string $key = null): WalletLedgerEntry
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $data = Validator::make(compact('kind', 'amount', 'reason', 'approval'), [
            'kind' => 'required|in:funding,adjustment',
            'amount' => 'required|string|max:32',
            'reason' => 'required|string|min:3|max:255',
            'approval' => 'required|string|min:3|max:255',
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
                'entry_id' => $entry->id,
                'amount_minor' => $delta,
                'currency' => $wallet->currency,
                'approval_reference' => $data['approval'],
            ]);

            return $entry;
        });
    }

    /**
     * Credit a wallet directly from the administrator console. This is the
     * supported manual-credit path for authorized administrators; it still
     * writes an append-only funding entry and an immutable audit record.
     */
    public function manualCredit(User $actor, WalletAccount $wallet, string $amount, string $reason, ?string $approval = null, ?string $key = null): WalletLedgerEntry
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $data = Validator::make(compact('amount', 'reason', 'approval'), [
            'amount' => 'required|string|max:32',
            'reason' => 'required|string|min:3|max:255',
            'approval' => 'nullable|string|max:255',
        ])->validate();
        $minor = $this->parse($data['amount'], $wallet->minor_unit_scale);
        if ($minor < 1) {
            throw ValidationException::withMessages(['amount' => 'Enter a positive credit amount.']);
        }
        // The authenticated administrator is the authorization; an external
        // approval reference is optional but recorded when supplied.
        $reference = trim((string) ($data['approval'] ?? ''))
            ?: mb_substr('Manual credit by '.$actor->name.' (user #'.$actor->id.') on '.now()->toIso8601String(), 0, 255);

        return $this->post(
            $actor,
            $wallet,
            'funding',
            self::amount($minor, $wallet->minor_unit_scale),
            $data['reason'],
            $reference,
            $key ?: 'admin:credit:'.Str::uuid(),
        );
    }

    /** Reverse an approved funding/adjustment entry with an opposite ledger entry. */
    public function reverse(User $actor, WalletAccount $wallet, WalletLedgerEntry $entry, string $reason, string $approval): WalletLedgerEntry
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $data = Validator::make(compact('reason', 'approval'), [
            'reason' => 'required|string|min:3|max:255',
            'approval' => 'required|string|min:3|max:255',
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
            $reversal = $this->entry(
                $wallet,
                'reversal',
                $delta,
                0,
                null,
                null,
                $actor,
                $data['reason'],
                $data['approval'],
                'billing:reversal:'.$entry->id,
                $entry
            );
            app(Foundation::class)->audit($actor, 'billing.wallet_reversal', $wallet, [
                'entry_id' => $reversal->id,
                'reversal_of' => $entry->id,
                'amount_minor' => $delta,
                'approval_reference' => $data['approval'],
            ]);

            return $reversal;
        });
    }

    /**
     * Operational counts for the admin console and client wallet history.
     * Money values are integer minor units, matching the ledger.
     */
    public function summary(WalletAccount $wallet): array
    {
        // One aggregate keeps the client dashboard and wallet pages cheap even
        // as reservation history grows.
        $row = WalletReservation::where('wallet_id', $wallet->id)->selectRaw(
            "SUM(CASE WHEN state = 'held' THEN 1 ELSE 0 END) as held_count,
             COALESCE(SUM(CASE WHEN state = 'held' THEN amount_minor - answer_reserved_minor ELSE 0 END), 0) as held_minor,
             SUM(CASE WHEN state = 'held' AND reserved_at <= ? THEN 1 ELSE 0 END) as stale_hold_count,
             MIN(CASE WHEN state = 'held' THEN reserved_at END) as oldest_hold_at,
             SUM(CASE WHEN state = 'settled' THEN 1 ELSE 0 END) as settled_count,
             COALESCE(SUM(CASE WHEN state = 'settled' THEN settled_minor ELSE 0 END), 0) as settled_minor,
             SUM(CASE WHEN state = 'settled' AND shortfall_minor > 0 THEN 1 ELSE 0 END) as shortfall_count,
             COALESCE(SUM(CASE WHEN state = 'settled' THEN shortfall_minor ELSE 0 END), 0) as shortfall_minor,
             SUM(CASE WHEN state = 'released' THEN 1 ELSE 0 END) as released_count",
            [now()->subDay()]
        )->first();

        return [
            'held_count' => (int) $row->held_count,
            'held_minor' => (int) $row->held_minor,
            'stale_hold_count' => (int) $row->stale_hold_count,
            'oldest_hold_at' => $row->oldest_hold_at ? CarbonImmutable::parse($row->oldest_hold_at) : null,
            'settled_count' => (int) $row->settled_count,
            'settled_minor' => (int) $row->settled_minor,
            'shortfall_count' => (int) $row->shortfall_count,
            'shortfall_minor' => (int) $row->shortfall_minor,
            'released_count' => (int) $row->released_count,
        ];
    }

    /**
     * Informational view of one full-hold affordability at the assigned tariff.
     * admission() remains the authoritative origination gate; this only lets the
     * portal explain a low-credit state before an operator tries to launch.
     */
    public function affordability(WalletAccount $wallet, ?array $tariff): array
    {
        $base = ['exposure_minor' => 0, 'covered_calls' => 0, 'remaining_minutes' => null, 'low' => true, 'reason' => null];
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
        if ($exposure === 0) {
            // A reviewed zero-rate tariff needs no credit, so it is never "low".
            return ['exposure_minor' => 0, 'covered_calls' => null, 'remaining_minutes' => null, 'low' => false, 'reason' => null];
        }
        $covered = intdiv($wallet->available_minor, $exposure);

        return [
            'exposure_minor' => $exposure,
            'covered_calls' => $covered,
            // Billing rounds each answered call up to whole minutes. Held credit is unavailable.
            'remaining_minutes' => intdiv($wallet->available_minor, (int) $tariff['rate_minor_per_minute']),
            'low' => $covered < 1,
            'reason' => $covered < 1 ? 'insufficient_credit' : null,
        ];
    }

    /**
     * Reconcile stored balances, the running ledger chain, holds and the
     * reservation lifecycle with the immutable ledger.
     */
    public function verify(WalletAccount $wallet): array
    {
        $available = (int) WalletLedgerEntry::where('wallet_id', $wallet->id)->sum('available_delta_minor');
        $reserved = (int) WalletLedgerEntry::where('wallet_id', $wallet->id)->sum('reserved_delta_minor');
        $held = (int) WalletReservation::where('wallet_id', $wallet->id)->where('state', 'held')->selectRaw('COALESCE(SUM(amount_minor - answer_reserved_minor), 0) as total')->value('total');
        $chain = $this->ledgerChain($wallet);
        $lifecycle = $this->lifecycle($wallet);

        $availableMatch = $available === $wallet->available_minor;
        $reservedMatch = $reserved === $wallet->reserved_minor;
        $heldMatch = $held === $wallet->reserved_minor;
        $issues = [];
        if (! $availableMatch) {
            $issues[] = 'Stored available balance does not match the ledger.';
        }
        if (! $reservedMatch) {
            $issues[] = 'Stored reserved balance does not match the ledger.';
        }
        if (! $heldMatch) {
            $issues[] = 'Reserved balance does not match open holds.';
        }
        if (! $chain['chain_ok']) {
            $issues[] = $chain['chain_issue'];
        }
        if ($lifecycle['reservation_entry_missing'] > 0) {
            $issues[] = $lifecycle['reservation_entry_missing'].' reservation(s) lack an opening hold entry.';
        }
        if ($lifecycle['release_entry_missing'] > 0) {
            $issues[] = $lifecycle['release_entry_missing'].' released reservation(s) lack a release entry.';
        }
        if ($lifecycle['settlement_entry_missing'] > 0) {
            $issues[] = $lifecycle['settlement_entry_missing'].' settled reservation(s) lack a settlement entry.';
        }
        if (! $lifecycle['settlement_amounts_match']) {
            $issues[] = 'Settlement debits do not equal settled charges minus shortfalls.';
        }

        return array_merge([
            'available_match' => $availableMatch,
            'reserved_match' => $reservedMatch,
            'held_match' => $heldMatch,
            'ledger_available_minor' => $available,
            'ledger_reserved_minor' => $reserved,
            'held_minor' => $held,
            'ok' => $issues === [],
            'issues' => $issues,
        ], $chain, $lifecycle);
    }

    /** Rebuild the append-only balance chain in entry order. */
    private function ledgerChain(WalletAccount $wallet): array
    {
        $runningAvailable = 0;
        $runningReserved = 0;
        $chainOk = true;
        $chainIssue = null;
        $entries = WalletLedgerEntry::where('wallet_id', $wallet->id)->orderBy('id')
            ->get(['id', 'available_delta_minor', 'reserved_delta_minor', 'available_after_minor', 'reserved_after_minor']);
        foreach ($entries as $entry) {
            $runningAvailable += $entry->available_delta_minor;
            $runningReserved += $entry->reserved_delta_minor;
            if ($runningAvailable !== $entry->available_after_minor || $runningReserved !== $entry->reserved_after_minor) {
                $chainOk = false;
                $chainIssue ??= 'Ledger entry #'.$entry->id.' carries balances that do not follow from its deltas.';
            }
        }

        return ['chain_ok' => $chainOk, 'chain_issue' => $chainIssue];
    }

    /** Every reservation must have an opening hold and the matching closing entry. */
    private function lifecycle(WalletAccount $wallet): array
    {
        $reservationEntries = WalletLedgerEntry::where('wallet_id', $wallet->id)
            ->where('type', 'reservation')->whereNotNull('wallet_reservation_id')->select('wallet_reservation_id');
        $releaseEntries = WalletLedgerEntry::where('wallet_id', $wallet->id)
            ->where('type', 'release')->whereNotNull('wallet_reservation_id')->select('wallet_reservation_id');
        $settlementEntries = WalletLedgerEntry::where('wallet_id', $wallet->id)
            ->where('type', 'settlement')->whereNotNull('wallet_reservation_id')->select('wallet_reservation_id');
        $reservationMissing = WalletReservation::where('wallet_id', $wallet->id)->whereNotIn('id', $reservationEntries)->count();
        $releaseMissing = WalletReservation::where('wallet_id', $wallet->id)->where('state', 'released')->whereNotIn('id', $releaseEntries)->count();
        $settlementMissing = WalletReservation::where('wallet_id', $wallet->id)->where('state', 'settled')->whereNotIn('id', $settlementEntries)->count();

        // A settlement entry's (available + reserved) delta is the negative of the
        // amount actually paid; shortfalls remain receivables and are not debited.
        $paid = -(int) WalletLedgerEntry::where('wallet_id', $wallet->id)->whereIn('type', ['settlement', 'answer_charge'])
            ->whereIn('wallet_reservation_id', WalletReservation::where('wallet_id', $wallet->id)->where('state', 'settled')->select('id'))
            ->selectRaw('COALESCE(SUM(available_delta_minor + reserved_delta_minor), 0) as total')->value('total');
        $payable = (int) WalletReservation::where('wallet_id', $wallet->id)->where('state', 'settled')
            ->selectRaw('COALESCE(SUM(settled_minor - shortfall_minor), 0) as total')->value('total');

        return [
            'reservation_entry_missing' => $reservationMissing,
            'release_entry_missing' => $releaseMissing,
            'settlement_entry_missing' => $settlementMissing,
            'settlement_amounts_match' => $paid === $payable,
            'settlement_paid_minor' => $paid,
            'settled_payable_minor' => $payable,
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
            'wallet_id' => $wallet->id,
            'client_id' => $wallet->client_id,
            'call_attempt_id' => $attempt?->id,
            'wallet_reservation_id' => $reservation?->id,
            'type' => $type,
            'available_delta_minor' => $availableDelta,
            'reserved_delta_minor' => $reservedDelta,
            'available_after_minor' => $wallet->available_minor,
            'reserved_after_minor' => $wallet->reserved_minor,
            'currency' => $wallet->currency,
            'minor_unit_scale' => $wallet->minor_unit_scale,
            'reason' => mb_substr($reason, 0, 255),
            'approval_reference' => $approval ? mb_substr($approval, 0, 255) : null,
            'actor_id' => $actor?->id,
            'idempotency_key' => $key,
            'reversal_of_id' => $reversalOf?->id,
            'created_at' => now(),
        ]);
    }
}
