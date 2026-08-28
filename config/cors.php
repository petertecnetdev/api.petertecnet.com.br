<?php

$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Extra exact origins can be supplied as a comma-separated environment value.
    'allowed_origins' => $allowedOrigins,

    // Browser applications hosted inside the Peter Tecnet ecosystem are trusted by default.
    // Localhost is allowed only for local frontend development.
    'allowed_origins_patterns' => [
        '#^https://([a-z0-9-]+\.)*petertecnet\.com\.br$#i',
        '#^https?://(localhost|127\.0\.0\.1)(:\d{1,5})?$#i',
    ],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Requested-With',
        'X-Request-ID',
        'X-App-ID',
    ],

    'exposed_headers' => ['X-Request-ID'],

    'max_age' => 600,

    // Authentication is token based; cross-origin cookies are intentionally disabled.
    'supports_credentials' => false,
];
