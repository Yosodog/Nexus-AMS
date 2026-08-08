<?php

use App\Enums\NexusRuntime;

return [
    'runtime' => env('NEXUS_RUNTIME', NexusRuntime::Standalone->value),
    'managed' => env('NEXUS_MANAGED', false),
    'tenant_id' => env('NEXUS_TENANT_ID'),
    'release_id' => env('NEXUS_RELEASE_ID', ''),
    'runtime_contract' => (int) env('NEXUS_RUNTIME_CONTRACT', 1),
    'world_view_contract' => (int) env('NEXUS_WORLD_VIEW_CONTRACT', 0),
    'build' => [
        'application_version' => env('NEXUS_APPLICATION_VERSION', ''),
        'image_digest' => env('NEXUS_IMAGE_DIGEST', ''),
        'commit' => env('NEXUS_COMMIT_SHA', ''),
    ],
    'health' => [
        'queue' => env('NEXUS_HEALTH_QUEUE', 'default'),
        'queue_max_age_seconds' => (int) env('NEXUS_QUEUE_HEARTBEAT_MAX_AGE', 180),
        'scheduler_max_age_seconds' => (int) env('NEXUS_SCHEDULER_HEARTBEAT_MAX_AGE', 180),
    ],
];
