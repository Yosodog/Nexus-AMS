<?php

namespace App\Models;

use App\Domain\Federation\Enums\FederationKeyStatus;
use App\Domain\Federation\Enums\FederationLinkStatus;
use Database\Factories\FederationLinkFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FederationLink extends Model
{
    /** @use HasFactory<FederationLinkFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'remote_installation_id',
        'remote_display_name',
        'approved_origin',
        'status',
        'remote_ownership_epoch',
        'negotiated_protocol_version',
        'negotiated_resource_versions',
        'suspension_reason_code',
        'active_at',
        'suspended_at',
        'revoked_at',
        'expired_at',
        'last_contact_at',
        'last_reconciled_at',
    ];

    protected $attributes = [
        'status' => FederationLinkStatus::PendingRemote->value,
        'remote_ownership_epoch' => 1,
    ];

    protected function casts(): array
    {
        return [
            'status' => FederationLinkStatus::class,
            'negotiated_resource_versions' => 'array',
            'active_at' => 'datetime',
            'suspended_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expired_at' => 'datetime',
            'last_contact_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
        ];
    }

    public function peerKeys(): HasMany
    {
        return $this->hasMany(FederationPeerKey::class);
    }

    public function activePeerKey(): HasOne
    {
        return $this->hasOne(FederationPeerKey::class)
            ->where('status', FederationKeyStatus::Active->value)
            ->latestOfMany('generation');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(FederationLinkInvitation::class);
    }
}
