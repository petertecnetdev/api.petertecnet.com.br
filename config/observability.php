<?php

return [
    'request_logging' => filter_var(env('OBSERVABILITY_REQUEST_LOGGING', true), FILTER_VALIDATE_BOOL),
    'slow_query_ms' => (float) env('OBSERVABILITY_SLOW_QUERY_MS', 250),
];
