<?php

namespace App\Services\Billing;

/** One server-side calculation shared by previews and immutable campaign versions. */
class CampaignPricing
{
    public function calculate(?int $audioId, ?float $seconds, ?array $tariff, int $contacts): array
    {
        $ms = $seconds !== null && is_finite($seconds) && $seconds > 0 && $seconds <= 86400
            ? (int) ceil($seconds * 1000) : null;
        $minutes = $ms === null ? null : Rating::minutes(true, $ms);
        $rate = $tariff === null ? null : (int) ($tariff['rate_minor_per_minute'] ?? 0);
        $perContact = $minutes !== null && $rate !== null && $rate > 0 ? $minutes * $rate : null;

        return [
            'policy_version' => 'recording_minute_v1',
            'audio_id' => $audioId,
            'audio_duration_seconds' => $seconds,
            'audio_duration_ms' => $ms,
            'billable_minutes' => $minutes,
            'rate_minor_per_minute' => $rate,
            'rate_per_contact_minor' => $perContact,
            'contact_count' => $contacts,
            'estimated_campaign_amount_minor' => $perContact === null ? null : $contacts * $perContact,
            'currency' => $tariff['currency'] ?? null,
            'minor_unit_scale' => $tariff['minor_unit_scale'] ?? null,
            'rate_plan_id' => $tariff['id'] ?? null,
            'rate_plan_version' => $tariff['version'] ?? null,
        ];
    }
}
