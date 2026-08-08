<?php

namespace App\Models;

use App\Domain\Federation\Enums\CoalitionStatus;
use Database\Factories\FederationCoalitionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FederationCoalition extends Model
{
    /** @use HasFactory<FederationCoalitionFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'name',
        'coordinator_installation_id',
        'status',
        'roster_revision',
        'roster_hash',
        'canonical_manifest',
        'expires_at',
        'dissolved_at',
        'created_by',
    ];

    protected $hidden = ['canonical_manifest'];

    protected $attributes = [
        'status' => CoalitionStatus::Active->value,
        'roster_revision' => 1,
    ];

    protected function casts(): array
    {
        return [
            'status' => CoalitionStatus::class,
            'canonical_manifest' => 'encrypted',
            'expires_at' => 'datetime',
            'dissolved_at' => 'datetime',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(FederationCoalitionMembership::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(FederationCoalitionInvitation::class);
    }

    public function proposals(): HasMany
    {
        return $this->hasMany(FederationCoalitionProposal::class);
    }

    public function capabilities(): HasMany
    {
        return $this->hasMany(FederationCapability::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
