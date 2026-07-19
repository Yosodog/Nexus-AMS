<?php

return [
    'redis' => [
        'connection' => env('SUBS_REDIS_CONNECTION', 'subscriptions'),
        'stream' => env('SUBS_REDIS_STREAM', 'nexus:subscriptions:v1'),
        'group' => env('SUBS_REDIS_GROUP', 'nexus-ams'),
        'consumer' => env('SUBS_REDIS_CONSUMER'),
        'block_ms' => (int) env('SUBS_REDIS_BLOCK_MS', 5000),
        'read_count' => (int) env('SUBS_REDIS_READ_COUNT', 10),
        'claim_idle_ms' => (int) env('SUBS_REDIS_CLAIM_IDLE_MS', 60000),
        'max_deliveries' => (int) env('SUBS_REDIS_MAX_DELIVERIES', 5),
        'retry_delay_ms' => (int) env('SUBS_REDIS_RETRY_DELAY_MS', 2000),
        'dead_letter_file' => env(
            'SUBS_REDIS_DEAD_LETTER_FILE',
            storage_path('logs/subscription-stream-dead-letters.jsonl')
        ),
    ],
];
