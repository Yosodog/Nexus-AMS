<?php

return [
    'queue' => env('RAID_QUEUE', 'default'),

    'loot_event_retention_days' => (int) env('RAID_LOOT_EVENT_RETENTION_DAYS', 120),

    'profiles' => [
        'economy_max_age_hours' => 24,
    ],

    'estimator' => [
        'max_projection_days' => 60,
        'production_only_days' => 14,
        'retention_prior_weight' => 2,
        'retention_priors' => [
            'active' => 0.25, 'recent' => 0.5, 'idle' => 0.8, 'inactive' => 1.0, 'abandoned' => 1.0,
        ],
        'default_interval_factors' => [
            'loot:0_7d' => ['low' => 0.7, 'high' => 1.3],
            'loot:7_30d' => ['low' => 0.5, 'high' => 1.6],
            'loot:30d_plus' => ['low' => 0.3, 'high' => 2.0],
            'production_only:0_7d' => ['low' => 0.2, 'high' => 2.5],
            'production_only:7_30d' => ['low' => 0.2, 'high' => 2.5],
            'production_only:30d_plus' => ['low' => 0.2, 'high' => 2.5],
        ],
    ],

    'valuation' => [
        'starting_map' => 3,
        'turns_per_war' => 60,
        'turn_hours' => 2,
        'active_probability' => [
            'active' => 0.9, 'recent' => 0.6, 'idle' => 0.3, 'inactive' => 0.1, 'abandoned' => 0.03,
        ],
        'active_deposit_fraction' => 0.5,
        'default_counter_rate' => 0.10,
        'unaligned_counter_rate' => 0.02,
        'counter_loss_fraction' => 0.10,
    ],

    'counter' => [
        'window_days' => 30,
        'prior_countered' => 1,
        'prior_uncountered' => 9,
    ],

    'calibration' => [
        'window_days' => 60,
        'min_samples' => 30,
    ],

    'finder' => [
        'cache_seconds' => 60,
        'candidate_pool' => 300,
        'impression_link_hours' => 48,
        'impression_retention_days' => 14,
    ],

    'claims' => [
        'ttl_minutes' => 120,
    ],

    'reconciliation_page_size' => 100,
    'reconciliation_refresh_seconds' => 600,
    'late_arrival_grace_seconds' => 3600,
];
