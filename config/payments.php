<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Online calling-credit top-ups
    |--------------------------------------------------------------------------
    |
    | Top-ups are disabled by default. Before enabling them, Finance and
    | Engineering must verify the provider's API contract, settlement timing,
    | fees, refund process and webhook signature scheme in provider UAT.
    |
    | Amount limits are integer minor units. For a GHS wallet with scale 2,
    | 1000 means GHS 10.00 and 1000000 means GHS 10,000.00.
    |
    */
    'enabled' => (bool) env('PAYMENT_TOPUPS_ENABLED', false),

    'provider' => env('PAYMENT_PROVIDER', 'xcel'),

    'min_amount_minor' => (int) env('PAYMENT_MIN_AMOUNT_MINOR', 1000),
    'max_amount_minor' => (int) env('PAYMENT_MAX_AMOUNT_MINOR', 1000000),
    'pending_ttl_minutes' => (int) env('PAYMENT_PENDING_TTL_MINUTES', 30),

    'providers' => [
        'xcel' => [
            // disabled|http. Keep disabled until the Xcel API contract and UAT
            // evidence have been approved. The http driver implements the
            // documented contract in docs/module-14-payments-and-top-ups.md.
            'driver' => env('PAYMENT_XCEL_DRIVER', 'disabled'),
            'base_url' => env('PAYMENT_XCEL_BASE_URL'),
            'api_key' => env('PAYMENT_XCEL_API_KEY'),
            'initiate_path' => env('PAYMENT_XCEL_INITIATE_PATH', '/payments'),
            'http_timeout' => (int) env('PAYMENT_XCEL_HTTP_TIMEOUT', 10),
            'webhook_secret' => env('PAYMENT_XCEL_WEBHOOK_SECRET'),
            'webhook_signature_header' => env('PAYMENT_XCEL_SIGNATURE_HEADER', 'X-Payment-Signature'),
            'webhook_timestamp_header' => env('PAYMENT_XCEL_TIMESTAMP_HEADER', 'X-Payment-Timestamp'),
            'webhook_tolerance' => (int) env('PAYMENT_XCEL_WEBHOOK_TOLERANCE', 300),
        ],
    ],
];
