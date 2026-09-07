<?php

return [
    'image_provider' => env('CREATIVE_IMAGE_PROVIDER', 'cloudflare'),
    'text_provider' => env('CREATIVE_TEXT_PROVIDER', 'cloudflare'),

    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_AI_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_AI_API_TOKEN'),
        'model' => env('CLOUDFLARE_AI_IMAGE_MODEL', '@cf/black-forest-labs/flux-1-schnell'),
        'text_model' => env('CLOUDFLARE_AI_TEXT_MODEL', '@cf/meta/llama-3.3-70b-instruct-fp8-fast'),
        'timeout' => (int) env('CLOUDFLARE_AI_TIMEOUT', 45),
        'text_timeout' => (int) env('CLOUDFLARE_AI_TEXT_TIMEOUT', 30),
        'steps' => min(8, max(1, (int) env('CLOUDFLARE_AI_IMAGE_STEPS', 4))),
        'text_max_tokens' => min(1200, max(200, (int) env('CLOUDFLARE_AI_TEXT_MAX_TOKENS', 700))),
        'text_temperature' => min(1.0, max(0.0, (float) env('CLOUDFLARE_AI_TEXT_TEMPERATURE', 0.65))),
        'daily_request_budget' => max(1, (int) env('CLOUDFLARE_AI_DAILY_REQUEST_BUDGET', 200)),
        'per_user_daily_limit' => max(1, (int) env('CLOUDFLARE_AI_USER_DAILY_LIMIT', 12)),
        'text_daily_request_budget' => max(1, (int) env('CLOUDFLARE_AI_TEXT_DAILY_REQUEST_BUDGET', 2000)),
        'text_per_user_daily_limit' => max(1, (int) env('CLOUDFLARE_AI_TEXT_USER_DAILY_LIMIT', 50)),
    ],
];
