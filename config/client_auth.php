<?php

return [
    'registration_enabled' => env('CLIENT_REGISTRATION_ENABLED', true),
    'auto_activate' => env('CLIENT_REGISTRATION_AUTO_ACTIVATE', true),
    'otp_minutes' => (int) env('CLIENT_OTP_MINUTES', 10),
    'retention_days' => (int) env('CLIENT_CHALLENGE_RETENTION_DAYS', 7),
    'otp_attempts' => (int) env('CLIENT_OTP_MAX_ATTEMPTS', 5),
    'terms_version' => env('CLIENT_TERMS_VERSION', '2026-10-25'),
    'sms_driver' => env('CLIENT_SMS_DRIVER', 'disabled'),
    'sms_url' => env('TXTCONNECT_API_URL', 'https://api.txtconnect.net/dev/api/sms/send'),
    'sms_token' => env('TXTCONNECT_API_TOKEN'),
    'sms_unicode' => env('TXTCONNECT_UNICODE', '0'),
    'sms_sender' => env('TXTCONNECT_SENDER_ID', 'GWO SEVO'),
];
