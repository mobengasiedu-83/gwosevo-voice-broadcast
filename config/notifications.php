<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Module 16 account alerts
    |--------------------------------------------------------------------------
    |
    | Email and SMS are queued separately from the request so a slow provider
    | cannot delay a payment callback or campaign transition. Delivery attempts
    | are persisted in notification_deliveries and shown in the client inbox.
    |
    */
    'enabled' => env('NOTIFICATIONS_ENABLED', true),

    'channels' => [
        'email' => env('NOTIFICATIONS_EMAIL_ENABLED', true),
        'sms' => env('NOTIFICATIONS_SMS_ENABLED', true),
    ],

    'queue' => env('NOTIFICATIONS_QUEUE', 'default'),

    // Null compares the balance with the cost of holding one full call. Set a
    // positive minor-unit amount to use a fixed account alert threshold.
    'low_balance_minor' => env('NOTIFICATIONS_LOW_BALANCE_MINOR') === null || env('NOTIFICATIONS_LOW_BALANCE_MINOR') === ''
        ? null
        : max(1, (int) env('NOTIFICATIONS_LOW_BALANCE_MINOR')),

    // One low-balance alert per wallet per window. Attempts that decline after
    // the first notification remain silent until the next window.
    'low_balance_cooldown_minutes' => max(1, min(10080, (int) env('NOTIFICATIONS_LOW_BALANCE_COOLDOWN_MINUTES', 1440))),
];
