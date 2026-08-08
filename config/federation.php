<?php

return [
    'enabled' => (bool) env('FEDERATION_ENABLED', false),

    'features' => [
        'inbound' => (bool) env('FEDERATION_INBOUND_ENABLED', false),
        'linking' => (bool) env('FEDERATION_LINKING_ENABLED', false),
        'publishing' => (bool) env('FEDERATION_PUBLISHING_ENABLED', false),
    ],

    'protocol_version' => '1.0',
    'resource_schemas' => [
        'milcom.war-plan-snapshot' => ['1.0'],
    ],

    'network' => [
        'require_https' => true,
        'allow_private_peers' => (bool) env('FEDERATION_ALLOW_PRIVATE_PEERS', false),
        'allowed_ports' => [443],
        'connect_timeout_seconds' => 3,
        'request_timeout_seconds' => 10,
    ],

    'limits' => [
        'outer_request_bytes' => 1024 * 1024,
        'decrypted_payload_bytes' => 512 * 1024,
        'targets_per_publication' => 500,
        'recipient_instructions_characters' => 1000,
    ],

    'invitation_expiry_hours' => 24,
    'publication_default_expiry_days' => 7,
    'publication_max_expiry_days' => 30,
    'clock_skew_seconds' => 5 * 60,
    'reconciliation_interval_minutes' => 15,
    'processed_body_retention_days' => 30,
    'tombstone_retention_days' => 180,
    'retiring_key_grace_days' => 30,

    'rate_limits' => [
        'global_per_minute' => 120,
        'ip_per_minute' => 30,
        'sender_per_minute' => 60,
        'handshake_ip_per_minute' => 10,
    ],
];
