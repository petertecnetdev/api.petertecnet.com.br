<?php

$approvalRequiredApps = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('APPROVAL_REQUIRED_APPS', ''))
)));

return [
    'approval_required_apps' => $approvalRequiredApps,
    'default_page_size' => (int) env('API_DEFAULT_PAGE_SIZE', 20),
    'max_page_size' => (int) env('API_MAX_PAGE_SIZE', 100),
];
