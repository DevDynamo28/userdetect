<?php

return [
    'paths' => ['api/*', 'sdk/*'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'X-API-Key', 'Accept', 'Origin'],

    'exposed_headers' => ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'],

    'max_age' => 86400,

    'supports_credentials' => false,
];
