<?php

return [
    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', env('GOOGLE_OAUTH_CLIENT_ID')),
    ],

    'efi' => [
        'base_url' => env('EFI_API_BASE_URL'),
        'client_id' => env('EFI_CLIENT_ID'),
        'client_secret' => env('EFI_CLIENT_SECRET'),
        'cert_path' => env('EFI_CERT_PATH'),
        'timeout' => (int) env('EFI_API_TIMEOUT', 15),
        'pix_key' => env('EFI_PIX_KEY'),
        'payout_source_pix_key' => env('EFI_PAYOUT_SOURCE_PIX_KEY', env('EFI_PIX_KEY')),
    ],

    'mercadopago' => [
        'client_id' => env('MERCADOPAGO_CLIENT_ID'),
        'client_secret' => env('MERCADOPAGO_CLIENT_SECRET'),
        'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'redirect_uri' => env('MERCADOPAGO_REDIRECT_URI', rtrim(env('APP_URL', ''), '/') . '/api/cutinapp/payments/mercadopago/oauth/callback'),
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
    ],

    'cutinapp' => [
        'platform_fee_percent' => (float) env('CUTINAPP_PLATFORM_FEE_PERCENT', 8),
        'frontend_url' => env('CUTINAPP_FRONTEND_URL', 'https://cutinapp.petertecnet.com.br'),
        // Safe production default: paid sales require the producer's connected
        // Mercado Pago account. Central collection is opt-in until automatic
        // payout settlement is implemented and operationally approved.
        'allow_platform_collection' => filter_var(env('CUTINAPP_ALLOW_PLATFORM_COLLECTION', false), FILTER_VALIDATE_BOOL),
        'manual_payout_requests_enabled' => filter_var(env('CUTINAPP_ENABLE_MANUAL_PAYOUT_REQUESTS', false), FILTER_VALIDATE_BOOL),
        // Mercado Pago documents a 30-minute minimum for configurable Pix
        // expiration. Keep the local inventory reservation aligned with it.
        'order_expiration_minutes' => max(30, min((int) env('CUTINAPP_ORDER_EXPIRATION_MINUTES', 30), 60)),
    ],

    'geo_ip' => [
        'endpoint' => env('GEO_IP_ENDPOINT', 'https://ipwho.is/{ip}'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_API_BASE_URL', 'https://api.openai.com/v1'),
        'catalog_vision_model' => env('OPENAI_CATALOG_VISION_MODEL', 'gpt-5-mini'),
        'timeout' => (int) env('OPENAI_API_TIMEOUT', 90),
    ],
];
