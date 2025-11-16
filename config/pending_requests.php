<?php

return [
    'cache_key' => 'pending_requests.counts',
    'cache_ttl_seconds' => env('PENDING_REQUESTS_TTL', 900), // 15 minutes by default

    'permissions' => [
        'withdrawals' => 'manage-accounts',
        'city_grants' => 'manage-city-grants',
        'grants' => 'manage-grants',
        'loans' => 'manage-loans',
        'war_aid' => 'manage-war-aid',
    ],
];
