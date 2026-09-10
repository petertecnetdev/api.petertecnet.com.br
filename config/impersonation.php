<?php

return [
    'ttl_minutes' => (int) env('IMPERSONATION_TTL_MINUTES', 30),
    'handoff_ttl_seconds' => (int) env('IMPERSONATION_HANDOFF_TTL_SECONDS', 60),
    'super_admin_emails' => array_values(array_filter(array_map(
        static fn ($email) => strtolower(trim($email)),
        explode(',', env('IMPERSONATION_SUPER_ADMIN_EMAILS', 'petertecnet@gmail.com'))
    ))),
    'trusted_root_domain' => env('IMPERSONATION_TRUSTED_ROOT_DOMAIN', 'petertecnet.com.br'),
];
