<?php

return [
    // A instalação de migrations e a inclusão de fontes não ativam a varredura.
    'enabled' => (bool) env('EVENT_DISCOVERY_ENABLED', false),
    'allowed_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('EVENT_DISCOVERY_ALLOWED_HOSTS', ''))
    ))),
    'max_sources_per_run' => 20,
    'max_events_per_feed' => 300,
    'max_feed_bytes' => 2 * 1024 * 1024,
];
