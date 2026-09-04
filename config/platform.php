<?php

$approvalRequiredApps = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('APPROVAL_REQUIRED_APPS', ''))
)));

return [
    'approval_required_apps' => $approvalRequiredApps,
    'default_page_size' => (int) env('API_DEFAULT_PAGE_SIZE', 20),
    'max_page_size' => (int) env('API_MAX_PAGE_SIZE', 100),

    // Application names belong in configuration only. Controllers, models,
    // services and routes consume capabilities through ApplicationContext.
    'applications' => [
        'cutinapp' => [
            'capabilities' => ['organizations','events','event_tickets','event_community','social','commerce','payments','payouts','agreements','notifications','moderation','acquisition'],
            'commerce' => [
                'platform_fee_percent' => (float) env('CUTINAPP_PLATFORM_FEE_PERCENT', 8),
                'order_expiration_minutes' => max(30, min((int) env('CUTINAPP_ORDER_EXPIRATION_MINUTES', 30), 60)),
                'allow_platform_collection' => filter_var(env('CUTINAPP_ALLOW_PLATFORM_COLLECTION', false), FILTER_VALIDATE_BOOL),
            ],
            'events' => [
                'requires_producer_agreement' => true,
            ],
        ],
        'rasoio' => [
            'capabilities' => ['catalog','scheduling','workforce','notifications','payments'],
        ],
        'nexus' => [
            'capabilities' => ['catalog','commerce','payments','notifications'],
        ],
        'plat' => [
            'capabilities' => ['catalog','commerce','inventory','payments','delivery','pickup','notifications'],
        ],
        'laora' => [
            'capabilities' => ['connections','messaging','moderation','notifications'],
        ],
        'payflow' => [
            'capabilities' => ['crm','commerce','payments','notifications'],
        ],
        'kryvion' => [
            'capabilities' => ['market_data','portfolio','analytics','alerts','notifications'],
        ],
    ],
];
