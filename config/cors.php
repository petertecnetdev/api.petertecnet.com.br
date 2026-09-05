<?php

$officialFrontendOrigins = [
    'https://petertecnet.com.br',
    'https://www.petertecnet.com.br',
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
    'paths' => ['api/*', 'broadcasting/auth'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [
        '#^https://([a-z0-9-]+\\.)*petertecnet\\.com\\.br$#i',
        '#^https?://(localhost|127\\.0\\.0\\.1)(:\\d{1,5})?$#i',
    ],
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'Referer',
        'X-Requested-With',
        'X-Request-ID',
        'X-App-ID',
        'X-Application-Id',
        'X-App-Slug',
        'X-Application-Slug',
        'X-Peter-App',
        'X-Peter-Context-Role',
        'X-Peter-Ecosystem-SDK',
        'X-Peter-Telemetry',
        'X-Telemetry-Schema',
        'X-Frontend-Page',
        'X-Correlation-ID',
        'X-Parent-Interaction-ID',
    ],
    'exposed_headers' => ['X-Request-ID'],
    'max_age' => 600,
    'supports_credentials' => false,
];
