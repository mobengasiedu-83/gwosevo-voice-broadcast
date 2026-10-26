<?php

return [
    // Maximum possible billable call duration used to size the credit reservation.
    // Exposure is the configured maximum, rounded up to whole billed minutes, at
    // the attempt's frozen tariff rate. Confirm this value with Finance.
    'max_call_seconds' => (int) env('BILLING_MAX_CALL_SECONDS', 900),
];
