<?php

namespace App\Services\Billing;

class Policy
{
    public static function charge(array $policy, float $seconds): int
    {
        $increment = (int) ($policy['increment_seconds'] ?? 60);
        $rounded = intdiv(max(1, (int) ceil($seconds)) + $increment - 1, $increment) * $increment;

        // Round the monetary result upward to the wallet's smallest accounting unit, using integers.
        return intdiv($rounded * (int) $policy['rate_minor_per_minute'] + 59, 60);
    }

    public static function description(array $policy): string
    {
        $basis = ($policy['duration_basis'] ?? 'recording') === 'answered' ? 'Verified answered duration' : 'Recording length';
        $increment = (int) ($policy['increment_seconds'] ?? 60);
        $failure = match ($policy['playback_failure_policy'] ?? 'charge') {
            'waive' => 'Confirmed playback failures are free.',
            'review' => 'Confirmed playback failures are held for review.',
            default => 'Answered calls are charged even if playback fails.',
        };

        return $basis.' rounded up in '.$increment.'-second increments (minimum one increment). Amounts round up to the wallet precision. '.$failure.' Unanswered calls are free.';
    }
}
