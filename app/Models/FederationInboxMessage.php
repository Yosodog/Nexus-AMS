<?php

namespace App\Models;

use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\InboxStatus;
use Database\Factories\FederationInboxMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FederationInboxMessage extends Model
{
    /** @use HasFactory<FederationInboxMessageFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'message_id',
        'sender_installation_id',
        'recipient_installation_id',
        'sender_key_id',
        'recipient_key_id',
        'nonce',
        'message_type',
        'protocol_version',
        'resource_schema',
        'payload_hash',
        'envelope_body',
        'decrypted_payload',
        'status',
        'processing_attempts',
        'safe_error_code',
        'correlation_id',
        'issued_at',
        'expires_at',
        'processed_at',
        'quarantined_at',
        'next_attempt_at',
    ];

    protected $hidden = ['envelope_body', 'decrypted_payload', 'nonce'];

    protected $attributes = [
        'status' => InboxStatus::Accepted->value,
    ];

    protected function casts(): array
    {
        return [
            'message_type' => FederationMessageType::class,
            'decrypted_payload' => 'encrypted',
            'status' => InboxStatus::class,
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'processed_at' => 'datetime',
            'quarantined_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }
}
