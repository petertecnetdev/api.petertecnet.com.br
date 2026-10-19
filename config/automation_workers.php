<?php

return [
    // Fail closed: registering jobs does not enable background work.
    'enabled' => (bool) env('AUTOMATION_WORKERS_ENABLED', false),
    'queue' => env('AUTOMATION_WORKERS_QUEUE', 'curation'),
    'batch_limit' => (int) env('AUTOMATION_WORKERS_BATCH_LIMIT', 200),
    'workers' => [
        'discovery_quality' => [
            'handler' => \App\Domain\Automation\Workers\DiscoveryCandidateQualityWorker::class,
            'interval_minutes' => 120,
        ],
        'discovery_health' => [
            'handler' => \App\Domain\Automation\Workers\DiscoverySourceHealthWorker::class,
            'interval_minutes' => 30,
        ],
    ],
];
