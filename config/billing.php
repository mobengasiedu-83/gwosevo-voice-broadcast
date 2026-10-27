<?php

return [
    // Maximum possible billable call duration used to size the credit reservation.
    // Exposure is the configured maximum, rounded up to whole billed minutes, at
    // the attempt's frozen tariff rate. Confirm this value with Finance.
    'hold_cleanup' => [
        'enabled' => (bool) env('BILLING_HOLD_CLEANUP_ENABLED', false),
        'actor_id' => (int) env('BILLING_HOLD_CLEANUP_ACTOR_ID', 0),
        'hours' => (int) env('BILLING_HOLD_CLEANUP_HOURS', 24),
        'limit' => (int) env('BILLING_HOLD_CLEANUP_LIMIT', 500),
    ],

    'max_call_seconds' => (int) env('BILLING_MAX_CALL_SECONDS', 900),
];
