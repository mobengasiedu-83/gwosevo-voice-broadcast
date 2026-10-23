<?php

return [
    // Unknown limits intentionally remain unset. Never assume 100 channels are available.
    'vitalpbx_version' => env('VITALPBX_VERSION'),
    'asterisk_version' => env('ASTERISK_VERSION'),
    'trunk' => env('VITALPBX_TRUNK_NAME'),
    'number_format' => env('VITALPBX_NUMBER_FORMAT', 'international'),
    'caller_id' => env('VITALPBX_CALLER_ID'),
    'dial_pattern' => env('VITALPBX_DIAL_PATTERN'),
    'custom_context' => env('VITALPBX_BROADCAST_CONTEXT'),
    'carrier_channels' => env('VITALPBX_CARRIER_CHANNELS'),
    'carrier_cps' => env('VITALPBX_CARRIER_CPS'),
    'reserved_channels' => env('VITALPBX_RESERVED_CHANNELS'),
    'safe_pbx_channels' => env('VITALPBX_SAFE_PBX_CHANNELS'),
    'broadcast_cap' => env('VITALPBX_BROADCAST_CAP'),
    'evidence_reference' => env('VITALPBX_DISCOVERY_EVIDENCE'),
];
