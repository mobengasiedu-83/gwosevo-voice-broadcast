<?php

return [
    'enabled' => env('VOICE_PAYMENTS_ENABLED', false),
    'provider' => env('VOICE_PAYMENTS_PROVIDER', 'xcel'),
    'min_cents' => 100,
    'max_cents' => 10000000,
    'xcel' => [
        'base_url' => env('XCEL_API_BASE_URL', 'https://api.xcelapp.com'),
        'merchant_id' => env('XCEL_MERCHANT_ID'),
        'public_key' => env('XCEL_PUBLIC_KEY'),
    ],
];
