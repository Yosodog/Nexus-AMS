<?php

$legacyV1Enabled = (bool) env('MILCOM_V1_ENABLED', false);

return [
    'v1_enabled' => $legacyV1Enabled,
    'v2_requested' => ! $legacyV1Enabled,
    'v2_enabled' => ! $legacyV1Enabled,

    'doctrine' => [
        'version' => 'fixed-v1',
        'weights' => [
            'air_matchup' => 0.40,
            'ground_matchup' => 0.20,
            'naval_matchup' => 0.10,
            'readiness' => 0.15,
            'tactical_fit' => 0.10,
            'activity' => 0.05,
        ],
        'candidate_limit_per_objective' => 40,
        'counter_combination_pool' => 20,
        'counter_alternative_count' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Politics & War declaration contract
    |--------------------------------------------------------------------------
    |
    | These values are deliberately centralized and versioned. Enablement is
    | blocked by the contract tests so a game-rule change cannot silently
    | produce invalid recommendations.
    |
    */
    'game_rules' => [
        'contract_version' => '2026-08',
        'contract_verified' => env('MILCOM_RULES_CONTRACT_VERIFIED', false),
        'base_offensive_slots' => 5,
        'declaration_score_minimum_multiplier' => 0.75,
        'declaration_score_maximum_multiplier' => 2.50,
        'offensive_slot_projects' => [
            'pirate_economy' => 1,
            'advanced_pirate_economy' => 1,
        ],
        'counter_snapshot_max_age_minutes' => 15,
        'plan_snapshot_max_age_minutes' => 60,
        'beige_blocks_declaration' => true,
    ],

    'discord' => [
        'forum_id' => null,
        'defense_role_id' => null,
        'forum_tag_ids' => [],
        'default_war_type' => 'ORDINARY',
        'default_war_reason' => 'Alliance operations',
    ],

    'pagination' => [
        'objectives' => 50,
        'incidents' => 50,
        'events' => 100,
    ],

    'live' => [
        'first_hit_grace_minutes' => 15,
    ],
];
