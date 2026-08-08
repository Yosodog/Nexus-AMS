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
    'control' => [
        'bootstrap_introspection_url' => env('NEXUS_BOOTSTRAP_INTROSPECTION_URL'),
        'bootstrap_token_max_ttl_seconds' => (int) env('NEXUS_BOOTSTRAP_TOKEN_MAX_TTL', 900),
        'callback_url' => env('NEXUS_CONTROL_CALLBACK_URL'),
        'callback_key_file' => env('NEXUS_CONTROL_CALLBACK_KEY_FILE'),
        'callback_queue' => env('NEXUS_CONTROL_CALLBACK_QUEUE', 'default'),
        'connect_timeout_seconds' => (int) env('NEXUS_CONTROL_CONNECT_TIMEOUT', 3),
        'request_timeout_seconds' => (int) env('NEXUS_CONTROL_REQUEST_TIMEOUT', 10),
        'response_max_age_seconds' => (int) env('NEXUS_CONTROL_RESPONSE_MAX_AGE', 120),
        'response_future_tolerance_seconds' => (int) env('NEXUS_CONTROL_RESPONSE_FUTURE_TOLERANCE', 30),
        'callback_lease_seconds' => (int) env('NEXUS_CONTROL_CALLBACK_LEASE', 90),
    ],
];
