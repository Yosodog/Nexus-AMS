<?php

return [
    'model_version' => 2,
    'queue' => env('RAID_QUEUE', 'default'),
    'intelligence_cache_store' => env('RAID_INTELLIGENCE_CACHE_STORE'),
    'fresh_seconds' => 300,
    'history_days' => 30,
    'history_pages' => 3,
    'history_page_size' => 50,
    'batch_size' => 25,
    'candidate_limit' => 100,
    'simulation_iterations' => 100,
    'finder_simulation_iterations' => 32,
    'recheck_per_minute' => 6,
    'reconciliation_page_size' => 100,
    'reconciliation_refresh_seconds' => 600,
    'late_arrival_grace_seconds' => 3600,
];
