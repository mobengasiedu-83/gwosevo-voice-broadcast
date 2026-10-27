<?php

return [
    'media_mode' => env('VOICE_BROADCAST_MEDIA_MODE', 'pbx'),
    'media_base_url' => env('VOICE_BROADCAST_MEDIA_BASE_URL'),
    'enabled' => env('VOICE_BROADCAST_ENABLED', false),
    'global_channels' => env('VOICE_BROADCAST_GLOBAL_CHANNELS', 0),
    'global_cps' => env('VOICE_BROADCAST_GLOBAL_CPS', 0),
    'context' => 'gwosevo-broadcast',
    'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
    'max_audio_seconds' => 600,
    'max_audio_bytes' => 10000000,
    'gateway_stale_seconds' => 15,
    'event_retention_days' => 30,
];
