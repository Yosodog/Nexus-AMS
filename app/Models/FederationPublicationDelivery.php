<?php

namespace App\Models;

use App\Domain\Federation\Enums\DeliveryState;
use Database\Factories\FederationPublicationDeliveryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FederationPublicationDelivery extends Model
{
    /** @use HasFactory<FederationPublicationDeliveryFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'federation_publication_version_id',
        'federation_link_id',
        'recipient_installation_id',
        'state',
        'canonical_payload',
        'payload_hash',
        'payload_bytes',
        'outbox_message_id',
        'safe_error_code',
        'transport_accepted_at',
        'validated_at',
        'acknowledged_at',
        'access_revocation_revision',
        'access_revoked_at',
    ];

    protected $hidden = ['canonical_payload'];

    protected $attributes = [
        'state' => DeliveryState::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'state' => DeliveryState::class,
            'canonical_payload' => 'encrypted',
            'transport_accepted_at' => 'datetime',
            'validated_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'access_revoked_at' => 'datetime',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(FederationPublicationVersion::class, 'federation_publication_version_id');
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(FederationLink::class, 'federation_link_id');
    }
}
