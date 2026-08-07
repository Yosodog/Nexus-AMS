<?php

return [
    'enabled' => (bool) env('SCHEDULER_LIFECYCLE_ENABLED', true),

    'freshness_contracts' => [
        'artisan:pw:health-check' => [
            'label' => 'Politics & War health check',
            'maximum_age_minutes' => 5,
        ],
        'artisan:sync:wars' => [
            'label' => 'War synchronization',
            'maximum_age_minutes' => 90,
        ],
        'artisan:taxes:collect' => [
            'label' => 'Tax collection',
            'maximum_age_minutes' => 90,
        ],
        'artisan:audits:run' => [
            'label' => 'Member audit evaluation',
            'maximum_age_minutes' => 120,
        ],
    ],

    'retention' => [
        'routine_success_days' => (int) env('SCHEDULER_LIFECYCLE_SUCCESS_DAYS', 14),
        'slow_success_days' => (int) env('SCHEDULER_LIFECYCLE_SLOW_SUCCESS_DAYS', 90),
        'slow_threshold_ms' => (int) env('SCHEDULER_LIFECYCLE_SLOW_THRESHOLD_MS', 60000),
        'failure_days' => (int) env('SCHEDULER_LIFECYCLE_FAILURE_DAYS', 90),
        'skipped_days' => (int) env('SCHEDULER_LIFECYCLE_SKIPPED_DAYS', 14),
        'overlap_days' => (int) env('SCHEDULER_LIFECYCLE_OVERLAP_DAYS', 30),
        'running_days' => (int) env('SCHEDULER_LIFECYCLE_RUNNING_DAYS', 90),
        'batch_size' => (int) env('SCHEDULER_LIFECYCLE_PRUNE_BATCH_SIZE', 1000),
    ],
];
