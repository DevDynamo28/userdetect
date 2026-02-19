<?php

return [
    'paths' => ['api/*', 'sdk/*'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => env('CORS_ALLOWED_ORIGINS', '*') === '*'
        ? ['*']
        : array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', '')))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'X-API-Key', 'Accept', 'Origin'],

    'exposed_headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],

    'max_age' => 86400,

    'supports_credentials' => false,
];
