<?php

return [
    'cache_key' => 'pending_requests.counts',
    'projection_cache_key' => 'pending_requests.work_queue.v1',
    'cache_ttl_seconds' => env('PENDING_REQUESTS_TTL', 900), // 15 minutes by default
    'failure_cache_ttl_seconds' => 60,

    'permissions' => [
        'applications' => 'manage-applications',
        'withdrawals' => 'manage-accounts',
        'city_grants' => 'manage-city-grants',
        'grants' => 'manage-grants',
        'loans' => 'manage-loans',
        'war_aid' => 'manage-war-aid',
        'rebuilding' => 'manage-rebuilding',
        'blockade_relief' => 'manage-war-room',
        'audit_remediation' => 'manage-audits',
    ],
];
