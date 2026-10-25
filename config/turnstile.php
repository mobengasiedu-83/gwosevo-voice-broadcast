<?php

return [
    'enabled' => env('TURNSTILE_ENABLED', false),
    'site_key' => env('TURNSTILE_SITE_KEY'),
    'secret_key' => env('TURNSTILE_SECRET_KEY'),
    'hostnames' => array_values(array_filter(array_map('trim', explode(',', (string) (env('TURNSTILE_HOSTNAMES') ?: (parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost')))))),
    'timeout' => (int) env('TURNSTILE_TIMEOUT', 8),
];
