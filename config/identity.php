<?php

return [
    'cache_store' => env('IDENTITY_CACHE_STORE', env('APP_ENV') === 'testing' ? 'array' : 'redis'),
    'session_cookie' => env('IDENTITY_SESSION_COOKIE', 'peter_ecosystem_session'),
    'refresh_cookie' => env('IDENTITY_REFRESH_COOKIE', 'peter_ecosystem_refresh'),
    'session_ttl_minutes' => (int) env('IDENTITY_SESSION_TTL_MINUTES', 10080),
    'refresh_ttl_minutes' => (int) env('IDENTITY_REFRESH_TTL_MINUTES', 43200),
    'refresh_grace_seconds' => (int) env('IDENTITY_REFRESH_GRACE_SECONDS', 30),
    'csrf_ttl_seconds' => (int) env('IDENTITY_CSRF_TTL_SECONDS', 300),
    'access_token_ttl_minutes' => (int) env('IDENTITY_ACCESS_TOKEN_TTL_MINUTES', 30),
    'require_https_origin' => env('IDENTITY_REQUIRE_HTTPS_ORIGIN', true),
    'audit_retention_days' => (int) env('IDENTITY_AUDIT_RETENTION_DAYS', 180),
];
