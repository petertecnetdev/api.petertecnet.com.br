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

    'instagram' => [
        'client_id' => env('INSTAGRAM_CLIENT_ID', env('INSTAGRAM_APP_ID')),
        'client_secret' => env('INSTAGRAM_CLIENT_SECRET', env('INSTAGRAM_APP_SECRET')),
        'redirect_uri' => env('INSTAGRAM_REDIRECT_URI'),
        'scopes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('INSTAGRAM_SCOPES', 'instagram_business_basic'))
        ))),
        'graph_url' => env('INSTAGRAM_GRAPH_URL', 'https://graph.instagram.com'),
    ],

    'search_console' => [
        'site_url' => env('GOOGLE_SEARCH_CONSOLE_SITE_URL'),
        'access_token' => env('GOOGLE_SEARCH_CONSOLE_ACCESS_TOKEN'),
        'service_account_json' => env('GOOGLE_SEARCH_CONSOLE_SERVICE_ACCOUNT_JSON'),
    ],

    'bing_webmaster' => [
        'site_url' => env('BING_WEBMASTER_SITE_URL'),
        'stats_url' => env('BING_WEBMASTER_STATS_URL'),
        'access_token' => env('BING_WEBMASTER_ACCESS_TOKEN'),
        'api_key' => env('BING_WEBMASTER_API_KEY'),
        'client_id' => env('BING_WEBMASTER_CLIENT_ID'),
        'client_secret' => env('BING_WEBMASTER_CLIENT_SECRET'),
        'refresh_token' => env('BING_WEBMASTER_REFRESH_TOKEN'),
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

    'asaas' => [
        'base_url' => env('ASAAS_API_BASE_URL', 'https://api.asaas.com/v3'),
        'api_key' => env('ASAAS_API_KEY'),
        'webhook_token' => env('ASAAS_WEBHOOK_TOKEN'),
        'withdrawal_auth_token' => env('ASAAS_WITHDRAWAL_AUTH_TOKEN'),
        'timeout' => (int) env('ASAAS_API_TIMEOUT', 20),
        'boleto_due_days' => (int) env('ASAAS_BOLETO_DUE_DAYS', 1),
        'boleto_days_after_due_date' => (int) env('ASAAS_BOLETO_DAYS_AFTER_DUE_DATE', 1),
    ],

    'identity' => [
        'aws_region' => env('AWS_REKOGNITION_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
        'aws_access_key_id' => env('AWS_ACCESS_KEY_ID'),
        'aws_secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
        'liveness_role_arn' => env('AWS_REKOGNITION_LIVENESS_ROLE_ARN'),
        'liveness_required' => filter_var(env('IDENTITY_LIVENESS_REQUIRED', false), FILTER_VALIDATE_BOOL),
        'liveness_threshold' => (float) env('IDENTITY_LIVENESS_THRESHOLD', 90),
        'face_similarity_threshold' => (float) env('IDENTITY_FACE_SIMILARITY_THRESHOLD', 92),
        'reverify_hours' => (int) env('IDENTITY_REVERIFY_HOURS', 24),
    ],

    'finance' => [
        'payment_primary_provider' => env('PAYMENT_PRIMARY_PROVIDER', 'mercadopago'),
        'payment_fallback_provider' => env('PAYMENT_FALLBACK_PROVIDER', ''),
        'payout_provider' => env('FINANCE_PAYOUT_PROVIDER', 'manual_pix'),
        'payout_hold_hours' => (int) env('FINANCE_PAYOUT_HOLD_HOURS', 24),
        'payout_reserve_percent' => (float) env('FINANCE_PAYOUT_RESERVE_PERCENT', 10),
        'payout_destination_cooling_hours' => (int) env('FINANCE_PIX_CHANGE_COOLING_HOURS', 24),
        'step_up_amount' => (float) env('FINANCE_STEP_UP_AMOUNT', 5000),
        'allow_platform_collection' => filter_var(env('FINANCE_ALLOW_PLATFORM_COLLECTION', false), FILTER_VALIDATE_BOOL),
    ],

    'mercadopago' => [
        'client_id' => env('MERCADOPAGO_CLIENT_ID'),
        'client_secret' => env('MERCADOPAGO_CLIENT_SECRET'),
        'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'redirect_uri' => env('MERCADOPAGO_REDIRECT_URI', rtrim(env('APP_URL', ''), '/') . '/api/payments/mercadopago/oauth/callback'),
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
        'buyer_fee' => [
            // Taxas contratuais da conta Mercado Pago. O checkout faz gross-up para
            // preservar o valor líquido definido pelo produtor.
            'pix_percent' => (float) env('MERCADOPAGO_PIX_FEE_PERCENT', 0),
            'pix_fixed' => (float) env('MERCADOPAGO_PIX_FEE_FIXED', 0),
            'boleto_percent' => (float) env('MERCADOPAGO_BOLETO_FEE_PERCENT', 0),
            'boleto_fixed' => (float) env('MERCADOPAGO_BOLETO_FEE_FIXED', 0),
            'card_percent' => (float) env('MERCADOPAGO_CARD_FEE_PERCENT', 0),
            'card_fixed' => (float) env('MERCADOPAGO_CARD_FEE_FIXED', 0),
            'card_installments' => array_filter(array_map(
                static fn ($value) => is_numeric($value) ? (float) $value : null,
                explode(',', (string) env('MERCADOPAGO_CARD_INSTALLMENT_RATES', ''))
            ), static fn ($value) => $value !== null),
        ],
    ],

    'whatsapp' => [
        'enabled' => (bool) env('WHATSAPP_CLOUD_ENABLED', false),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v26.0'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'waba_id' => env('WHATSAPP_WABA_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'pt_BR'),
        'invitation_template' => env('WHATSAPP_INVITATION_TEMPLATE', 'petertecnet_user_invitation'),
        'authentication_template' => env('WHATSAPP_AUTH_TEMPLATE', 'petertecnet_authentication_code'),
        'activation_template' => env('WHATSAPP_ACTIVATION_TEMPLATE', 'petertecnet_account_activated'),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'app_secret' => env('META_APP_SECRET'),
        'timeout' => (int) env('WHATSAPP_API_TIMEOUT', 15),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_API_BASE_URL', 'https://api.openai.com/v1'),
        'text_model' => env('OPENAI_TEXT_MODEL', 'gpt-5.6-luna'),
        'timeout' => (int) env('OPENAI_API_TIMEOUT', 45),
    ],

    'agent_chat' => [
        'repository' => env('AGENT_CHAT_GITHUB_REPOSITORY', 'petertecnetdev/petertecnet.com.br'),
        'branch' => env('AGENT_CHAT_GITHUB_BRANCH', 'main'),
        'path' => env('AGENT_CHAT_GITHUB_PATH', '.agents/AGENT_CHAT.md'),
        'token' => env('AGENT_CHAT_GITHUB_TOKEN'),
        'timeout' => (int) env('AGENT_CHAT_GITHUB_TIMEOUT', 12),
    ],

    'webpush' => [
        'subject' => env('WEB_PUSH_SUBJECT', 'mailto:petertecnet@gmail.com'),
        'public_key' => env('WEB_PUSH_PUBLIC_KEY'),
        'private_key' => env('WEB_PUSH_PRIVATE_KEY'),
    ],

    'artist_invitation_email' => [
        'webhook_secret' => env('ARTIST_INVITATION_EMAIL_WEBHOOK_SECRET'),
    ],

    'geo_ip' => ['endpoint' => env('GEO_IP_ENDPOINT', 'https://ipwho.is/{ip}')],
];
