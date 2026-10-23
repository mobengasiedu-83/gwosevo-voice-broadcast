<?php

return [
    'enabled' => env('VOICE_BROADCAST_ENABLED', false),
    'global_channels' => env('VOICE_BROADCAST_GLOBAL_CHANNELS', 0),
    'global_cps' => env('VOICE_BROADCAST_GLOBAL_CPS', 0),
    'context' => 'gwosevo-broadcast',
    'max_audio_seconds' => 600,
    'max_audio_bytes' => 10000000,
    'gateway_stale_seconds' => 15,
    'event_retention_days' => 30,
];
