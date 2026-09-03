<?php

return [
    'access_token_ttl_minutes' => (int) env('IDENTITY_ACCESS_TOKEN_TTL_MINUTES', 30),

    'session' => [
        'ttl_minutes' => (int) env('IDENTITY_SESSION_TTL_MINUTES', 43200),
        'touch_interval_minutes' => (int) env('IDENTITY_SESSION_TOUCH_INTERVAL_MINUTES', 5),
    ],

    'global_sso' => [
        'cache_store' => env('IDENTITY_CACHE_STORE', env('APP_ENV') === 'testing' ? 'array' : 'redis'),
        'session_cookie' => env('IDENTITY_SESSION_COOKIE', 'peter_ecosystem_session'),
        'refresh_cookie' => env('IDENTITY_REFRESH_COOKIE', 'peter_ecosystem_refresh'),
        'session_ttl_minutes' => (int) env('IDENTITY_GLOBAL_SESSION_TTL_MINUTES', 10080),
        'refresh_ttl_minutes' => (int) env('IDENTITY_REFRESH_TTL_MINUTES', 43200),
        'refresh_grace_seconds' => (int) env('IDENTITY_REFRESH_GRACE_SECONDS', 30),
        'csrf_ttl_seconds' => (int) env('IDENTITY_CSRF_TTL_SECONDS', 300),
        'require_https_origin' => filter_var(env('IDENTITY_REQUIRE_HTTPS_ORIGIN', true), FILTER_VALIDATE_BOOL),
    ],

    'magic_link' => [
        'ttl_minutes' => (int) env('IDENTITY_MAGIC_LINK_TTL_MINUTES', 10),
    ],

    'two_factor' => [
        'issuer' => env('IDENTITY_TOTP_ISSUER', 'Peter Tecnet'),
        'period' => 30,
        'digits' => 6,
        'window' => 1,
        'challenge_ttl_minutes' => (int) env('IDENTITY_2FA_CHALLENGE_TTL_MINUTES', 5),
        'recovery_codes' => (int) env('IDENTITY_2FA_RECOVERY_CODES', 8),
    ],

    'password' => [
        'min_length' => (int) env('IDENTITY_PASSWORD_MIN_LENGTH', 8),
        'require_uppercase' => filter_var(env('IDENTITY_PASSWORD_REQUIRE_UPPERCASE', true), FILTER_VALIDATE_BOOL),
        'require_lowercase' => filter_var(env('IDENTITY_PASSWORD_REQUIRE_LOWERCASE', true), FILTER_VALIDATE_BOOL),
        'require_number' => filter_var(env('IDENTITY_PASSWORD_REQUIRE_NUMBER', true), FILTER_VALIDATE_BOOL),
        'require_symbol' => filter_var(env('IDENTITY_PASSWORD_REQUIRE_SYMBOL', true), FILTER_VALIDATE_BOOL),
        'compromised_check' => filter_var(env('IDENTITY_PASSWORD_COMPROMISED_CHECK', true), FILTER_VALIDATE_BOOL),
        'compromised_threshold' => (int) env('IDENTITY_PASSWORD_COMPROMISED_THRESHOLD', 1),
    ],

    'rate_limits' => [
        'login_attempts' => (int) env('IDENTITY_LOGIN_ATTEMPTS', 8),
        'login_decay_seconds' => (int) env('IDENTITY_LOGIN_DECAY_SECONDS', 60),
        'magic_link_attempts' => (int) env('IDENTITY_MAGIC_LINK_ATTEMPTS', 5),
        'magic_link_decay_seconds' => (int) env('IDENTITY_MAGIC_LINK_DECAY_SECONDS', 300),
    ],

    'passkeys' => [
        'rp_name' => env('IDENTITY_PASSKEY_RP_NAME', 'Peter Tecnet'),
        'rp_id' => env('IDENTITY_PASSKEY_RP_ID', 'petertecnet.com.br'),
        'origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('IDENTITY_PASSKEY_ORIGINS', 'https://petertecnet.com.br,https://nexus.petertecnet.com.br,https://rasoio.petertecnet.com.br,https://plat.petertecnet.com.br,https://inkap.petertecnet.com.br,https://cutinapp.petertecnet.com.br,https://payflow.petertecnet.com.br,https://laora.petertecnet.com.br'))
        ))),
        'challenge_ttl_minutes' => (int) env('IDENTITY_PASSKEY_CHALLENGE_TTL_MINUTES', 5),
    ],
];
