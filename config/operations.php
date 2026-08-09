<?php

return [
    'schema_version' => 2,

    'features' => [
        'ui' => (bool) env('OPERATIONS_UI_ENABLED', false),
        'coordination' => (bool) env('OPERATIONS_COORDINATION_ENABLED', false),
        'discord' => (bool) env('OPERATIONS_DISCORD_ENABLED', false),
        'quick_actions' => (bool) env('OPERATIONS_QUICK_ACTIONS_ENABLED', false),
        'materialized_sources' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('OPERATIONS_MATERIALIZED_SOURCES', '')),
        ))),
    ],

    'permissions' => [
        'coordinate' => 'coordinate-operations',
        'manage' => 'manage-operations',
        'milcom_summary' => 'view-milcom-operations',
    ],

    'teams' => [
        'internal_affairs' => ['label' => 'Internal affairs / Recruitment', 'icon' => 'users'],
        'finance' => ['label' => 'Finance / Economics', 'icon' => 'banknotes'],
        'defense_support' => ['label' => 'Defense support', 'icon' => 'lifebuoy'],
        'milcom' => ['label' => 'Milcom', 'icon' => 'command-line'],
        'audit' => ['label' => 'Audit / Compliance', 'icon' => 'shield-check'],
        'systems' => ['label' => 'Nexus systems', 'icon' => 'server-stack'],
    ],

    'sources' => [
        'applications' => [
            'team' => 'internal_affairs',
            'view_abilities' => ['view-applications'],
            'fresh_seconds' => 300,
            'stale_seconds' => 900,
            'sensitivity' => 'restricted',
        ],
        'member_transfers' => [
            'team' => 'internal_affairs',
            'view_abilities' => ['view-accounts'],
            'fresh_seconds' => 300,
            'stale_seconds' => 900,
            'sensitivity' => 'restricted',
        ],
        'withdrawals' => [
            'team' => 'finance',
            'view_abilities' => ['view-accounts'],
            'fresh_seconds' => 60,
            'stale_seconds' => 300,
            'sensitivity' => 'restricted',
        ],
        'city_grants' => [
            'team' => 'finance',
            'view_abilities' => ['view-city-grants'],
            'fresh_seconds' => 300,
            'stale_seconds' => 900,
            'sensitivity' => 'restricted',
        ],
        'grants' => [
            'team' => 'finance',
            'view_abilities' => ['view-grants'],
            'fresh_seconds' => 300,
            'stale_seconds' => 900,
            'sensitivity' => 'restricted',
        ],
        'loans' => [
            'team' => 'finance',
            'view_abilities' => ['view-loans'],
            'fresh_seconds' => 300,
            'stale_seconds' => 900,
            'sensitivity' => 'restricted',
        ],
        'war_aid' => [
            'team' => 'defense_support',
            'interested_teams' => ['finance'],
            'view_abilities' => ['view-war-aid'],
            'fresh_seconds' => 300,
            'stale_seconds' => 900,
            'sensitivity' => 'restricted',
        ],
        'rebuilding' => [
            'team' => 'defense_support',
            'interested_teams' => ['finance'],
            'view_abilities' => ['view-rebuilding'],
            'fresh_seconds' => 300,
            'stale_seconds' => 900,
            'sensitivity' => 'restricted',
        ],
        'blockade_relief' => [
            'team' => 'milcom',
            'view_abilities' => ['view-milcom-operations', 'manage-war-room'],
            'fresh_seconds' => 30,
            'stale_seconds' => 120,
            'sensitivity' => 'restricted',
        ],
        'audit_remediation' => [
            'team' => 'audit',
            'view_abilities' => ['view-audits'],
            'fresh_seconds' => 300,
            'stale_seconds' => 900,
            'sensitivity' => 'restricted',
            'actions' => ['audit.acknowledge', 'audit.snooze'],
        ],
        'milcom_exceptions' => [
            'team' => 'milcom',
            'view_abilities' => ['view-milcom-operations', 'manage-war-room'],
            'fresh_seconds' => 30,
            'stale_seconds' => 120,
            'sensitivity' => 'restricted',
            'actions' => ['milcom.retry_discord_room'],
        ],
        'system_health' => [
            'team' => 'systems',
            'view_abilities' => ['view-diagnostic-info'],
            'fresh_seconds' => 60,
            'stale_seconds' => 300,
            'sensitivity' => 'diagnostic',
        ],
        'delivery_failures' => [
            'team' => 'systems',
            'view_abilities' => ['view-diagnostic-info'],
            'fresh_seconds' => 60,
            'stale_seconds' => 300,
            'sensitivity' => 'diagnostic',
        ],
    ],

    'attention' => [
        'attention_after_seconds' => 24 * 60 * 60,
        'overdue_after_seconds' => 72 * 60 * 60,
        'due_soon_seconds' => 6 * 60 * 60,
    ],

    'pagination' => [
        'default' => 25,
        'maximum' => 100,
        'command_palette_limit' => 8,
        'discord_default' => 25,
    ],

    'batch' => ['maximum_items' => 25],
    'action_intents' => ['ttl_seconds' => 300, 'retention_hours' => 24],
    'retention' => [
        'coordination_days' => 365,
        'events_days' => 365,
        'metric_days' => 1095,
    ],
    'source_failure_retry_seconds' => 30,
];
