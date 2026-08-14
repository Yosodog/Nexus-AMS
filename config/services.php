<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'nexus_api_token' => env('NEXUS_API_TOKEN'),
    'discord_bot_key' => env('DISCORD_BOT_KEY'),
    'discord' => [
        'guild_id' => env('DISCORD_GUILD_ID'),
        'application_id' => env('DISCORD_APPLICATION_ID', env('DISCORD_CLIENT_ID')),
        'connection_mode' => env('DISCORD_CONNECTION_MODE', 'dedicated'),
        'connection_id' => env('DISCORD_CONNECTION_ID'),
        'connection_generation' => (int) env('DISCORD_CONNECTION_GENERATION', 1),
        'relay_protocol_version' => (int) env('DISCORD_RELAY_PROTOCOL_VERSION', 2),
        'relay_public_key' => env('DISCORD_RELAY_PUBLIC_KEY'),
        'relay_current_key_id' => env('DISCORD_RELAY_CURRENT_KEY_ID', 'relay-current'),
        'relay_current_public_key' => env('DISCORD_RELAY_CURRENT_PUBLIC_KEY'),
        'relay_next_key_id' => env('DISCORD_RELAY_NEXT_KEY_ID'),
        'relay_next_public_key' => env('DISCORD_RELAY_NEXT_PUBLIC_KEY'),
        'relay_next_activates_at' => env('DISCORD_RELAY_NEXT_ACTIVATES_AT'),
        'nexus_current_key_id' => env('DISCORD_NEXUS_CURRENT_KEY_ID'),
        'nexus_current_public_key' => env('DISCORD_NEXUS_CURRENT_PUBLIC_KEY'),
        'nexus_next_key_id' => env('DISCORD_NEXUS_NEXT_KEY_ID'),
        'nexus_next_public_key' => env('DISCORD_NEXUS_NEXT_PUBLIC_KEY'),
        'nexus_next_activates_at' => env('DISCORD_NEXUS_NEXT_ACTIVATES_AT'),
        'capability_version' => (int) env('DISCORD_CAPABILITY_VERSION', 1),
        'capabilities' => [
            'capabilities' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('DISCORD_CAPABILITIES', '')),
            ))),
            'supported_queue_actions' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('DISCORD_SUPPORTED_QUEUE_ACTIONS', '')),
            ))),
        ],
        'v1_reader_enabled' => filter_var(env('DISCORD_RELAY_V1_READER_ENABLED', false), FILTER_VALIDATE_BOOL),
        'interaction_max_age_seconds' => (int) env('DISCORD_INTERACTION_MAX_AGE_SECONDS', 300),
        'finance_action_intent_ttl_seconds' => (int) env('DISCORD_FINANCE_ACTION_INTENT_TTL_SECONDS', 120),
        'workflow_action_intent_ttl_seconds' => (int) env('DISCORD_WORKFLOW_ACTION_INTENT_TTL_SECONDS', 900),
    ],

    'pw' => [
        'alliance_id' => env('PW_ALLIANCE_ID', 0),
        'api_key' => env('PW_API_KEY'),
        'mutation_key' => env('PW_API_MUTATION_KEY'),
        'endpoint' => env('PW_API_ENDPOINT', 'https://api.politicsandwar.com/graphql'),
    ],

];
