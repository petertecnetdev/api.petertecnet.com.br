<?php

return [
    'protocol' => [
        'version' => env('IDENTITY_PROTOCOL_VERSION', '3.0'),
        'sdk_min_version' => env('IDENTITY_SDK_MIN_VERSION', '3.0.0'),
    ],

    'access_token' => [
        'ttl_minutes' => (int) env('IDENTITY_ACCESS_TOKEN_TTL_MINUTES', 30),
    ],

    'session' => [
        'ttl_minutes' => (int) env('IDENTITY_SESSION_TTL_MINUTES', 43200),
        'touch_interval_minutes' => (int) env('IDENTITY_SESSION_TOUCH_INTERVAL_MINUTES', 5),
    ],

    'global_sso' => [
        'cache_store' => env('IDENTITY_CACHE_STORE', app()->environment('testing') ? 'array' : 'redis'),
        'session_cookie' => 'peter_ecosystem_session',
        'refresh_cookie' => 'peter_ecosystem_refresh',
        'session_ttl_minutes' => (int) env('IDENTITY_GLOBAL_SESSION_TTL_MINUTES', 10080),
        'refresh_ttl_minutes' => (int) env('IDENTITY_GLOBAL_REFRESH_TTL_MINUTES', 43200),
        'refresh_grace_seconds' => (int) env('IDENTITY_GLOBAL_REFRESH_GRACE_SECONDS', 30),
        'csrf_ttl_seconds' => (int) env('IDENTITY_CSRF_TTL_SECONDS', 300),
    ],

    'rollout' => [
        'global_sso_enabled' => filter_var(env('IDENTITY_GLOBAL_SSO_ENABLED', false), FILTER_VALIDATE_BOOL),
        'default_percentage' => (int) env('IDENTITY_GLOBAL_SSO_PERCENTAGE', 0),
    ],

    'step_up' => [
        'ttl_minutes' => (int) env('IDENTITY_STEP_UP_TTL_MINUTES', 10),
        'actions' => [
            'revoke_all_sessions',
            'logout_everywhere',
            'disable_2fa',
            'manage_passkeys',
            'trust_device',
            'revoke_device',
            'identity_rollout',
            'change_password',
            'financial_operation',
            'admin_user_manage',
            'admin_access_change',
            'admin_profile_change',
            'ecosystem_settings',
        ],
    ],

    'legacy_tokens' => [
        // observe -> emit Deprecation/Sunset and metrics; enforce -> reject JWTs without sid.
        'mode' => env('IDENTITY_LEGACY_TOKEN_MODE', 'observe'),
        'sunset_at' => env('IDENTITY_LEGACY_TOKEN_SUNSET_AT', '2026-12-01T00:00:00-03:00'),
        'reject_after_sunset' => filter_var(env('IDENTITY_LEGACY_TOKEN_REJECT_AFTER_SUNSET', false), FILTER_VALIDATE_BOOL),
    ],

    'client_circuit_breaker' => [
        'failure_threshold' => (int) env('IDENTITY_CLIENT_FAILURE_THRESHOLD', 3),
        'cooldown_seconds' => (int) env('IDENTITY_CLIENT_COOLDOWN_SECONDS', 60),
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
