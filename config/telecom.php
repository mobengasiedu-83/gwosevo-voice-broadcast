<?php

return [
    // Carrier expense only. Never used to charge a client's wallet.
    'rate_per_minute' => env('TELECOM_RATE_PER_MINUTE'),
    'currency' => env('TELECOM_CURRENCY'),
    'minor_unit_scale' => (int) env('TELECOM_MINOR_UNIT_SCALE', 2),
];
