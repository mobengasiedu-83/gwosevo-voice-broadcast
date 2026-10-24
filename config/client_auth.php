<?php

return [
    'terms_version' => env('CLIENT_TERMS_VERSION', '2026-10-24'),
    'terms_url' => env('CLIENT_TERMS_URL'),
    'privacy_url' => env('CLIENT_PRIVACY_URL'),
    'registration_enabled' => env('CLIENT_REGISTRATION_ENABLED', true),
    'sms' => [
        'driver' => env('CLIENT_SMS_DRIVER', 'disabled'),
        'url' => env('TXTCONNECT_API_URL', 'https://api.txtconnect.net/dev/api/sms/send'),
        'token' => env('TXTCONNECT_API_TOKEN'),
        'sender' => env('TXTCONNECT_SENDER_ID', 'VoiceCast'),
    ],
];
