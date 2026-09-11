<?php

return [
    'driver' => env('DISCOVERY_SEARCH_DRIVER', 'database'),
    'endpoint' => rtrim((string) env('DISCOVERY_SEARCH_ENDPOINT', ''), '/'),
    'key' => env('DISCOVERY_SEARCH_KEY'),
    'index_prefix' => env('DISCOVERY_SEARCH_INDEX_PREFIX', 'peter'),
    'timeout' => (int) env('DISCOVERY_SEARCH_TIMEOUT', 3),
];
