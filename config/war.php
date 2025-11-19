<?php

return [
    /*
    |--------------------------------------------------------------------------
    | War Types
    |--------------------------------------------------------------------------
    |
    | Valid war declaration types supported by Politics & War. Keep order stable
    | so UI dropdowns remain predictable.
    |
    */
    'war_types' => [
        'ordinary' => 'Ordinary',
        'raid' => 'Raid',
        'attrition' => 'Attrition',
    ],

    /*
    |--------------------------------------------------------------------------
    | Slot Capacity Defaults
    |--------------------------------------------------------------------------
    |
    | Offensive and defensive slot caps determine whether a friendly nation can
    | participate in a counter or plan. Project modifiers are keyed by the PW
    | project slug and applied as additive adjustments (positive opens slots,
    | negative reserves them). These numbers are conservative baseline values;
    | tune them per alliance doctrine.
    |
    */
    'slot_caps' => [
        'default_offensive' => 3,
        'default_defensive' => 3,
        'project_modifiers' => [
            'space_program' => 1, // TODO make this accurate
            'advanced_urban_planning' => 1,
            'activity_center' => 1,
            'vital_defense_system' => -1,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Target Priority Score Weights
    |--------------------------------------------------------------------------
    |
    | Each factor contributes to the 0–100 Target Priority Score. Keep weights
    | normalized to roughly sum to 1 to avoid runaway scores. The decay profile
    | is exponential and uses the provided half-life window. Metas are persisted
    | so leadership can inspect tooltips and retune effectively.
    |
    */
    'target_priority' => [
        'weights' => [
            'alliance_position' => 0.18,
            'city_scale' => 0.15,
            'recent_activity' => 0.12,
            'military_output' => 0.18,
            'scarcity' => 0.12,
            'strategic_flags' => 0.1,
            'wars_won' => 0.05,
            'infrastructure_destroyed' => 0.1,
        ],
        'decay' => [
            'recent_activity_hours' => 72,
            'full_decay_days' => 14,
        ],
        'strategic_adjustments' => [
            'at_war_with_us' => 6,
            'declared_recently' => 5,
            'vacation_mode' => -20,
            'beige' => -12,
        ],
        'bounded_range' => [0, 100],
        'default_ttl' => 600,
        'debounce_seconds' => 90,
    ],

    /*
    |--------------------------------------------------------------------------
    | Nation Match Score Weights
    |--------------------------------------------------------------------------
    |
    | Match scores combine capacity checks, military readiness, historical
    | performance, and soft preferences (cohesion, color). The small TPS bias
    | keeps auto-assignment loosely aligned with numeric priority without
    | overriding human judgement.
    |
    */
    'nation_match' => [
        'weights' => [
            // Relative power deliberately dominates so bad matchups bottom out.
            'relative_power' => 0.3,
            'availability' => 0.15,
            'military_effectiveness' => 0.15,
            'city_advantage' => 0.1,
            'recent_activity' => 0.1,
            'assignment_load_penalty' => -0.1,
            'mmr_compliance' => 0.05,
            'cohesion_bonus' => 0.07,
            'color_penalty' => -0.04,
            'tps_bias' => 0.02,
        ],
        'cohesion' => [
            'preferred_delta' => 10,
        ],
        'penalties' => [
            'offensive_load' => 4,
            'defensive_load' => 6,
        ],
        'relative_power' => [
            // Auto mode rejects pairings below this parity so squads stay reasonable.
            'auto_floor' => 0.18,
            'manual_floor' => 0.05,
            'auto_ratio_floor' => 0.48,
            'manual_ratio_floor' => 0.38,
            'ratio_ceiling' => 0.95,
            'auto_curve_exponent' => 0.85,
            'manual_curve_exponent' => 0.75,
            'auto_min_cap' => 24,
            'manual_min_cap' => 40,
        ],
        'factor_explanations' => [
            'availability' => 'Binary check that you have open offensive slots; zero slots zeroes your score.',
            'military_effectiveness' => 'Weighted readiness across soldiers, armor, aircraft, ships, missiles, and nukes relative to city caps.',
            'city_advantage' => 'Smooth curve comparing friendly vs enemy cities; leans into parity while rewarding slight advantages.',
            'relative_power' => 'Composite parity floor using score, city count, and estimated military strength; low parity caps the whole match.',
            'recent_activity' => 'Linearly decays from 12h to 72h last login to favour active players.',
            'assignment_load_penalty' => 'Scales penalties as you accumulate assignments versus your allowed max.',
            'mmr_compliance' => 'Normalised MMR score to favour members with track record; defaults to 0.5 when unknown.',
            'cohesion_bonus' => 'Keeps squads together by rewarding similar readiness versus squad reference.',
            'color_penalty' => 'Discourages colour-locked wars that would cost colour bonuses.',
            'tps_bias' => 'Light nudge honouring target priority so planners see top targets first, scaled by parity.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Squad Defaults
    |--------------------------------------------------------------------------
    */
    'squads' => [
        'max_size' => 3,
        'cohesion_tolerance' => 10,
        'label_prefix' => 'Squad',
    ],

    /*
    |--------------------------------------------------------------------------
    | War Plan Defaults
    |--------------------------------------------------------------------------
    */
    'plan_defaults' => [
        'plan_type' => 'ordinary',
        'preferred_nations_per_target' => 3,
        'activity_window_hours' => 72,
        'suppress_counters_when_active' => true,
        'lock_ttl' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Counter Policy Defaults
    |--------------------------------------------------------------------------
    */
    'counters' => [
        'default_team_size' => 3,
        'lock_ttl' => 30,
        'debounce_seconds' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Templates
    |--------------------------------------------------------------------------
    |
    | Placeholders:
    | {friendlyName}, {enemyName}, {planName}, {counterName}, {aggressorName},
    | {discordRoom}. Messages render as Markdown in PW mail and embed text on
    | Discord.
    |
    */
    'notifications' => [
        'templates' => [
            'plan_assignments' => [
                'subject' => 'War Plan {planName} Assignments Ready',
                'body' => <<<'TPL'
Hello {friendlyName},

You have been assigned to engage {enemyName} under war plan **{planName}**. Review your squad and confirm readiness ASAP.

Cheers,
AMS War Room
TPL,
            ],
            'counter_finalized' => [
                'subject' => 'Counter Orders for {aggressorName}',
                'body' => <<<'TPL'
{friendlyName},

Immediate action required. Counter operations against {aggressorName} are final. Coordinate with your squad and hold until launch call.

Stay sharp.
TPL,
            ],
            'discord_room' => [
                'subject' => 'New War Room: {discordRoom}',
                'body' => 'Discord channel {discordRoom} scheduled for creation.',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Windows & Locks
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'active_enemy_alliances_ttl' => 300,
        'active_war_counts_ttl' => 60,
        'live_feed_ttl' => 90,
        'lock_timeout' => 10,
        'lock_release_after' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Live Feed Defaults
    |--------------------------------------------------------------------------
    */
    'live_feed' => [
        'default_window_minutes' => 60,
        'page_size' => 25,
        'max_window_hours' => 24,
    ],
];
