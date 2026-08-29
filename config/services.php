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
        'base_url' => env('EFI_API_BASE_URL', 'https://pix.api.efipay.com.br'),
        'client_id' => env('EFI_CLIENT_ID'),
        'client_secret' => env('EFI_CLIENT_SECRET'),
        'cert_path' => env('EFI_CERT_PATH'),
        'cert_password' => env('EFI_CERT_PASSWORD'),
        'pix_key' => env('EFI_PIX_KEY'),
        'webhook_hmac' => env('EFI_WEBHOOK_HMAC'),
        'timeout' => (int) env('EFI_API_TIMEOUT', 15),
    ],

    'geo_ip' => [
        'endpoint' => env('GEO_IP_ENDPOINT', 'https://ipwho.is/{ip}'),
    ],
];
