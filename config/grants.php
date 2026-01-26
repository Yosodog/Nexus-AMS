<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Grant Alerting Thresholds
    |--------------------------------------------------------------------------
    |
    | These thresholds control when grant approvals emit warning logs for
    | unusually large amounts. Set to 0 to disable a specific threshold.
    |
    */
    'alert_thresholds' => [
        'money' => 100000000,
        'resource' => 500000,
        'city_grant_amount' => 50000000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Grant Request Rate Limits
    |--------------------------------------------------------------------------
    |
    | Per-minute request limits applied to grant and city grant requests.
    | These limits are enforced per nation and per IP.
    |
    */
    'rate_limits' => [
        'nation_per_minute' => 3,
        'ip_per_minute' => 10,
    ],
];
