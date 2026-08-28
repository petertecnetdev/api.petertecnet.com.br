<?php

$officialFrontendOrigins = [
    'https://nexus.petertecnet.com.br',
    'https://rasoio.petertecnet.com.br',
    'https://plat.petertecnet.com.br',
    'https://cutinapp.petertecnet.com.br',
    'https://inkap.petertecnet.com.br',
];

$extraOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

$allowedOrigins = array_values(array_unique(array_merge(
    $officialFrontendOrigins,
    $extraOrigins
)));

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Official Peter Tecnet browser applications plus optional environment-specific origins.
    'allowed_origins' => $allowedOrigins,

    // Keep the ecosystem pattern for future Peter Tecnet apps and localhost for development.
    'allowed_origins_patterns' => [
        '#^https://([a-z0-9-]+\\.)*petertecnet\\.com\\.br$#i',
        '#^https?://(localhost|127\\.0\\.0\\.1)(:\\d{1,5})?$#i',
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
