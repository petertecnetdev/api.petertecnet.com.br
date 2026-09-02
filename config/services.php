<?php

return [
    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => ['token' => env('POSTMARK_TOKEN')],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => ['client_id' => env('GOOGLE_CLIENT_ID', env('GOOGLE_OAUTH_CLIENT_ID'))],

    'efi' => [
        'base_url' => env('EFI_API_BASE_URL'),
        'client_id' => env('EFI_CLIENT_ID'),
        'client_secret' => env('EFI_CLIENT_SECRET'),
        'cert_path' => env('EFI_CERT_PATH'),
        'timeout' => (int) env('EFI_API_TIMEOUT', 15),
        'pix_key' => env('EFI_PIX_KEY'),
        'payout_source_pix_key' => env('EFI_PAYOUT_SOURCE_PIX_KEY', env('EFI_PIX_KEY')),
    ],

    'asaas' => [
        'base_url' => env('ASAAS_API_BASE_URL', 'https://api.asaas.com/v3'),
        'api_key' => env('ASAAS_API_KEY'),
        'webhook_token' => env('ASAAS_WEBHOOK_TOKEN'),
        'withdrawal_auth_token' => env('ASAAS_WITHDRAWAL_AUTH_TOKEN'),
        'timeout' => (int) env('ASAAS_API_TIMEOUT', 20),
    ],

    'identity' => [
        'aws_region' => env('AWS_REKOGNITION_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
        'aws_access_key_id' => env('AWS_ACCESS_KEY_ID'),
        'aws_secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
        'liveness_role_arn' => env('AWS_REKOGNITION_LIVENESS_ROLE_ARN'),
        'liveness_threshold' => (float) env('IDENTITY_LIVENESS_THRESHOLD', 90),
        'face_similarity_threshold' => (float) env('IDENTITY_FACE_SIMILARITY_THRESHOLD', 92),
        'reverify_hours' => (int) env('IDENTITY_REVERIFY_HOURS', 24),
    ],

    'finance' => [
        'payout_provider' => env('FINANCE_PAYOUT_PROVIDER', 'asaas'),
        'payout_hold_hours' => (int) env('FINANCE_PAYOUT_HOLD_HOURS', 24),
        'payout_reserve_percent' => (float) env('FINANCE_PAYOUT_RESERVE_PERCENT', 10),
        'payout_destination_cooling_hours' => (int) env('FINANCE_PIX_CHANGE_COOLING_HOURS', 24),
        'step_up_amount' => (float) env('FINANCE_STEP_UP_AMOUNT', 5000),
    ],

    'mercadopago' => [
        'client_id' => env('MERCADOPAGO_CLIENT_ID'),
        'client_secret' => env('MERCADOPAGO_CLIENT_SECRET'),
        'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'redirect_uri' => env('MERCADOPAGO_REDIRECT_URI', rtrim(env('APP_URL', ''), '/') . '/api/payments/mercadopago/oauth/callback'),
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
    ],

    'geo_ip' => ['endpoint' => env('GEO_IP_ENDPOINT', 'https://ipwho.is/{ip}')],
];
