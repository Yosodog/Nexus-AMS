<?php

namespace App\Models;

use App\Domain\Federation\Enums\FederationResourceType;
use App\Domain\Federation\Enums\ReceivedResourceState;
use Database\Factories\FederationReceivedResourceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FederationReceivedResource extends Model
{
    /** @use HasFactory<FederationReceivedResourceFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'federation_link_id',
        'source_installation_id',
        'source_publication_id',
        'coalition_id',
        'resource_type',
        'state',
        'current_version',
        'current_revision',
        'expires_at',
        'revoked_at',
        'payload_purged_at',
    ];

    protected $attributes = [
        'resource_type' => FederationResourceType::WarPlanSnapshot->value,
        'state' => ReceivedResourceState::PendingReview->value,
        'current_version' => 0,
        'current_revision' => 0,
    ];

    protected function casts(): array
    {
        return [
            'resource_type' => FederationResourceType::class,
            'state' => ReceivedResourceState::class,
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'payload_purged_at' => 'datetime',
        ];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(FederationLink::class, 'federation_link_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FederationReceivedVersion::class);
    }
}
