<?php

namespace App\Models;

use App\Domain\Federation\Enums\CoalitionRole;
use App\Domain\Federation\Enums\MembershipStatus;
use Database\Factories\FederationCoalitionMembershipFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FederationCoalitionMembership extends Model
{
    /** @use HasFactory<FederationCoalitionMembershipFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'federation_coalition_id',
        'installation_id',
        'federation_link_id',
        'role',
        'status',
        'roster_revision',
        'joined_at',
        'expires_at',
        'removed_at',
    ];

    protected $attributes = [
        'status' => MembershipStatus::Pending->value,
    ];

    protected function casts(): array
    {
        return [
            'role' => CoalitionRole::class,
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
            'expires_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function coalition(): BelongsTo
    {
        return $this->belongsTo(FederationCoalition::class, 'federation_coalition_id');
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(FederationLink::class, 'federation_link_id');
    }
}
