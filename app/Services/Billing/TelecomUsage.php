<?php

namespace App\Services\Billing;

use App\Models\CallAttempt;
use App\Models\CallTelecomUsage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Actual carrier expense; this service never moves client wallet money. */
class TelecomUsage
{
    public function snapshot(): ?array
    {
        $rate = config('telecom.rate_per_minute');
        $currency = config('telecom.currency');
        $scale = config('telecom.minor_unit_scale');
        if ($rate === null || $rate === '') {
            return null;
        }
        Validator::make(compact('rate', 'currency', 'scale'), [
            'rate' => 'required|string|max:32', 'currency' => 'required|regex:/\A[A-Z]{3}\z/',
            'scale' => 'required|integer|min:0|max:6',
        ])->validate();

        return ['rate_minor_per_minute' => app(Wallet::class)->parse((string) $rate, $scale),
            'currency' => $currency, 'minor_unit_scale' => $scale, 'policy_version' => 'carrier_ceil_minute_v1'];
    }

    public function record(CallAttempt $attempt): ?CallTelecomUsage
    {
        if (! $attempt->ended_at || ! $attempt->sent_at) {
            return null;
        }

        return DB::transaction(function () use ($attempt) {
            $a = CallAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($existing = CallTelecomUsage::where('call_attempt_id', $a->id)->first()) {
                return $existing;
            }
            $answered = (bool) $a->answered_at;
            $ms = $a->timeSpentMs();
            $minutes = Rating::minutes($answered, $ms);
            $tariff = $a->telecom_tariff_snapshot;

            return CallTelecomUsage::create(['call_attempt_id' => $a->id, 'answered' => $answered,
                'duration_ms' => $ms, 'billed_duration_ms' => $minutes * 60000, 'billed_minutes' => $minutes,
                'cost_minor' => $tariff === null ? null : $minutes * $tariff['rate_minor_per_minute'],
                'currency' => $tariff['currency'] ?? null, 'minor_unit_scale' => $tariff['minor_unit_scale'] ?? null,
                'tariff_snapshot' => $tariff, 'timing_source' => 'gateway_answer_hangup', 'created_at' => now()]);
        });
    }
}
