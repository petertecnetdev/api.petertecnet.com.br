<?php

return [
    'enabled' => filter_var(env('WHATSAPP_CLOUD_ENABLED', false), FILTER_VALIDATE_BOOL),
    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v26.0'),
    'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    'waba_id' => env('WHATSAPP_WABA_ID'),
    'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'pt_BR'),
    'timeout' => (int) env('WHATSAPP_API_TIMEOUT', 15),
    'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
    'app_secret' => env('META_APP_SECRET'),
    'templates' => [
        'USER_INVITATION' => ['name' => env('WHATSAPP_INVITATION_TEMPLATE', 'petertecnet_user_invitation'), 'locale' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'pt_BR')],
        'AUTH_CODE' => ['name' => env('WHATSAPP_AUTH_TEMPLATE', 'petertecnet_authentication_code'), 'locale' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'pt_BR')],
        'ACCOUNT_ACTIVATED' => ['name' => env('WHATSAPP_ACTIVATION_TEMPLATE', 'petertecnet_account_activated'), 'locale' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'pt_BR')],
        'EVENT_REMINDER' => ['name' => env('WHATSAPP_EVENT_REMINDER_TEMPLATE'), 'locale' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'pt_BR')],
        'TICKET_PURCHASED' => ['name' => env('WHATSAPP_TICKET_PURCHASED_TEMPLATE'), 'locale' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'pt_BR')],
        'PAYMENT_CONFIRMED' => ['name' => env('WHATSAPP_PAYMENT_CONFIRMED_TEMPLATE'), 'locale' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'pt_BR')],
        'PAYMENT_FAILED' => ['name' => env('WHATSAPP_PAYMENT_FAILED_TEMPLATE'), 'locale' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'pt_BR')],
        'PRODUCER_ACTION_REQUIRED' => ['name' => env('WHATSAPP_PRODUCER_ACTION_REQUIRED_TEMPLATE'), 'locale' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'pt_BR')],
    ],
];
