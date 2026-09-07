<?php

return [
    'image_provider' => env('CREATIVE_IMAGE_PROVIDER', 'cloudflare'),

    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_AI_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_AI_API_TOKEN'),
        'model' => env('CLOUDFLARE_AI_IMAGE_MODEL', '@cf/black-forest-labs/flux-1-schnell'),
        'timeout' => (int) env('CLOUDFLARE_AI_TIMEOUT', 45),
        'steps' => min(8, max(1, (int) env('CLOUDFLARE_AI_IMAGE_STEPS', 4))),
        'daily_request_budget' => max(1, (int) env('CLOUDFLARE_AI_DAILY_REQUEST_BUDGET', 1000)),
        'per_user_daily_limit' => max(1, (int) env('CLOUDFLARE_AI_USER_DAILY_LIMIT', 100)),
    ],
];
