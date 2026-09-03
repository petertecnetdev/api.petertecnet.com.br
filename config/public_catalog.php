<?php

return [
    'cache_ttl_seconds' => (int) env('PUBLIC_CATALOG_CACHE_TTL_SECONDS', 60),

    'performance' => [
        'max_response_ms' => (int) env('PUBLIC_CATALOG_MAX_RESPONSE_MS', 750),
        'max_payload_bytes' => (int) env('PUBLIC_CATALOG_MAX_PAYLOAD_BYTES', 524288),
        'max_queries' => (int) env('PUBLIC_CATALOG_MAX_QUERIES', 18),
    ],

    'monitoring' => [
        'max_response_ms' => (int) env('PUBLIC_CATALOG_MONITOR_MAX_RESPONSE_MS', 2500),
        'max_payload_bytes' => (int) env('PUBLIC_CATALOG_MONITOR_MAX_PAYLOAD_BYTES', 1048576),
    ],
];
