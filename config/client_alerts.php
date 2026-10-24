<?php

return [
    'delivery_enabled' => env('VOICE_ALERT_DELIVERY_ENABLED', false),
    'max_attempts' => 3,
    'per_user_per_hour' => 10,
];
