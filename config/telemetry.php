<?php

return [
    'frontend_schema' => env('PETER_TELEMETRY_SCHEMA', '3'),
    'frontend_version' => env('PETER_TELEMETRY_VERSION', '3.3.0'),
    'stale_minutes' => (int) env('PETER_TELEMETRY_STALE_MINUTES', 60),
    'down_minutes' => (int) env('PETER_TELEMETRY_DOWN_MINUTES', 1440),
];
