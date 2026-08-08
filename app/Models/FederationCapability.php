<?php

namespace App\Models;

use App\Domain\Federation\Enums\CapabilityDirection;
use App\Domain\Federation\Enums\CapabilityState;
use App\Domain\Federation\Enums\FederationResourceType;
use Database\Factories\FederationCapabilityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FederationCapability extends Model
{
    /** @use HasFactory<FederationCapabilityFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'issuer_installation_id',
        'peer_installation_id',
        'federation_coalition_id',
        'resource_type',
        'direction',
        'revision',
        'state',
        'is_local',
        'statement_hash',
        'canonical_statement',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = ['canonical_statement'];

    protected $attributes = [
        'state' => CapabilityState::Active->value,
        'is_local' => false,
    ];

    protected function casts(): array
    {
        return [
            'resource_type' => FederationResourceType::class,
            'direction' => CapabilityDirection::class,
            'state' => CapabilityState::class,
            'is_local' => 'boolean',
            'canonical_statement' => 'encrypted',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function coalition(): BelongsTo
    {
        return $this->belongsTo(FederationCoalition::class, 'federation_coalition_id');
    }
}
