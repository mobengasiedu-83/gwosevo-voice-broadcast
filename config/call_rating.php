<?php

return [
    // Explicitly configure validated high-precision CDR/CEL adapters; none are trusted by default.
    'trusted_sources' => array_values(array_filter(array_map('trim', explode(',', (string) env('CALL_RATING_TRUSTED_SOURCES', ''))))),
];
