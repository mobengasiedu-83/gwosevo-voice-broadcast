<?php

namespace App\Services\Billing;

use App\Models\AuditLog;
use App\Models\BillingException;
use App\Models\CallAttempt;
use App\Models\CallRating;
use App\Models\CallTimingEvidence;
use App\Models\CampaignRecipient;
use App\Models\User;
use App\Services\Broadcast\Engine;
use App\Services\Broadcast\Reconciliation;
use App\Services\Clients\Tenancy;
use App\Services\Foundation;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class Rating
{
    public static function minutes(bool $answered, int $durationMs): int
    {
        if ($durationMs < 0 || $durationMs > 86400000) {
            throw new \InvalidArgumentException('Duration must be an integer from 0 through 86400000 milliseconds.');
        }

        return $answered ? max(1, intdiv($durationMs + 59999, 60000)) : 0;
    }

    public function issue(CallAttempt $attempt, string $issue, ?string $evidence = null, ?User $actor = null): void
    {
        $exception = BillingException::firstOrCreate(['call_attempt_id' => $attempt->id, 'issue' => $issue], ['evidence' => $evidence, 'reported_by' => $actor?->id, 'created_at' => now()]);
        $opened = $exception->wasRecentlyCreated;
        if ($exception->resolved_at) {
            $exception->update(['resolved_at' => null, 'resolved_by' => null]);
            $opened = true;
        }
        if ($opened && $actor) {
            app(Foundation::class)->audit($actor, 'billing.exception_opened', $attempt, ['issue' => $issue, 'evidence' => $evidence]);
        }
    }

    private function close(CallAttempt $attempt, array $issues, ?User $actor): void
    {
        BillingException::where('call_attempt_id', $attempt->id)->whereIn('issue', $issues)->whereNull('resolved_at')->update(['resolved_at' => now(), 'resolved_by' => $actor?->id]);
    }

    // Only validated provider timestamps, never AMI receipt time or integer billsec, can establish rating duration.
    private function timestamp(?string $value, bool $precise): ?DateTimeImmutable
    {
        if (! $value || ! preg_match('/\A(\d{4})-(\d{2})-(\d{2})T([0-1]\d|2[0-3]):([0-5]\d):([0-5]\d)(?:\.(\d{1,6}))?(Z|[+-](?:0\d|1[0-4]):[0-5]\d)\z/', $value, $m)) {
            return null;
        }
        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || ($precise && strlen($m[7] ?? '') < 3)) {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function micros(DateTimeImmutable $time): int
    {
        return (int) $time->format('U') * 1000000 + (int) $time->format('u');
    }

    public function import(User $actor, array $record, string $source, string $evidence): string
    {
        Gate::forUser($actor)->authorize('telephony.manage');
        Validator::make(compact('source', 'evidence') + $record, [
            'source' => 'required|string|max:80', 'evidence' => 'required|string|max:1000',
            'attempt_uuid' => 'required|uuid', 'uniqueid' => 'required|string|max:255',
            'linkedid' => 'nullable|string|max:255', 'disposition' => 'required|in:ANSWERED,BUSY,NO ANSWER,FAILED',
            'answered_at' => 'nullable|string|max:64', 'ended_at' => 'required|string|max:64',
            'duration_ms' => 'nullable|integer|min:0|max:86400000',
        ])->validate();
        abort_unless(in_array($source, config('call_rating.trusted_sources', []), true), 422, 'Configure a validated high-precision timing source before importing ratings.');

        return DB::transaction(function () use ($actor, $record, $source, $evidence) {
            app(Engine::class)->mutex();
            $a = CallAttempt::where('uuid', $record['attempt_uuid'])->lockForUpdate()->firstOrFail();
            if ($a->uniqueid !== $record['uniqueid'] || (! empty($record['linkedid']) && $a->linkedid !== $record['linkedid'])) {
                $this->issue($a, 'correlation_conflict', $evidence, $actor);

                return 'held';
            }
            $answered = $record['disposition'] === 'ANSWERED';
            $end = $this->timestamp($record['ended_at'], $answered);
            $answer = $this->timestamp($record['answered_at'] ?? null, $answered);
            if (! $end || ($answered && ! $answer)) {
                $this->issue($a, 'timing_precision', $evidence, $actor);

                return 'held';
            }
            if (! $answered && ($this->observedAnswer($a) || ! empty($record['answered_at']) || ! empty($record['duration_ms']))) {
                $this->issue($a, 'answer_conflict', $evidence, $actor);

                return 'held';
            }
            if (! $a->sent_at) {
                $this->issue($a, 'invalid_timing', $evidence, $actor);

                return 'held';
            }
            $endUs = $this->micros($end);
            $answerUs = $answer ? $this->micros($answer) : null;
            $startUs = $this->micros(new DateTimeImmutable(($a->sent_at ?? $a->created_at)->toIso8601String()));
            $nowUs = $this->micros(new DateTimeImmutable(now()->format('Y-m-d\TH:i:s.uP')));
            $elapsed = $answered ? $endUs - $answerUs : 0;
            if (! $a->sent_at || $endUs > $nowUs || $endUs < $startUs || ($answered && ($answerUs < $startUs || $elapsed < 0 || $elapsed > 86400000000))) {
                $this->issue($a, 'invalid_timing', $evidence, $actor);

                return 'held';
            }
            $ms = $answered ? intdiv($elapsed + 999, 1000) : 0;
            if (isset($record['duration_ms']) && (int) $record['duration_ms'] !== $ms) {
                $this->issue($a, 'duration_conflict', $evidence, $actor);

                return 'held';
            }
            $payload = ['disposition' => $record['disposition'], 'source' => $source, 'uniqueid' => $record['uniqueid'], 'answered' => $answered, 'answer_timestamp' => $answer?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'), 'end_timestamp' => $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'), 'duration_ms' => $ms];
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $existing = CallTimingEvidence::where('call_attempt_id', $a->id)->first();
            if ($existing && ! hash_equals($existing->payload_hash, $hash)) {
                $this->issue($a, 'timing_conflict', $evidence, $actor);

                return 'held';
            }
            if (! $a->ended_at) {
                // Reuse the operational safety gate: only uncertain calls absent from a fresh full channel snapshot can end here.
                app(Reconciliation::class)->resolve($actor, array_merge($record, ['billable_seconds' => intdiv($ms, 1000)]), $evidence);
                $a->refresh();
            }
            $timing = $existing ?? CallTimingEvidence::create([
                'call_attempt_id' => $a->id, 'source' => $source, 'reference' => $evidence, 'payload_hash' => $hash,
                'answered' => $answered, 'disposition' => $record['disposition'], 'answer_timestamp' => $payload['answer_timestamp'], 'end_timestamp' => $payload['end_timestamp'],
                'duration_ms' => $ms, 'billable_minutes' => self::minutes($answered, $ms), 'verified_by' => $actor->id, 'created_at' => now(),
            ]);
            if (! $existing) {
                $updates = ['ended_at' => CarbonImmutable::instance($end), 'billable_seconds' => intdiv($ms, 1000)];
                if ($answered) {
                    $updates['answered_at'] = CarbonImmutable::instance($answer);
                    if (! $this->observedAnswer($a) && $a->outcome !== 'playback_failed') {
                        $updates['outcome'] = 'answered_unknown';
                    }
                } else {
                    $updates['outcome'] = match ($record['disposition']) {
                        'BUSY' => 'busy', 'NO ANSWER' => 'no_answer', default => 'failed'
                    };
                }
                $a->update($updates);
                if ($answered && CampaignRecipient::whereKey($a->campaign_recipient_id)->where('status', 'pending')->update(['status' => 'terminal'])) {
                    $recipient = CampaignRecipient::findOrFail($a->campaign_recipient_id);
                    app(Engine::class)->complete($recipient->campaign_id);
                    app(Foundation::class)->audit($actor, 'billing.answered_retry_stopped', $a, ['source' => $source]);
                }
                app(Foundation::class)->audit($actor, 'billing.timing_verified', $a, ['source' => $source, 'evidence' => $evidence, 'duration_ms' => $ms]);
            }
            $this->close($a, ['timing_required', 'timing_precision', 'invalid_timing'], $actor);

            return $this->finalize($a, $timing, $actor);
        });
    }

    private function finalize(CallAttempt $a, CallTimingEvidence $timing, User $actor): string
    {
        if (BillingException::where('call_attempt_id', $a->id)->whereNull('resolved_at')->whereIn('issue', ['correlation_conflict', 'answer_conflict', 'duration_conflict', 'timing_conflict'])->exists()) {
            return 'held';
        }
        if (CallRating::where('call_attempt_id', $a->id)->exists()) {
            // Replays never re-charge, but they still heal a missing settlement.
            app(Wallet::class)->settle(CallRating::where('call_attempt_id', $a->id)->firstOrFail());

            return 'rated';
        }
        $tariff = $a->tariff_snapshot;
        if ($timing->answered && ! $tariff) {
            $this->issue($a, 'missing_tariff', $timing->reference, $actor);

            return 'held';
        }
        if ($tariff && (Validator::make($tariff, ['rate_minor_per_minute' => 'required|integer|min:0|max:1000000000', 'currency' => 'required|regex:/\A[A-Z]{3}\z/', 'minor_unit_scale' => 'required|integer|min:0|max:6', 'playback_policy' => 'required|in:charge_answered,hold_on_failure', 'policy_version' => 'required|in:ceil_minute_v1'])->fails())) {
            $this->issue($a, 'invalid_tariff', $timing->reference, $actor);

            return 'held';
        }
        if ($timing->answered && ($tariff['playback_policy'] ?? '') !== 'charge_answered' && ! ($a->playback_completed_at || $a->ivr_completed_at || $a->transfer_answered_at)) {
            $this->issue($a, 'playback_policy', $timing->reference, $actor);

            return 'held';
        }
        $this->close($a, ['playback_policy'], $actor);
        $rating = CallRating::create([
            'call_attempt_id' => $a->id, 'call_timing_evidence_id' => $timing->id, 'tariff_snapshot' => $tariff,
            'policy_version' => 'ceil_minute_v1', 'duration_ms' => $timing->duration_ms, 'billable_minutes' => $timing->billable_minutes,
            'amount_minor' => $timing->answered ? $timing->billable_minutes * (int) $tariff['rate_minor_per_minute'] : 0,
            'currency' => $tariff['currency'] ?? null, 'minor_unit_scale' => $tariff['minor_unit_scale'] ?? null, 'created_at' => now(),
        ]);
        app(Wallet::class)->settle($rating);
        app(Foundation::class)->audit($actor, 'billing.call_rated', $a, ['minutes' => $timing->billable_minutes, 'policy' => 'ceil_minute_v1']);

        return 'rated';
    }

    private function observedAnswer(CallAttempt $a): bool
    {
        return (bool) ($a->answered_at || $a->playback_started_at || $a->playback_completed_at || $a->ivr_completed_at || $a->opted_out_at || $a->transfer_answered_at || in_array($a->outcome, ['completed', 'answered_unknown', 'early_hangup', 'opt_out', 'ivr_completed', 'transfer_success'], true));
    }

    public function unsent(CallAttempt $a): void
    {
        if ($a->sent_at || ! $a->ended_at || $a->channel || $this->observedAnswer($a) || ! in_array($a->outcome, ['cancelled_before_send', 'authorization_failed', 'suppressed', 'contact_changed', 'no_consent'], true)) {
            $this->issue($a, 'timing_required');

            return;
        }
        if (CallTimingEvidence::where('call_attempt_id', $a->id)->exists()) {
            return;
        }
        $timing = CallTimingEvidence::create([
            'call_attempt_id' => $a->id, 'source' => 'application:not_sent', 'reference' => 'Durable unsent state: '.$a->outcome,
            'payload_hash' => hash('sha256', $a->uuid.':not_sent:'.$a->outcome), 'answered' => false, 'disposition' => 'NOT SENT',
            'answer_timestamp' => null, 'end_timestamp' => now()->format('Y-m-d\TH:i:s.u\Z'), 'duration_ms' => 0, 'billable_minutes' => 0,
            'verified_by' => null, 'created_at' => now(),
        ]);
        CallRating::create(['call_attempt_id' => $a->id, 'call_timing_evidence_id' => $timing->id, 'tariff_snapshot' => $a->tariff_snapshot, 'policy_version' => 'ceil_minute_v1', 'duration_ms' => 0, 'billable_minutes' => 0, 'amount_minor' => 0, 'currency' => $a->tariff_snapshot['currency'] ?? null, 'minor_unit_scale' => $a->tariff_snapshot['minor_unit_scale'] ?? null, 'created_at' => now()]);
        // A zero rating settles (releases) any hold and never posts a usage charge.
        app(Wallet::class)->settle(CallRating::where('call_attempt_id', $a->id)->firstOrFail());
        AuditLog::create(['client_id' => app(Tenancy::class)->ownerOf($a), 'actor_id' => null, 'action' => 'billing.unsent_zero_rated', 'entity_type' => $a->getMorphClass(), 'entity_id' => $a->id, 'details' => ['reason' => $a->outcome], 'created_at' => now()]);
    }

    public function observe(CallAttempt $a): void
    {
        if (! $a->ended_at || ! $this->observedAnswer($a)) {
            return;
        }
        $timing = CallTimingEvidence::where('call_attempt_id', $a->id)->first();
        if ($timing && ! $timing->answered && $this->observedAnswer($a)) {
            $this->issue($a, 'answer_conflict', 'Late AMI answer/playback evidence');
        }
    }

    public static function amount(CallRating $rating): string
    {
        if ($rating->minor_unit_scale === null) {
            return '0';
        }
        $scale = (int) $rating->minor_unit_scale;
        $factor = 10 ** $scale;

        return intdiv($rating->amount_minor, $factor).($scale ? '.'.str_pad((string) ($rating->amount_minor % $factor), $scale, '0', STR_PAD_LEFT) : '');
    }
}
