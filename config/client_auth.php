<?php

return [
    'registration_enabled' => env('CLIENT_REGISTRATION_ENABLED', true),
    'sms' => [
        'driver' => env('CLIENT_SMS_DRIVER', 'disabled'),
        'url' => env('TXTCONNECT_API_URL', 'https://api.txtconnect.net/dev/api/sms/send'),
        'token' => env('TXTCONNECT_API_TOKEN'),
        'sender' => env('TXTCONNECT_SENDER_ID', 'VoiceCast'),
    ],
];
