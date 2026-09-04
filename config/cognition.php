<?php

return [
    'enabled' => env('COGNITION_ENABLED', false),
    'auto_learn' => env('COGNITION_AUTO_LEARN', true),
    'default_agent_slug' => env('COGNITION_DEFAULT_AGENT', 'ecosystem-core'),
    'default_agent_name' => env('COGNITION_DEFAULT_AGENT_NAME', 'Peter Cognitive Core'),
    'memory_threshold' => (float) env('COGNITION_MEMORY_THRESHOLD', 0.65),
    'allow_self_generated_goals' => env('COGNITION_ALLOW_SELF_GENERATED_GOALS', false),
    'queue' => env('COGNITION_QUEUE', 'default'),

    'interaction_content_allowlist' => [
        'status', 'status_code', 'http_status', 'duration_ms', 'error_code',
        'source_channel', 'page', 'action', 'result', 'reason',
    ],

    'sensitive_key_fragments' => [
        'password', 'passwd', 'secret', 'token', 'authorization', 'cookie',
        'cpf', 'cnpj', 'email', 'phone', 'address', 'ip', 'user_agent',
        'credit_card', 'card_number', 'cvv', 'pix', 'document',
    ],
];
