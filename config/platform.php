<?php

$approvalRequiredApps = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('APPROVAL_REQUIRED_APPS', ''))
)));

return [
    'approval_required_apps' => $approvalRequiredApps,
    'default_page_size' => (int) env('API_DEFAULT_PAGE_SIZE', 20),
    'max_page_size' => (int) env('API_MAX_PAGE_SIZE', 100),

    // Product identity belongs to context/configuration. Domain controllers
    // expose reusable capabilities and must never be named after applications.
    'capabilities' => [
        'rasoio' => ['catalog', 'relationships', 'scheduling', 'ordering', 'payments', 'metrics', 'notifications'],
        'nexus' => ['catalog', 'discovery', 'relationships', 'metrics'],
        'plat' => ['catalog', 'ordering', 'payments', 'inventory', 'delivery', 'pickup', 'metrics', 'notifications'],
        'cutinapp' => ['catalog', 'events', 'ordering', 'payments', 'payouts', 'checkin', 'community', 'social', 'notifications', 'moderation', 'contracts', 'discovery'],
        'laora' => ['profiles', 'discovery', 'matching', 'messaging', 'privacy', 'moderation', 'notifications'],
        'inkap' => ['catalog', 'relationships', 'notifications'],
        'payflow' => ['crm', 'ordering', 'payments', 'notifications', 'metrics'],
    ],
];
