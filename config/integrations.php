<?php

return [
    'api_enabled' => env('VOICE_API_ENABLED', false),
    'webhook_delivery_enabled' => env('VOICE_WEBHOOK_DELIVERY_ENABLED', false),
    'defaults' => ['requests_per_minute' => 60, 'requests_per_day' => 10000, 'import_rows_per_day' => 10000, 'drafts_per_day' => 100, 'webhook_limit' => 3],
];
