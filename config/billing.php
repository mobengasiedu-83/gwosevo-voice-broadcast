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

    // Labels actual answer/hangup timing retained for CDRs. Charges use the
    // full recording length frozen in the approved campaign version.
    'internal_timing_source' => (string) env('BILLING_INTERNAL_TIMING_SOURCE', 'engine'),

    // Actor attributed to money movements the gateway triggers without a human
    // present (answer-only rating at hangup). Falls back to the cleanup
    // administrator so one reviewed billing identity covers both.
    'system_actor_id' => (int) env('BILLING_SYSTEM_ACTOR_ID', env('BILLING_HOLD_CLEANUP_ACTOR_ID', 0)),
];
