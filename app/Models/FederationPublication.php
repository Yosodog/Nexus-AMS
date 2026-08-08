<?php

namespace App\Models;

use App\Domain\Federation\Enums\FederationResourceType;
use App\Domain\Federation\Enums\PublicationStatus;
use Database\Factories\FederationPublicationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FederationPublication extends Model
{
    /** @use HasFactory<FederationPublicationFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'milcom_operation_id',
        'federation_coalition_id',
        'source_installation_id',
        'resource_type',
        'status',
        'current_version',
        'current_revision',
        'source_generation',
        'created_by',
        'expires_at',
        'published_at',
        'revoked_at',
    ];

    protected $attributes = [
        'resource_type' => FederationResourceType::WarPlanSnapshot->value,
        'status' => PublicationStatus::Draft->value,
        'current_version' => 0,
        'current_revision' => 0,
    ];

    protected function casts(): array
    {
        return [
            'resource_type' => FederationResourceType::class,
            'status' => PublicationStatus::class,
            'expires_at' => 'datetime',
            'published_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(MilcomOperation::class, 'milcom_operation_id');
    }

    public function coalition(): BelongsTo
    {
        return $this->belongsTo(FederationCoalition::class, 'federation_coalition_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FederationPublicationVersion::class);
    }
}
