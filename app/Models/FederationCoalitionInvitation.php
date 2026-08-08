<?php

namespace App\Models;

use App\Domain\Federation\Enums\CoalitionRole;
use App\Domain\Federation\Enums\FederationWorkflowStatus;
use Database\Factories\FederationCoalitionInvitationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FederationCoalitionInvitation extends Model
{
    /** @use HasFactory<FederationCoalitionInvitationFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'federation_coalition_id',
        'federation_link_id',
        'installation_id',
        'role',
        'direction',
        'token_hash',
        'status',
        'pending_key',
        'source_message_id',
        'created_by',
        'reviewed_by',
        'expires_at',
        'reviewed_at',
    ];

    protected $hidden = ['token_hash'];

    protected $attributes = [
        'status' => FederationWorkflowStatus::Pending->value,
        'pending_key' => 1,
    ];

    protected function casts(): array
    {
        return [
            'role' => CoalitionRole::class,
            'status' => FederationWorkflowStatus::class,
            'expires_at' => 'datetime',
            'reviewed_at' => 'datetime',
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
