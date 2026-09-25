<?php

return [
    'enabled' => filter_var(env('STRIPE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'mode' => env('STRIPE_MODE', 'test'),
    'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
    'secret_key' => env('STRIPE_SECRET_KEY'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    'connect_webhook_secret' => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
];
