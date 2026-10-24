<?php

namespace App\Services\Billing;

use App\Models\AudioFile;
use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\ClientAccount;
use App\Models\RateVersion;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Services\Broadcast\Engine;
use App\Services\Foundation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Ledger
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['billing' => $message]);
    }

    public function price(User $actor, array $data): RateVersion
    {
        Gate::forUser($actor)->authorize('billing.manage');
        $data = Validator::make($data, ['plan_code' => 'required|regex:/\A[a-zA-Z0-9_-]{1,80}\z/', 'currency' => 'required|regex:/\A[A-Z]{3}\z/', 'minor_digits' => 'required|integer|min:0|max:6', 'rate_minor_per_minute' => 'required|integer|min:1|max:1000000', 'evidence' => 'required|string|max:2000', 'duration_basis' => 'sometimes|required|in:recording,answered', 'increment_seconds' => 'sometimes|required|integer|in:1,6,30,60', 'playback_failure_policy' => 'sometimes|required|in:charge,waive,review', 'reservation_seconds' => 'sometimes|required|integer|min:1|max:86400'])->validate();

        return DB::transaction(function () use ($actor, $data) {
            app(Engine::class)->mutex();
            $rate = RateVersion::create($data + ['version' => (RateVersion::where('plan_code', $data['plan_code'])->max('version') ?? 0) + 1, 'duration_basis' => 'recording', 'increment_seconds' => 60, 'playback_failure_policy' => 'charge', 'reservation_seconds' => 620, 'created_by' => $actor->id, 'created_at' => now()]);
            app(Foundation::class)->audit($actor, 'billing.price_version_created', $rate, ['policy' => $rate->toArray()]);

            return $rate;
        });
    }

    public function assign(User $actor, ClientAccount $account, RateVersion $rate, string $reason): Wallet
    {
        Gate::forUser($actor)->authorize('billing.manage');
        Validator::make(['reason' => $reason], ['reason' => 'required|string|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $account, $rate, $reason) {
            app(Engine::class)->mutex();
            $wallet = Wallet::firstOrCreate(['client_account_id' => $account->id], ['currency' => $rate->currency, 'minor_digits' => $rate->minor_digits]);
            $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            if ($wallet->currency !== $rate->currency || (int) $wallet->minor_digits !== (int) $rate->minor_digits) {
                $this->fail('A wallet denomination cannot change. Assign a price with the same currency and precision.');
            }
            $before = $wallet->rate_version_id;
            $wallet->update(['rate_version_id' => $rate->id]);
            app(Foundation::class)->audit($actor, 'billing.price_assigned', $wallet, ['before' => $before, 'rate_version_id' => $rate->id, 'reason' => $reason]);

            return $wallet;
        });
    }

    public function adjust(User $actor, Wallet $wallet, int $amount, string $key, string $reason, ?int $refundEntry = null): WalletLedgerEntry
    {
        Gate::forUser($actor)->authorize('billing.manage');
        Validator::make(compact('amount', 'key', 'reason'), ['amount' => 'required|integer|not_in:0|between:-1000000000,1000000000', 'key' => 'required|regex:/\A[a-zA-Z0-9_-]{8,100}\z/', 'reason' => 'required|string|max:2000'])->validate();

        return DB::transaction(function () use ($actor, $wallet, $amount, $key, $reason, $refundEntry) {
            app(Engine::class)->mutex();
            $wallet = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $entryKey = 'adjustment:'.$key;
            if ($existing = WalletLedgerEntry::where('wallet_id', $wallet->id)->where('idempotency_key', $entryKey)->first()) {
                if ((int) $existing->amount_minor !== $amount || $existing->reason !== $reason || (int) $existing->refund_entry_id !== (int) $refundEntry) {
                    $this->fail('This request reference was already used with different adjustment details.');
                }

                return $existing;
            }
            $kind = 'adjustment';
            $attemptId = null;
            if ($refundEntry) {
                $original = WalletLedgerEntry::where('wallet_id', $wallet->id)->where('kind', 'call_charge')->findOrFail($refundEntry);
                $kind = 'refund';
                $attemptId = $original->call_attempt_id;
                $refunded = WalletLedgerEntry::where('wallet_id', $wallet->id)->where('call_attempt_id', $attemptId)->where('kind', 'refund')->sum('amount_minor');
                if ($amount <= 0 || $amount + $refunded > -$original->amount_minor) {
                    $this->fail('Refund must be positive and cannot exceed the remaining original charge.');
                }
            }
            if ($wallet->balance_minor + $amount < $wallet->reserved_minor || $wallet->balance_minor + $amount > 1000000000000) {
                $this->fail('Adjustment would consume reserved funds or exceed the wallet limit.');
            }
            if (! $this->reconciliation($wallet)['matches']) {
                $this->fail('Wallet reconciliation mismatch. Investigate before posting an adjustment.');
            }
            $entry = $this->entry($wallet, $amount, $kind, $entryKey, $reason, $actor->id, $attemptId, $refundEntry);
            app(Foundation::class)->audit($actor, 'billing.'.$kind, $wallet, ['entry_id' => $entry->id, 'amount_minor' => $amount, 'refund_entry_id' => $refundEntry, 'reason' => $reason]);

            return $entry;
        });
    }

    private function entry(Wallet $wallet, int $amount, string $kind, string $key, string $reason, ?int $actor = null, ?int $attempt = null, ?int $refundEntry = null): WalletLedgerEntry
    {
        $balance = (int) $wallet->balance_minor + $amount;
        if ($balance < 0) {
            $this->fail('Insufficient wallet balance.');
        }
        $entry = WalletLedgerEntry::create(['wallet_id' => $wallet->id, 'refund_entry_id' => $refundEntry, 'actor_id' => $actor, 'call_attempt_id' => $attempt, 'kind' => $kind, 'amount_minor' => $amount, 'balance_after_minor' => $balance, 'idempotency_key' => $key, 'reason' => $reason, 'created_at' => now()]);
        $wallet->update(['balance_minor' => $balance]);

        return $entry;
    }

    public static function format(int $amount, int $digits): string
    {
        $sign = $amount < 0 ? '-' : '';
        $value = (string) abs($amount);
        if ($digits === 0) {
            return $sign.$value;
        }
        $value = str_pad($value, $digits + 1, '0', STR_PAD_LEFT);

        return $sign.substr($value, 0, -$digits).'.'.substr($value, -$digits);
    }

    public function quote(Campaign $campaign): ?array
    {
        if ($campaign->client_account_id === null) {
            return null;
        }
        if (! config('billing.enabled')) {
            if (Wallet::where('client_account_id', $campaign->client_account_id)->exists()) {
                $this->fail('Billing admission is disabled for this configured client wallet.');
            }

            return null;
        }
        $wallet = Wallet::where('client_account_id', $campaign->client_account_id)->lockForUpdate()->first();
        $rate = $wallet ? RateVersion::find($wallet->rate_version_id) : null;
        if (! $wallet || ! $rate) {
            $this->fail('An assigned price and funded wallet are required before client launch.');
        }
        if (! $this->reconciliation($wallet)['matches']) {
            $this->fail('Wallet reconciliation mismatch. Contact support.');
        }
        $audio = AudioFile::findOrFail($campaign->configuration['audio_id'] ?? null);
        if (! is_numeric($audio->duration) || $audio->duration <= 0) {
            $this->fail('A verified recording duration is required for pricing.');
        }
        $basis = $rate->duration_basis;
        if ($basis === 'answered' && $campaign->type !== 'one_way') {
            $this->fail('Answered-duration pricing currently supports one-way campaigns only.');
        }
        $policy = ['wallet_id' => $wallet->id, 'rate_version_id' => $rate->id, 'currency' => $wallet->currency,
            'minor_digits' => (int) $wallet->minor_digits, 'rate_minor_per_minute' => (int) $rate->rate_minor_per_minute,
            'duration_basis' => $basis, 'recording_seconds' => $audio->duration,
            'increment_seconds' => (int) $rate->increment_seconds, 'playback_failure_policy' => $rate->playback_failure_policy,
            'reservation_seconds' => (int) $rate->reservation_seconds];
        $seconds = $basis === 'answered' ? $policy['reservation_seconds'] : $audio->duration;
        $policy['rounded_minutes'] = max(1, (int) ceil($seconds / 60)); // Legacy preview compatibility.
        $policy['call_charge_minor'] = Policy::charge($policy, $seconds);
        if ($wallet->balance_minor - $wallet->reserved_minor < $policy['call_charge_minor']) {
            $this->fail('Insufficient available credit for one call reservation.');
        }

        return $policy;
    }

    public function reserve(CallAttempt $attempt, Campaign $campaign, array $snapshot): bool
    {
        return DB::transaction(function () use ($attempt, $campaign, $snapshot) {
            app(Engine::class)->mutex();

            return $this->reserveLocked($attempt, $campaign, $snapshot);
        });
    }

    private function reserveLocked(CallAttempt $attempt, Campaign $campaign, array $snapshot): bool
    {
        if ($campaign->client_account_id === null) {
            return true;
        }
        $policy = $snapshot['billing'] ?? null;
        if (! $policy) {
            return ! config('billing.enabled') && ! Wallet::where('client_account_id', $campaign->client_account_id)->exists(); // No repricing of legacy runs.
        }
        if (! config('billing.enabled')) {
            return false;
        }
        $wallet = Wallet::where('client_account_id', $campaign->client_account_id)->whereKey($policy['wallet_id'])->lockForUpdate()->first();
        if (! $wallet || $wallet->currency !== $policy['currency'] || (int) $wallet->minor_digits !== $policy['minor_digits']) {
            return false;
        }
        if (! $this->reconciliation($wallet)['matches']) {
            return false;
        }
        $amount = (int) $policy['call_charge_minor'];
        $existing = DB::table('wallet_reservations')->where('call_attempt_id', $attempt->id)->first();
        if ($existing) {
            return $existing->status === 'held';
        }
        if ($amount < 1 || $wallet->balance_minor - $wallet->reserved_minor < $amount) {
            return false;
        }
        DB::table('wallet_reservations')->insert(['wallet_id' => $wallet->id, 'call_attempt_id' => $attempt->id, 'amount_minor' => $amount, 'held_minor' => $amount, 'charged_minor' => 0, 'status' => 'held', 'policy' => json_encode($policy, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        $wallet->update(['reserved_minor' => $wallet->reserved_minor + $amount]);

        return true;
    }

    public function canSend(CallAttempt $attempt, Campaign $campaign, array $snapshot): bool
    {
        if ($campaign->client_account_id === null) {
            return true;
        }
        $policy = $snapshot['billing'] ?? null;
        if (! $policy) {
            return ! config('billing.enabled') && ! Wallet::where('client_account_id', $campaign->client_account_id)->exists();
        }
        if (! config('billing.enabled')) {
            return false;
        }
        $wallet = Wallet::where('client_account_id', $campaign->client_account_id)->whereKey($policy['wallet_id'])->lockForUpdate()->first();
        if (! $wallet || ! $this->reconciliation($wallet)['matches']) {
            return false;
        }
        $reservation = DB::table('wallet_reservations')->where('wallet_id', $wallet->id)->where('call_attempt_id', $attempt->id)->first();

        return $reservation && $reservation->status === 'held' && (int) $reservation->held_minor === (int) $policy['call_charge_minor'] && json_decode($reservation->policy, true) === $policy;
    }

    public function settle(CallAttempt $attempt): void
    {
        DB::transaction(function () use ($attempt) {
            app(Engine::class)->mutex();
            $this->settleLocked($attempt->fresh());
        });
    }

    private function settleLocked(CallAttempt $attempt): void
    {
        // Every caller holds the gateway mutex. Settlement remains active for frozen priced runs even if admission is disabled.
        $r = DB::table('wallet_reservations')->where('call_attempt_id', $attempt->id)->lockForUpdate()->first();
        if (! $r) {
            return;
        }
        if ($r->status === 'settled') {
            $policy = json_decode($r->policy, true, 512, JSON_THROW_ON_ERROR);
            $failed = ($attempt->outcome === 'playback_failed' || $attempt->playback_failed_at) && ! $attempt->playback_completed_at;
            if ($failed && ($policy['playback_failure_policy'] ?? 'charge') === 'waive') {
                $this->refundLateFailure($r);
            } elseif ($failed && ($policy['playback_failure_policy'] ?? 'charge') === 'review') {
                DB::table('wallet_reservations')->where('id', $r->id)->update(['review_reason' => 'Late playback failure after settlement: review the posted charge and issue an audited refund if appropriate.', 'updated_at' => now()]);
            }

            return;
        }
        if ($r->status === 'released') {
            if ($attempt->answered_at && $attempt->sent_at && ! in_array($r->review_reason, ['Playback failure waived by frozen policy.', 'Released by administrator decision.'], true)) {
                DB::table('wallet_reservations')->where('id', $r->id)->update(['status' => 'review', 'review_reason' => 'Late answer evidence after release; manual reconciliation required.', 'updated_at' => now()]);
            }

            return;
        }
        if (! $attempt->ended_at || $attempt->state === 'unknown') {
            if ($attempt->state === 'unknown') {
                DB::table('wallet_reservations')->where('id', $r->id)->update(['status' => 'review', 'review_reason' => 'Uncertain call outcome; funds retained.', 'updated_at' => now()]);
            }

            return;
        }
        if ($r->status === 'review' && (int) $r->held_minor === 0) {
            return;
        }
        $wallet = Wallet::whereKey($r->wallet_id)->lockForUpdate()->firstOrFail();
        if (! $this->reconciliation($wallet)['matches']) {
            DB::table('wallet_reservations')->where('id', $r->id)->update(['status' => 'review', 'review_reason' => 'Wallet totals do not reconcile; investigate before settlement.', 'updated_at' => now()]);

            return;
        }
        if (! $attempt->sent_at || ! $attempt->answered_at) {
            // Busy/no answer/failure with a confirmed terminal outcome: no calling debit.
            $this->finish($wallet, $r, 0, 'released');

            return;
        }
        $policy = json_decode($r->policy, true, 512, JSON_THROW_ON_ERROR);
        $failurePolicy = $policy['playback_failure_policy'] ?? 'charge';
        $playbackFailed = ($attempt->outcome === 'playback_failed' || $attempt->playback_failed_at) && ! $attempt->playback_completed_at;
        if ($playbackFailed && $failurePolicy !== 'charge') {
            if ($failurePolicy === 'waive') {
                $this->finish($wallet, $r, 0, 'released');
                DB::table('wallet_reservations')->where('id', $r->id)->update(['review_reason' => 'Playback failure waived by frozen policy.']);
            } else {
                $this->review($r, 'Playback failure requires an administrator billing decision.');
            }

            return;
        }
        // A manual playback-failure review remains held even when later events are replayed.
        if ($r->status === 'review' && str_starts_with($r->review_reason ?? '', 'Playback failure requires')) {
            return;
        }
        $seconds = $r->verified_seconds ?? $attempt->billable_seconds;
        if (($policy['duration_basis'] ?? 'recording') === 'answered' && $seconds === null) {
            $this->review($r, 'Verified answered duration is required; funds retained.');

            return;
        }
        $charge = ($policy['duration_basis'] ?? 'recording') === 'answered' ? Policy::charge($policy, (int) $seconds) : (int) $r->amount_minor;
        if ($charge > $r->held_minor) {
            $this->review($r, 'Verified duration exceeds reserved exposure; manual reconciliation required.');

            return;
        }
        $this->finish($wallet, $r, $charge, 'settled');
    }

    private function refundLateFailure(object $reservation): void
    {
        $wallet = Wallet::whereKey($reservation->wallet_id)->lockForUpdate()->firstOrFail();
        if (! $this->reconciliation($wallet)['matches']) {
            DB::table('wallet_reservations')->where('id', $reservation->id)->update(['review_reason' => 'Late failure waiver awaits wallet reconciliation.', 'updated_at' => now()]);

            return;
        }
        $charge = WalletLedgerEntry::where('wallet_id', $wallet->id)->where('call_attempt_id', $reservation->call_attempt_id)->where('kind', 'call_charge')->firstOrFail();
        $refunded = (int) WalletLedgerEntry::where('wallet_id', $wallet->id)->where('refund_entry_id', $charge->id)->where('kind', 'refund')->sum('amount_minor');
        $remaining = -(int) $charge->amount_minor - $refunded;
        if ($remaining > 0) {
            $this->entry($wallet, $remaining, 'refund', 'policy-waiver:'.$reservation->call_attempt_id,
                'Automatic refund for late confirmed playback failure under the frozen waiver policy.', null, $reservation->call_attempt_id, $charge->id);
        }
        DB::table('wallet_reservations')->where('id', $reservation->id)->update(['review_reason' => 'Late playback failure waived through a compensating refund.', 'updated_at' => now()]);
    }

    private function review(object $reservation, string $reason): void
    {
        DB::table('wallet_reservations')->where('id', $reservation->id)->update(['status' => 'review', 'review_reason' => $reason, 'updated_at' => now()]);
    }

    private function finish(Wallet $wallet, object $reservation, int $charge, string $status, ?User $actor = null, string $reason = 'Answered call billed under the frozen execution policy.'): void
    {
        $available = $wallet->balance_minor - $wallet->reserved_minor + $reservation->held_minor;
        if ($charge > $available) {
            $this->fail('Insufficient unreserved balance for reconciliation.');
        }
        $wallet->update(['reserved_minor' => $wallet->reserved_minor - $reservation->held_minor]);
        if ($charge > 0) {
            $this->entry($wallet, -$charge, 'call_charge', 'call:'.$reservation->call_attempt_id, $reason, $actor?->id, $reservation->call_attempt_id);
        }
        DB::table('wallet_reservations')->where('id', $reservation->id)->update(['held_minor' => 0, 'charged_minor' => $charge, 'status' => $status, 'review_reason' => null, 'updated_at' => now()]);
    }

    public function resolve(User $actor, int $reservationId, string $decision, string $evidence, ?int $verifiedSeconds = null): void
    {
        Gate::forUser($actor)->authorize('billing.manage');
        Validator::make(compact('decision', 'evidence', 'verifiedSeconds'), ['decision' => 'required|in:charge,release', 'evidence' => 'required|string|max:2000', 'verifiedSeconds' => 'nullable|integer|min:0|max:86400'])->validate();
        DB::transaction(function () use ($actor, $reservationId, $decision, $evidence, $verifiedSeconds) {
            app(Engine::class)->mutex();
            $r = DB::table('wallet_reservations')->where('id', $reservationId)->lockForUpdate()->firstOrFail();
            $a = CallAttempt::findOrFail($r->call_attempt_id);
            if ($r->status !== 'review' || ! $a->ended_at || $a->state === 'unknown') {
                $this->fail('First resolve uncertain live-call evidence through telephony reconciliation.');
            }
            if ($decision === 'charge' && (! $a->answered_at || ! $a->sent_at)) {
                $this->fail('An answered, sent call is required for a debit.');
            }
            $wallet = Wallet::whereKey($r->wallet_id)->lockForUpdate()->firstOrFail();
            if (! $this->reconciliation($wallet)['matches']) {
                $this->fail('Wallet totals do not reconcile. Investigate before resolution.');
            }
            $policy = json_decode($r->policy, true, 512, JSON_THROW_ON_ERROR);
            $seconds = $verifiedSeconds ?? $r->verified_seconds ?? $a->billable_seconds;
            $charge = (int) $r->amount_minor;
            if ($decision === 'charge' && ($policy['duration_basis'] ?? 'recording') === 'answered') {
                if ($seconds === null) {
                    $this->fail('Provide verified answered seconds and an evidence reference.');
                }
                $charge = Policy::charge($policy, (int) $seconds);
            }
            $this->finish($wallet, $r, $decision === 'charge' ? $charge : 0, $decision === 'charge' ? 'settled' : 'released', $actor, $evidence);
            DB::table('wallet_reservations')->where('id', $r->id)->update(['evidence' => $evidence, 'verified_seconds' => $seconds, 'review_reason' => $decision === 'release' ? 'Released by administrator decision.' : null]);
            app(Foundation::class)->audit($actor, 'billing.reservation_reconciled', $wallet, ['reservation_id' => $r->id, 'decision' => $decision, 'evidence' => $evidence, 'verified_seconds' => $seconds]);
        });
    }

    public function reconciliation(Wallet $wallet): array
    {
        $ledger = (int) WalletLedgerEntry::where('wallet_id', $wallet->id)->sum('amount_minor');
        $held = (int) DB::table('wallet_reservations')->where('wallet_id', $wallet->id)->sum('held_minor');

        $charges = -(int) WalletLedgerEntry::where('wallet_id', $wallet->id)->where('kind', 'call_charge')->sum('amount_minor');
        $settled = (int) DB::table('wallet_reservations')->where('wallet_id', $wallet->id)->sum('charged_minor');

        return ['ledger_minor' => $ledger, 'held_minor' => $held, 'matches' => $charges === $settled && $ledger === (int) $wallet->balance_minor && $held === (int) $wallet->reserved_minor && $held >= 0 && $ledger >= $held];
    }
}
