<?php

return [
    'image_provider' => env('CREATIVE_IMAGE_PROVIDER', 'cloudflare'),

    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_AI_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_AI_API_TOKEN'),
        'model' => env('CLOUDFLARE_AI_IMAGE_MODEL', '@cf/black-forest-labs/flux-1-schnell'),
        'quality_model' => env('CLOUDFLARE_AI_IMAGE_QUALITY_MODEL'),
        'event_preview_model' => env('CLOUDFLARE_AI_EVENT_PREVIEW_MODEL', '@cf/black-forest-labs/flux-2-klein-4b'),
        'event_quality_model' => env('CLOUDFLARE_AI_EVENT_IMAGE_MODEL', '@cf/black-forest-labs/flux-2-dev'),
        'timeout' => (int) env('CLOUDFLARE_AI_TIMEOUT', 45),
        'steps' => min(8, max(1, (int) env('CLOUDFLARE_AI_IMAGE_STEPS', 4))),
        'quality_steps' => min(40, max(1, (int) env('CLOUDFLARE_AI_IMAGE_QUALITY_STEPS', 12))),
        'event_preview_steps' => 4,
        'event_quality_steps' => min(40, max(4, (int) env('CLOUDFLARE_AI_EVENT_IMAGE_STEPS', 16))),
        'guidance' => min(10, max(0, (float) env('CLOUDFLARE_AI_IMAGE_GUIDANCE', 4.5))),
        'daily_request_budget' => max(1, (int) env('CLOUDFLARE_AI_DAILY_REQUEST_BUDGET', 1000)),
        'per_user_daily_limit' => max(1, (int) env('CLOUDFLARE_AI_USER_DAILY_LIMIT', 100)),
    ],

    'event_flyer' => [
        'candidate_count' => min(4, max(1, (int) env('CREATIVE_EVENT_CANDIDATE_COUNT', 3))),
        'return_candidates' => (bool) env('CREATIVE_EVENT_RETURN_CANDIDATES', false),
        'preview_long_edge' => min(1280, max(512, (int) env('CREATIVE_EVENT_PREVIEW_LONG_EDGE', 960))),
        'max_reference_images' => min(4, max(0, (int) env('CREATIVE_EVENT_MAX_REFERENCE_IMAGES', 4))),
    ],

    'quality' => [
        'minimum_score' => min(100, max(0, (int) env('CREATIVE_MINIMUM_QUALITY_SCORE', 70))),
    ],
];
