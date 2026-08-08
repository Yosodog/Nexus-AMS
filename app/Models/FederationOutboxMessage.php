<?php

namespace App\Models;

use App\Domain\Federation\Enums\FederationMessageType;
use App\Domain\Federation\Enums\OutboxStatus;
use Database\Factories\FederationOutboxMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FederationOutboxMessage extends Model
{
    /** @use HasFactory<FederationOutboxMessageFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'message_id',
        'federation_link_id',
        'sender_installation_id',
        'recipient_installation_id',
        'sender_key_id',
        'recipient_key_id',
        'nonce',
        'message_type',
        'protocol_version',
        'resource_schema',
        'envelope_body',
        'status',
        'attempts',
        'safe_error_code',
        'correlation_id',
        'next_attempt_at',
        'expires_at',
        'transport_accepted_at',
        'validated_at',
        'failed_at',
    ];

    protected $hidden = ['envelope_body', 'nonce'];

    protected $attributes = [
        'status' => OutboxStatus::Pending->value,
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'message_type' => FederationMessageType::class,
            'status' => OutboxStatus::class,
            'next_attempt_at' => 'datetime',
            'expires_at' => 'datetime',
            'transport_accepted_at' => 'datetime',
            'validated_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(FederationLink::class, 'federation_link_id');
    }
}
