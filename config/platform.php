<?php

$approvalRequiredApps = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('APPROVAL_REQUIRED_APPS', ''))
)));

return [
    'approval_required_apps' => $approvalRequiredApps,
    'default_page_size' => (int) env('API_DEFAULT_PAGE_SIZE', 20),
    'max_page_size' => (int) env('API_MAX_PAGE_SIZE', 100),

    // Central first-party role -> scope defaults. Product code may define roles,
    // but authorization checks always consume scopes from Identity.
    'role_scopes' => [
        'member' => ['profile.read'],
        'customer' => ['profile.read', 'catalog.read', 'orders.read', 'orders.write'],
        'professional' => ['profile.read', 'catalog.read', 'bookings.read', 'bookings.write'],
        'manager' => ['profile.read', 'catalog.*', 'orders.*', 'bookings.*', 'events.*', 'people.*'],
        'owner' => ['profile.read', 'catalog.*', 'orders.*', 'payments.read', 'bookings.*', 'events.*', 'people.*', 'organization.*'],
        'admin' => ['*'],
    ],
];
